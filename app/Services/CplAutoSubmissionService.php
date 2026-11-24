<?php

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\CplBatch;
use App\Models\CplSetting;
use App\Models\LoanFollowup;
use App\Models\NotificationQueue;
use App\Models\User;
use App\Support\AsciiText;

/**
 * Builds and sends the DAILY and MONTHLY CPL files to Creditinfo by itself, from the answers staff gave on the Loan Questions page
 * (see CplAutoSelector for WHAT goes in a file; this class decides WHETHER and WHEN it may go, and records what happened).
 *
 * Nothing about the manual flow changes: staff can still prepare, approve and submit a batch by hand (CplBatchController / CplSftpService::submit()).
 * A batch made here is marked as automatic (legacy_summary.origin) and is only ever sent, and recorded, by this class.
 *
 * A file is SENT only when ALL of these hold; otherwise it is stored as a draft ("Ready for Review"/"Draft") so staff can see exactly what would be
 * sent, nothing is uploaded, and the staff are told by e-mail (once a day per reason, counts and a link only - never names or loan numbers):
 *   1. this is not a demo or sandbox copy of the system (DemoMode), and no SFTP login was ever refused without a person acknowledging it (hard stop);
 *   2. the switch cpl_auto_submit_enabled is '1' (default '0' = off; turning it on is a person's decision, with acknowledgements);
 *   3. a STANDING APPROVAL exists: turning the switch on is the maker-checker approval of the automatic path (cpl_batch_approval is never silently
 *      bypassed). It names who gave it and when, is cited on every batch it sends, and is revoked by turning the switch off or by changing the submission
 *      environment, the supplier reference, the recipient or the SFTP destination;
 *   4. the CPL submission environment is Production (so the file name is L702; a test environment never sends a live file);
 *   5. the live SFTP connection is ready (sftp_submission_enabled on, connection complete);
 *   6. the file has at least one record, and the finished bytes pass CplFileValidator (exact widths, ASCII only, counts, name);
 *   7. the very first live file of a kind is not more than 20% larger than the preview the enabler saw;
 *   8. no earlier automatic upload of the same kind is in an unknown state, and no upload of the kind failed within the last few hours.
 * A DAILY file carries every record that passed validation: the loans that failed validation are held back (and e-mailed as a count) and are simply
 * tried again the next day. A MONTHLY file is held back as a whole only while some instalment has never been answered; loans that fail validation are left
 * out and counted.
 *
 * What was reported is written to cpl_auto_reported (unique per loan + R/C/month), but ONLY after the SFTP server confirmed the upload. The intent is
 * written first ("IN PROGRESS") and cleared afterwards, so a crash, a timeout or a failed write after a successful upload leaves the batch UNCERTAIN:
 * the next runs refuse to send that kind again and alert, until a person says whether Creditinfo has the file (resolveUncertain()). Nothing is ever
 * resent on a guess. A refused SFTP login is a global hard stop (see haltOnLoginRefusal()).
 *
 * Daily files carry the run date; at most one daily file exists per date (the batch table's unique key). Monthly files: every completed month end from the
 * first tracked loan onwards, oldest first, each sent on or after the Nth working day of the next month (cpl_monthly_send_day, default 3) and at most once
 * per month end; the run STOPS at the first month that is blocked or unknown, so the bureau always receives the months in order. MySQL GET_LOCK (named after
 * the database) stops two runs from overlapping.
 */
class CplAutoSubmissionService
{
    public const ORIGIN = 'Automatic: built from the Loan Questions answers';
    /** legacy_summary.origin of a staff batch built from the approved online applications (bin/cpl_applications.php): the only foreign kind that may be taken over. */
    public const MANUAL_LOAN_QUESTIONS_ORIGIN = 'Built from the approved online applications';
    public const SOURCE_MODULE = 'CPL Auto';
    public const ALERT_PERMISSION = 'reports.cpl_export';
    /** The permission the person who gives (and keeps) the standing approval must hold. */
    public const APPROVE_PERMISSION = 'reports.cpl_approve';
    public const IN_PROGRESS_PREFIX = 'Automatic upload IN PROGRESS';
    public const UNCLEAR_PREFIX = 'Automatic upload UNCLEAR';
    public const FAILED_PREFIX = 'Automatic upload FAILED';
    public const LOCK_NAME = 'cpl_auto_submission';
    /** After a failed upload of a kind, no new attempt for this many hours (a wrong password must not be hammered into a locked account). */
    public const RETRY_AFTER_FAILURE_HOURS = 6;
    public const DEFAULT_SEND_DAY = 3;
    /** A scheduled job that has not reported for longer than this is considered not running. */
    public const HEARTBEAT_STALE_HOURS = 30;
    /** The first live file of a kind may be at most this much bigger than the preview the enabler saw. */
    public const PREVIEW_TOLERANCE = 1.2;
    /** An "IN PROGRESS" note older than this belongs to a run that is surely dead. */
    public const IN_PROGRESS_STALE_MINUTES = 10;
    /** At most this many month ends are handled in one run. */
    public const MAX_MONTHS_PER_RUN = 24;

    /** The sentence shown on the dashboard and next to the switch: the Loan Questions are not the whole loan book. */
    public const COMPLETENESS_WARNING = 'The Loan Questions only cover loan applications received since 2026-10-29. The monthly file therefore is NOT the full historic loan book: the active loans that are still managed in the old SolidFin system (about 941 at the last count) are not in it and are not reported by this automatic submission.';

    private CplSetting $settings;
    private CplBatch $batches;
    private CplSftpService $sftp;
    private CplAutoSelector $selector;
    private CplRecordBuilder $builder;
    /** @var callable|null */
    private $connect;
    /** @var callable():\DateTimeImmutable */
    private $clock;
    /** @var callable():array<int, array<string,mixed>> */
    private $entriesLoader;
    /** @var callable():array<int, array{id:int,name:string,email:string}> */
    private $recipients;
    /** @var callable(\DateTimeImmutable):bool */
    private $isBusinessDay;
    /** @var callable():bool true on a demo or sandbox copy of the system */
    private $instanceGuard;
    /** @var callable():array{daily:?string, monthly:?string} when each scheduled job last reported (Y-m-d H:i:s) */
    private $heartbeats;

    public function __construct(
        ?CplSetting $settings = null,
        ?CplBatch $batches = null,
        ?CplSftpService $sftp = null,
        ?callable $connect = null,
        ?callable $clock = null,
        ?callable $entriesLoader = null,
        ?callable $recipients = null,
        ?callable $isBusinessDay = null,
        ?CplAutoSelector $selector = null,
        ?callable $instanceGuard = null,
        ?callable $heartbeats = null
    ) {
        $this->settings = $settings ?? new CplSetting();
        $this->batches = $batches ?? new CplBatch();
        $this->sftp = $sftp ?? new CplSftpService($this->batches, $this->settings);
        $this->connect = $connect;
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now');
        $this->entriesLoader = $entriesLoader ?? [$this, 'loadEntries'];
        $this->recipients = $recipients ?? fn (): array => $this->defaultRecipients();
        $this->isBusinessDay = $isBusinessDay ?? static function (\DateTimeImmutable $d): bool {
            try {
                return (new BusinessCalendarService())->isBusinessDay($d);
            } catch (\Throwable $e) {
                return !BusinessCalendarService::isWeekendPure($d); // the holiday table is unreadable: weekends only
            }
        };
        $this->selector = $selector ?? new CplAutoSelector();
        $this->builder = new CplRecordBuilder();
        $this->instanceGuard = $instanceGuard ?? static fn (): bool => \App\Support\DemoMode::requiresTestEnvironment();
        $this->heartbeats = $heartbeats ?? fn (): array => $this->loadHeartbeats();
    }

    /** Is this cpl_batches row one this class made (and so is sent only by it)? */
    public static function isAutoBatch(array $batch): bool
    {
        $summary = json_decode((string) ($batch['legacy_summary'] ?? ''), true);
        return is_array($summary) && ($summary['origin'] ?? null) === self::ORIGIN;
    }

    /**
     * Is this a batch of the Loan Questions kind, i.e. one the automatic run could rebuild with exactly the same meaning? Only a batch built from the
     * approved online applications (bin/cpl_applications.php, legacy_summary.origin) or one the plain "Local" generator made (source 'Local', no foreign
     * origin). A full-book batch from the old loan system (source SolidFin, any other origin) is a different thing: it must never be overwritten.
     */
    public static function isLoanQuestionsKind(array $batch): bool
    {
        $summary = json_decode((string) ($batch['legacy_summary'] ?? ''), true);
        $origin = is_array($summary) ? ($summary['origin'] ?? null) : null;
        if ($origin === self::MANUAL_LOAN_QUESTIONS_ORIGIN) {
            return true;
        }
        return ($batch['source'] ?? '') === 'Local' && $origin === null;
    }

    /** Is the automatic upload of this batch in a state where nobody knows whether Creditinfo has the file? */
    public static function isUncertain(array $batch): bool
    {
        if (!self::isAutoBatch($batch) || ($batch['status'] ?? '') === 'Submitted' || ($batch['submission_outcome'] ?? '') !== 'Awaiting Load Report') {
            return false;
        }
        $notes = (string) ($batch['submission_notes'] ?? '');
        return str_starts_with($notes, self::IN_PROGRESS_PREFIX) || str_starts_with($notes, self::UNCLEAR_PREFIX);
    }

    public function isEnabled(): bool
    {
        return $this->settings->get('cpl_auto_submit_enabled', '0') === '1';
    }

    /** True on a demo or sandbox copy: it never sends a live file and the switch cannot be turned on. */
    public function isRestrictedInstance(): bool
    {
        return (bool) ($this->instanceGuard)();
    }

    /** The name of the MySQL lock: includes the database name, so two systems sharing one MySQL server never block each other. */
    public function lockName(): string
    {
        $db = (string) Database::connection()->query('SELECT DATABASE()')->fetchColumn();
        $name = self::LOCK_NAME . ':' . $db;
        return strlen($name) > 64 ? self::LOCK_NAME . ':' . substr(sha1($db), 0, 20) : $name;
    }

    // ------------------------------------------------------------------ runs

    /**
     * The daily file: every loan newly paid out (R) and every loan fully settled (C) that Creditinfo has not been told about yet.
     *
     * @param string|null $date Y-m-d to act as "today" (never later than the real today)
     * @return array<string,mixed> outcome (code), message, batch_id, records, file, sent, dry_run, ...
     */
    public function runDaily(bool $dryRun = false, ?string $date = null): array
    {
        return $this->guarded('daily', $dryRun, $date, fn (\DateTimeImmutable $now, string $today): array => $this->doDaily($now, $today, $dryRun));
    }

    /** The monthly file(s) that are due: every completed month end not yet sent, oldest first. */
    public function runMonthly(bool $dryRun = false, ?string $date = null): array
    {
        return $this->guarded('monthly', $dryRun, $date, fn (\DateTimeImmutable $now, string $today): array => $this->doMonthly($now, $today, $dryRun));
    }

    /**
     * @param callable(\DateTimeImmutable, string):array<string,mixed> $work
     * @return array<string,mixed>
     */
    private function guarded(string $kind, bool $dryRun, ?string $date, callable $work): array
    {
        $now = ($this->clock)();
        $today = $date ?? $now->format('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $today) || $today > $now->format('Y-m-d') || !self::isRealDate($today)) {
            return $this->result($kind, 'bad_date', 'The date must be a real date that is not in the future.', $dryRun);
        }

        $db = Database::connection();
        $lock = $this->lockName();
        $stmt = $db->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([$lock]);
        if ((int) $stmt->fetchColumn() !== 1) {
            return $this->result($kind, 'locked', 'Another automatic CPL run is busy right now; nothing was done.', $dryRun);
        }
        try {
            $result = $work($now, $today);
        } catch (\Throwable $e) {
            error_log('CplAutoSubmissionService ' . $kind . ' run failed: ' . get_class($e) . ' ' . self::safeText($e->getMessage(), 200));
            $result = $this->result($kind, 'error', 'The automatic ' . $kind . ' run stopped with an error and sent nothing more (' . get_class($e) . ').', $dryRun);
            if (!$dryRun) {
                $this->alert($kind, 'error', $today, 'CPL automatic ' . $kind . ' file: the run failed', 'The automatic ' . $kind . " run stopped with an error (" . get_class($e) . ").\n\nNothing further was sent. The technical team can read the details in the server log.\n\nCPL Dashboard: " . $this->link('/reports/cpl-export/batches'));
            }
        } finally {
            try {
                $release = $db->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lock]);
            } catch (\Throwable $e) {
                error_log('CplAutoSubmissionService could not release its lock: ' . get_class($e)); // never turns a finished run into a failure
            }
        }

        if (!$dryRun && !in_array($result['outcome'], ['locked', 'bad_date'], true)) {
            $this->remember($kind, $now, $today, $result);
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function doDaily(\DateTimeImmutable $now, string $today, bool $dryRun): array
    {
        $uncertain = $this->uncertainBatch('Daily');
        if ($uncertain !== null) {
            return $this->heldBecauseUncertain('daily', $uncertain, $today, $dryRun);
        }

        $existing = $this->batches->findByTypeAndMonth('Daily', $today, false);
        if ($existing && !$this->mayRefresh($existing)) {
            return $this->result('daily', 'already_sent', 'A daily file for ' . $today . ' already exists (' . ($existing['status'] ?? '') . '); only one daily file is made per day, so nothing was sent.', $dryRun, ['batch_id' => (int) $existing['id']]);
        }

        $selected = $this->selector->daily(
            ($this->entriesLoader)(),
            $this->reportedSet(),
            $today,
            trim($this->settings->get('cpl_auto_daily_since', '')) ?: null,
            CplAutoSelector::parseExcludedNames($this->settings->get('cpl_auto_excluded_names', ''))
        );
        $records = $selected['records'];
        $exceptions = $selected['exceptions'];

        if ($records === [] && $exceptions === []) {
            if (!$dryRun && $existing) {
                $this->withdrawDraft((int) $existing['id'], 'Nothing is left to report for ' . $today . '.');
            }
            return $this->result('daily', 'nothing_to_report', 'Nothing to report: no loan was newly paid out or fully settled since the last daily file. No file was made or sent.', $dryRun);
        }

        $supplier = trim($this->settings->supplierReferenceNumber());
        $content = $this->buildDailyContent($records, $supplier, $today);
        $filename = $this->liveFilename('D', $today);

        $summary = [
            'origin' => self::ORIGIN,
            'kind' => 'Daily',
            'as_of' => $today,
            'sent' => count($records),
            'registrations' => $selected['registrations'],
            'closures' => $selected['closures'],
            'with_warnings' => $selected['warnings'],
            'blocked' => count($exceptions),
            'file_name' => $filename,
        ];

        // valid records go out; the loans that failed validation stay out (not marked reported, so they are tried again tomorrow). Only a file with
        // nothing valid in it is "held back as a whole".
        $holdCount = $records === [] ? count($exceptions) : 0;
        return $this->finish('daily', $now, $today, $records, $exceptions, $content, $filename, $summary, $existing, $dryRun, $supplier, $holdCount);
    }

    /**
     * The bytes of the daily file. Protected so a test can feed the final check a broken file.
     *
     * @param array<int, array<string,mixed>> $records
     */
    protected function buildDailyContent(array $records, string $supplier, string $today): string
    {
        $lines = [];
        foreach ($records as $r) {
            $lines[] = $this->builder->dailyRecord($r['fields'], $supplier, $today);
        }
        return implode("\r\n", $lines);
    }

    /**
     * Every completed month end from the first tracked loan up to the latest one, oldest first. Each month: already sent -> skip; not yet due -> stop;
     * blocked, held or unknown -> STOP (so the order is kept) and alert; otherwise build it, send it and go on to the next.
     *
     * @return array<string,mixed>
     */
    private function doMonthly(\DateTimeImmutable $now, string $today, bool $dryRun): array
    {
        $latest = self::previousMonthEnd($today);

        $uncertain = $this->uncertainBatch('Monthly');
        if ($uncertain !== null) {
            return $this->heldBecauseUncertain('monthly', $uncertain, $today, $dryRun);
        }

        $entries = ($this->entriesLoader)();
        $excluded = CplAutoSelector::parseExcludedNames($this->settings->get('cpl_auto_excluded_names', ''));
        $start = $this->monthlyStart($entries, $latest, $excluded);
        if ($start === null) {
            return $this->result('monthly', 'nothing_to_report', 'Nothing to report for ' . $latest . ': no tracked loan was paid out by that month end. No file was made or sent.', $dryRun, ['month_end' => $latest]);
        }

        // The window: every completed month end, minus the leading run that is already Submitted, THEN capped at MAX_MONTHS_PER_RUN months of work (so a
        // long history can never push the latest months out of reach). Months wait for the next run when the cap bites, and the staff are told.
        $allMonthEnds = self::monthEnds($start, $latest);
        $alreadySent = 0;
        foreach ($allMonthEnds as $m) {
            $b = $this->batches->findByTypeAndMonth('Monthly', $m, false);
            if (!$b || ($b['status'] ?? '') !== 'Submitted') {
                break;
            }
            $alreadySent++;
        }
        $pendingMonthEnds = array_slice($allMonthEnds, $alreadySent);
        $monthEnds = array_slice($pendingMonthEnds, 0, self::MAX_MONTHS_PER_RUN);
        $windowMissesLatest = $monthEnds !== [] && end($monthEnds) !== $latest;
        // In order: the bureau must never get an older month AFTER a later one (a late answer could back-date a pay-out and move the start earlier).
        $laterSubmitted = $this->latestSubmittedMonthEnd();

        $sent = [];
        $recordsSent = 0;
        $stop = null;
        $stoppedAt = null;
        $skippedNothing = 0;
        foreach ($monthEnds as $monthEnd) {
            $sendFrom = $this->sendFromDate($monthEnd);
            $existing = $this->batches->findByTypeAndMonth('Monthly', $monthEnd, false);
            if ($existing && ($existing['status'] ?? '') === 'Submitted') {
                $alreadySent++;
                continue; // this month is done
            }
            if ($today < $sendFrom) {
                $stop = $this->result('monthly', 'not_due', 'The monthly file for ' . $monthEnd . ' is sent from ' . $sendFrom . ' (working day ' . $this->sendDay() . ' of the month); not due yet.', $dryRun, ['month_end' => $monthEnd, 'send_from' => $sendFrom]);
                $stoppedAt = $monthEnd;
                break;
            }

            $takeOver = false;
            if ($existing && self::isAutoBatch($existing)) {
                if (!$this->mayRefresh($existing)) {
                    $stop = $this->result('monthly', 'monthly_blocked', 'The automatic monthly batch for ' . $monthEnd . ' is in the state "' . ($existing['status'] ?? '') . '" and cannot be rebuilt; the later months wait behind it so they are sent in order.', $dryRun, ['batch_id' => (int) $existing['id'], 'month_end' => $monthEnd]);
                    if (!$dryRun) {
                        $this->alert('monthly', 'monthly_blocked', $today, 'CPL automatic monthly file: a month is blocked', 'The automatic monthly batch for ' . $monthEnd . ' (batch #' . (int) $existing['id'] . ') is in the state "' . ($existing['status'] ?? '') . "\" and cannot be sent by the automatic run. Later months wait behind it.\n\nCPL Dashboard: " . $this->link('/reports/cpl-export/batches/' . (int) $existing['id']));
                    }
                    $stoppedAt = $monthEnd;
                    break;
                }
            } elseif ($existing) {
                if ($this->isStaleUnsentManualBatch($existing)) {
                    $takeOver = true;
                } else {
                    $stop = $this->result('monthly', 'manual_batch', 'A monthly batch for ' . $monthEnd . ' was prepared by staff (status ' . $existing['status'] . ') and is being worked on; the automatic submission leaves it alone and sent nothing. The later months wait behind it so they are sent in order.', $dryRun, ['batch_id' => (int) $existing['id'], 'month_end' => $monthEnd]);
                    if (!$dryRun) {
                        $this->alert('monthly', 'manual_batch', $today, 'CPL automatic monthly file: a staff batch is in the way', 'A monthly batch for ' . $monthEnd . ' was prepared by staff (status ' . $existing['status'] . ', batch #' . (int) $existing['id'] . ") and is not finished, so the automatic submission did not send that month - and the months after it wait so they go out in order.\n\nFinish or reject that batch, then the next run carries on.\n\nCPL Dashboard: " . $this->link('/reports/cpl-export/batches/' . (int) $existing['id']));
                    }
                    $stoppedAt = $monthEnd;
                    break;
                }
            }

            $outOfOrderBy = ($laterSubmitted !== null && $monthEnd < $laterSubmitted) ? $laterSubmitted : null;
            $r = $this->monthlyOne($now, $today, $monthEnd, $existing, $dryRun, $takeOver, $entries, $excluded, $outOfOrderBy);
            if ($r['outcome'] === 'nothing_to_report') {
                $skippedNothing++;
                continue; // no tracked loan was open at that month end
            }
            if ($r['outcome'] === 'sent') {
                $sent[] = $r['file'];
                $recordsSent += (int) $r['records'];
                continue;
            }
            $stop = $r;
            $stoppedAt = $monthEnd;
            break; // blocked / held / unknown: later months wait
        }

        if ($stop !== null && $stoppedAt !== null && $stop['outcome'] !== 'not_due') {
            $waiting = 0;
            foreach ($monthEnds as $m) {
                if ($m > $stoppedAt && $today >= $this->sendFromDate($m)) {
                    $waiting++;
                }
            }
            if ($waiting > 0 && !$dryRun) {
                $this->alert('monthly', 'rolled_over', $today, 'CPL automatic monthly file: months are waiting', $waiting . ' later month(s) have rolled over and are waiting behind the month ' . $stoppedAt . ', which is not sent yet (' . $stop['outcome'] . "). They are sent in order as soon as it is.\n\nCPL Dashboard: " . $this->link('/reports/cpl-export/batches'));
            }
        }

        $capNote = '';
        if ($windowMissesLatest) {
            $beyond = count($pendingMonthEnds) - count($monthEnds);
            $capNote = ' ' . $beyond . ' later month(s), up to ' . $latest . ', are beyond the limit of ' . self::MAX_MONTHS_PER_RUN . ' months per run and wait for the next run.';
            if (!$dryRun) {
                $this->alert('monthly', 'window_capped', $today, 'CPL automatic monthly file: more months are waiting than one run handles', $beyond . ' month(s) up to ' . $latest . ' were not handled in this run because only ' . self::MAX_MONTHS_PER_RUN . " months are processed per run. They are sent, in order, by the next runs.\n\nCPL Dashboard: " . $this->link('/reports/cpl-export/batches'));
            }
        }

        if ($stop === null) {
            if ($capNote !== '' && $sent === []) {
                return $this->result('monthly', 'monthly_window_capped', 'No monthly file could be sent in this run.' . $capNote, $dryRun, ['month_end' => $latest]);
            }
            if ($sent === [] && $skippedNothing > 0 && $alreadySent === 0) {
                return $this->result('monthly', 'nothing_to_report', 'Nothing to report up to ' . $latest . ': no tracked loan was open at any of those month ends. No file was made or sent.', $dryRun, ['month_end' => $latest]);
            }
            if ($sent === []) {
                return $this->result('monthly', 'already_sent', 'Every completed month end up to ' . $latest . ' was already sent; each is sent at most once.', $dryRun, ['month_end' => $latest]);
            }
            return $this->result('monthly', 'sent', count($sent) . ' monthly file(s) were uploaded to Creditinfo and recorded (waiting for the load report): ' . implode(', ', $sent) . $capNote, $dryRun, ['files' => $sent, 'file' => end($sent), 'records' => $recordsSent, 'month_end' => $latest, 'sent' => true]);
        }
        if ($sent !== []) {
            $stop['message'] = count($sent) . ' monthly file(s) were sent first (' . implode(', ', $sent) . '); then: ' . $stop['message'];
            $stop['files'] = $sent;
        }
        return $stop;
    }

    /**
     * One month end of the monthly catch-up.
     *
     * @param array<int, array<string,mixed>> $entries
     * @param string[] $excluded
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function monthlyOne(\DateTimeImmutable $now, string $today, string $monthEnd, ?array $existing, bool $dryRun, bool $takeOver, array $entries, array $excluded, ?string $outOfOrderBy = null): array
    {
        $supplier = trim($this->settings->supplierReferenceNumber());
        $built = $this->selector->monthly($entries, $monthEnd, $now->format('Y-m-d H:i:s'), $supplier, $this->settings->tradingName(), $excluded);
        $result = $built['result'];
        $exceptions = $result['exceptions'];

        if ($result['records'] === 0 && $exceptions === []) {
            if (!$dryRun && $existing) {
                $this->withdrawDraft((int) $existing['id'], 'Nothing is left to report for ' . $monthEnd . '.');
            }
            return $this->result('monthly', 'nothing_to_report', 'Nothing to report for ' . $monthEnd . ': no tracked loan was open at that month end. No file was made or sent.', $dryRun, ['month_end' => $monthEnd]);
        }

        $filename = $this->liveFilename('M', $monthEnd);
        $summary = $result['summary'] + [
            'origin' => self::ORIGIN,
            'kind' => 'Monthly',
            'file_name' => $filename,
            'pending_answers' => count($built['pending']),
        ];
        $records = [];
        foreach ($built['followup_ids'] as $fid) {
            $records[] = ['kind' => 'M', 'followup_id' => $fid, 'application_no' => (string) ($built['application_numbers'][$fid] ?? ''), 'event_date' => $monthEnd];
        }
        if ($takeOver && !$dryRun && $existing) {
            $this->audit('Update', 'The automatic submission took over the unsent staff batch #' . (int) $existing['id'] . ' for ' . $monthEnd . ' (status ' . ($existing['status'] ?? '') . ', no submission) and rebuilds it as an automatic batch');
            $this->alert('monthly', 'taken_over', $today, 'CPL automatic monthly file: a staff batch was taken over', 'The unsent staff batch #' . (int) $existing['id'] . ' for ' . $monthEnd . ' (status ' . ($existing['status'] ?? '') . ") had no submission and the month was overdue, so the automatic submission rebuilt it as an automatic batch.\n\nCPL Dashboard: " . $this->link('/reports/cpl-export/batches/' . (int) $existing['id']));
        }

        // the monthly file is held as a whole ONLY while an instalment has never been answered
        $hold = $result['records'] === 0 ? count($exceptions) : count($built['pending']);
        return $this->finish('monthly', $now, $monthEnd, $records, $exceptions, (string) $result['file'], $filename, $summary, $existing, $dryRun, $supplier, $hold, $today, $outOfOrderBy);
    }

    /**
     * The part daily and monthly share: store the batch, then send it if (and only if) every gate is open.
     *
     * @param array<int, array<string,mixed>> $records
     * @param array<int, array<string,mixed>> $exceptions
     * @param array<string,mixed> $summary
     * @param array<string,mixed>|null $existing
     * @param int $holdCount how many problems hold the WHOLE file back (the others only leave their loan out)
     * @return array<string,mixed>
     */
    private function finish(string $kind, \DateTimeImmutable $now, string $date, array $records, array $exceptions, string $content, string $filename, array $summary, ?array $existing, bool $dryRun, string $supplier, int $holdCount, ?string $alertDate = null, ?string $outOfOrderBy = null): array
    {
        $alertDate ??= $date;
        $fileProblems = $records === []
            ? []
            : CplFileValidator::validate($kind, $content, count($records), $this->liveFilename($kind === 'daily' ? 'D' : 'M', $date), $filename, $date);
        $summary['file_problems'] = $fileProblems;
        $gate = $this->gate($kind, $now, $records, $exceptions, $supplier, $holdCount, $fileProblems, $outOfOrderBy);
        $base = ['records' => count($records), 'exceptions' => count($exceptions), 'file' => $filename, 'date' => $date];

        if ($dryRun) {
            return $this->result($kind, $gate === null ? 'would_send' : $gate['code'], $gate === null ? 'This is what would be sent now: ' . $filename . ' with ' . count($records) . ' record(s)' . ($exceptions !== [] ? ' (' . count($exceptions) . ' loan(s) held back)' : '') . '.' : 'Would NOT send: ' . $gate['message'], true, $base + [
                'exception_list' => array_map(static fn (array $x): string => ($x['ref_no'] ?? '') . ' ' . ($x['name'] ?? '') . ': ' . implode(' | ', $x['reasons'] ?? []), $exceptions),
                'preview' => array_map(static fn (array $r): string => $r['kind'] . ' ' . ($r['application_no'] ?? '') . ' ' . ($r['name'] ?? ''), $records),
                'would_send' => $gate === null,
            ]);
        }

        $batchId = $this->storeBatch($kind === 'daily' ? 'Daily' : 'Monthly', $date, $records, $exceptions, $content, $summary, $existing, $filename);
        $base['batch_id'] = $batchId;

        if ($gate !== null) {
            if (!empty($gate['revoke'])) {
                $this->revokeStandingApproval($gate['revoke_reason'] ?? 'the submission environment, supplier reference, trading name, recipient or SFTP destination changed since it was given', null);
                $this->settings->set('cpl_auto_submit_enabled', '0', null);
            }
            $this->alertHeld($kind, $gate, $alertDate, $filename, count($records), count($exceptions), $batchId);
            return $this->result($kind, $gate['code'], $gate['message'], false, $base + ['sent' => false]);
        }

        $delivered = $this->deliver($kind, $batchId, $filename, $content, $now, $alertDate, count($records));
        if ($delivered['outcome'] === 'sent' && $exceptions !== []) {
            $this->alert($kind, 'held_loans', $alertDate, 'CPL automatic ' . $kind . ' file: some loans were held back', count($exceptions) . ' loan(s) could not be reported (they failed validation or lack an answer) and were left out of ' . $filename . ' (' . count($records) . " record(s) were sent). They are tried again on the next run.\n\nThe batch shows which and why: " . $this->link('/reports/cpl-export/batches/' . $batchId));
        }
        return $this->result($kind, $delivered['outcome'], $delivered['message'], false, $base + ['sent' => $delivered['outcome'] === 'sent']);
    }

    /**
     * Every reason a file may not go out right now. Null = the way is clear.
     *
     * @param array<int, array<string,mixed>> $records
     * @param array<int, array<string,mixed>> $exceptions
     * @param string[] $fileProblems
     * @return array{code:string, message:string, revoke?:bool}|null
     */
    private function gate(string $kind, \DateTimeImmutable $now, array $records, array $exceptions, string $supplier, int $holdCount, array $fileProblems, ?string $outOfOrderBy = null): ?array
    {
        if ($holdCount > 0) {
            return ['code' => 'draft_blocked', 'message' => ($kind === 'monthly'
                ? $holdCount . ' loan(s) cannot be reported yet (an instalment that fell due was never answered on the Loan Questions page, or the loan failed validation), so the monthly file is held back as a whole and nothing was sent.'
                : 'None of the ' . $holdCount . ' loan(s) can be reported yet (blocking validation problems), so there is nothing valid to send and nothing was sent.') . ' The draft shows which and why.'];
        }
        if ($records === []) {
            return ['code' => 'nothing_to_report', 'message' => 'The file has no records, so nothing was sent.'];
        }
        if ($outOfOrderBy !== null) {
            // DECISION: no stored high-water mark, the batches themselves are the record: an older month is never sent after a later month was Submitted.
            return ['code' => 'draft_out_of_order', 'message' => 'A later monthly file (month end ' . $outOfOrderBy . ') was already sent to Creditinfo, so this older month is NOT sent automatically: a late file could move the start of the reported history earlier. It is kept as a draft; a person must decide (send it by hand after checking with Creditinfo, or leave it). Later months wait behind it. Nothing was sent.'];
        }
        if ($fileProblems !== []) {
            return ['code' => 'draft_blocked', 'message' => 'The finished file failed its final check before upload, so nothing was sent: ' . implode('; ', array_slice($fileProblems, 0, 5)) . '.'];
        }
        if (!preg_match('/^[A-Za-z0-9]{1,10}$/D', $supplier)) {
            return ['code' => 'draft_blocked', 'message' => 'The Supplier Reference Number is not set (or is not valid) in CPL Settings, so there is no valid file name. Nothing was sent.'];
        }
        if ($this->isRestrictedInstance()) {
            return ['code' => 'refused_instance', 'message' => 'This is a demo or sandbox copy of the system, which never sends a live file to Creditinfo. Nothing was sent.'];
        }
        if ($this->haltedReason() !== null) {
            return ['code' => 'halted', 'message' => 'Automatic submission is stopped because Creditinfo\'s server refused the saved SFTP login. Nothing is tried again until a person fixes the SFTP settings and acknowledges it on the CPL dashboard.'];
        }
        if (!$this->isEnabled()) {
            return ['code' => 'draft_switch_off', 'message' => 'Automatic submission is switched OFF, so the file was only prepared as a draft and nothing was sent.'];
        }
        if (!$this->settings->isProductionEnvironment()) {
            return ['code' => 'draft_not_production', 'message' => 'The CPL submission environment is not Production, so a live (L702) file is never sent. The file was only prepared as a draft.'];
        }
        if (!$this->sftp->isReady()) {
            return ['code' => 'draft_sftp_not_ready', 'message' => 'The live SFTP submission is not switched on or the SFTP connection is incomplete (CPL Settings / Public Defaults Settings). The file was only prepared as a draft.'];
        }
        $approval = $this->standingApproval();
        if ($approval === null) {
            return ['code' => 'draft_no_approval', 'message' => 'There is no standing approval on record for the automatic submission (it is given by turning the switch on, and is revoked when the switch is turned off). Nothing was sent.'];
        }
        if (($approval['fingerprint'] ?? '') !== $this->currentFingerprint()) {
            return ['code' => 'draft_no_approval', 'revoke' => true, 'message' => 'The standing approval no longer applies: the submission environment, supplier reference, trading name, recipient or SFTP destination changed after it was given. It was revoked and the switch turned off; turn it on again to approve the new settings. Nothing was sent.'];
        }
        $approverProblem = $this->approverProblem((int) ($approval['user_id'] ?? 0));
        if ($approverProblem !== null) {
            return ['code' => 'draft_no_approval', 'revoke' => true, 'revoke_reason' => 'the approving user ' . $approverProblem, 'message' => 'The standing approval no longer applies: the person who gave it ' . $approverProblem . '. It was revoked and the switch turned off; a person with the CPL approval permission must turn it on again. Nothing was sent.'];
        }
        $previewProblem = $this->previewProblem($kind, count($records), $approval);
        if ($previewProblem !== null) {
            return ['code' => 'draft_exceeds_preview', 'message' => $previewProblem];
        }
        $failure = $this->lastFailure($kind);
        if ($failure !== null && strtotime($failure['at']) > $now->getTimestamp() - self::RETRY_AFTER_FAILURE_HOURS * 3600) {
            return ['code' => 'backoff', 'message' => 'The last upload attempt failed at ' . $failure['at'] . ' (' . $failure['message'] . '). To avoid hammering the server (or locking the account), no new attempt is made for ' . self::RETRY_AFTER_FAILURE_HOURS . ' hours.'];
        }
        return null;
    }

    /**
     * The standing approval is a person's decision, so it is only as good as that person's standing: they must still be an active user and still hold the
     * approval permission. Null = fine; otherwise a phrase saying what is wrong. Protected so a test can replace the lookup.
     */
    protected function approverProblem(int $userId): ?string
    {
        $stmt = Database::connection()->prepare('SELECT is_active FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $active = $stmt->fetchColumn();
        if ($active === false) {
            return 'no longer exists';
        }
        if ((int) $active !== 1) {
            return 'is no longer an active user';
        }
        foreach ((new User())->usersWithPermission(self::APPROVE_PERMISSION) as $u) {
            if ((int) $u['id'] === $userId) {
                return null;
            }
        }
        return 'no longer holds the CPL approval permission (' . self::APPROVE_PERMISSION . ')';
    }

    /** The very first live automatic file of a kind may not be much bigger than the preview the enabler looked at. */
    private function previewProblem(string $kind, int $records, array $approval): ?string
    {
        if ($this->hasSubmittedAutoBatch($kind === 'daily' ? 'Daily' : 'Monthly')) {
            return null;
        }
        $preview = isset($approval['preview'][$kind]) ? (int) $approval['preview'][$kind] : 0;
        if ($records > $preview * self::PREVIEW_TOLERANCE) {
            return 'This would be the first live automatic ' . $kind . ' file and it has ' . $records . ' record(s), but the preview shown when the switch was turned on had ' . $preview . ' (up to ' . (int) floor($preview * self::PREVIEW_TOLERANCE) . ' are allowed). Nothing was sent. Check the draft; to approve the new size turn the switch off and on again, which shows a fresh preview.';
        }
        return null;
    }

    /** The latest month end of an AUTOMATIC monthly batch that was Submitted (a staff batch is a person's own business); null when none. */
    private function latestSubmittedMonthEnd(): ?string
    {
        $stmt = Database::connection()->prepare("SELECT MAX(month_end) FROM cpl_batches WHERE batch_type = 'Monthly' AND is_uat_test = 0 AND source = 'SolidFin' AND status = 'Submitted' AND legacy_summary LIKE ?");
        $stmt->execute(['%' . substr((string) json_encode(self::ORIGIN), 1, -1) . '%']);
        $v = $stmt->fetchColumn();
        return $v ? substr((string) $v, 0, 10) : null;
    }

    private function hasSubmittedAutoBatch(string $type): bool
    {
        $stmt = Database::connection()->prepare("SELECT COUNT(*) FROM cpl_batches WHERE batch_type = ? AND is_uat_test = 0 AND source = 'SolidFin' AND status = 'Submitted' AND legacy_summary LIKE ?");
        $stmt->execute([$type, '%' . substr((string) json_encode(self::ORIGIN), 1, -1) . '%']);
        return (int) $stmt->fetchColumn() > 0;
    }

    // ------------------------------------------------------------- delivery

    /** @return array{outcome:string, message:string} */
    private function deliver(string $kind, int $batchId, string $filename, string $content, \DateTimeImmutable $now, string $alertDate, int $recordCount): array
    {
        $at = $now->format('Y-m-d H:i:s');
        $approval = $this->standingApproval() ?? [];
        $approver = isset($approval['user_id']) ? (int) $approval['user_id'] : null;
        $approvedAt = (string) ($approval['at'] ?? '');
        $cite = 'Standing approval given by user #' . ($approver ?? '?') . ' on ' . ($approvedAt !== '' ? $approvedAt : 'an unknown date') . ' (switching automatic submission on).';

        // 1. Write the intent down BEFORE anything leaves: if anything goes wrong from here on, the batch says "outcome unknown", never "not sent".
        $this->batches->updateRecord($batchId, [
            'submission_outcome' => 'Awaiting Load Report',
            'submission_notes' => self::IN_PROGRESS_PREFIX . ' on ' . $at . ' as ' . $filename . '. ' . $cite . ' If this note is still here, the outcome is not known: do not send it again without checking with Creditinfo.',
            'submission_recorded_by' => null,
            'submission_recorded_at' => $at,
            'approved_by' => $approver,
            'approved_at' => $approvedAt !== '' ? $approvedAt : null,
        ]);
        $this->audit('Upload', 'Automatic upload of ' . $filename . ' started (' . $recordCount . ' record(s), batch #' . $batchId . ')', ['file' => $filename, 'records' => $recordCount, 'batch_id' => $batchId, 'outcome' => 'started']);

        // 2. Upload.
        try {
            $sent = $this->sftp->uploadLiveFile($filename, $content, $this->connect);
        } catch (CplSftpUncertainException $e) {
            $this->batches->updateRecord($batchId, [
                'submission_outcome' => 'Awaiting Load Report',
                'submission_notes' => self::UNCLEAR_PREFIX . ' on ' . $at . ': ' . self::safeText($e->getMessage(), 400) . ' Creditinfo may or may not have ' . $filename . '. Ask Creditinfo, then say on the CPL dashboard whether they have it.',
                'submission_recorded_at' => $at,
            ]);
            $this->recordFailure($kind, $at, 'the connection broke while sending');
            $this->audit('Upload', 'Automatic upload of ' . $filename . ' UNCERTAIN: the outcome is unknown (batch #' . $batchId . ', ' . $recordCount . ' record(s))', ['file' => $filename, 'records' => $recordCount, 'batch_id' => $batchId, 'outcome' => 'uncertain']);
            $this->alert($kind, 'uncertain', $alertDate, 'CPL automatic ' . $kind . ' file: upload UNCLEAR - please check with Creditinfo', 'The connection broke while ' . $filename . " was being sent, so Creditinfo may or may not have it.\n\nNOTHING further will be sent automatically for the " . $kind . " file until you say what happened: ask Creditinfo whether they received it, then open the CPL Dashboard and press \"Creditinfo has this file\" or \"Creditinfo does NOT have this file\".\n\nCPL Dashboard: " . $this->link('/reports/cpl-export/batches'));
            return ['outcome' => 'uncertain', 'message' => 'The connection broke while sending (or the server did not confirm the write); the outcome is unknown. The loans are NOT marked as reported and nothing more is sent until a person confirms.'];
        } catch (\Throwable $e) {
            // Nothing was delivered (could not connect, login refused, the server refused the file).
            $this->batches->updateRecord($batchId, [
                'submission_outcome' => null,
                'submission_notes' => self::FAILED_PREFIX . ' on ' . $at . ': ' . self::safeText($e->getMessage(), 300),
                'submission_recorded_at' => $at,
                'approved_by' => null,
                'approved_at' => null,
            ]);
            $label = self::reasonLabel($e);
            $this->audit('Upload', 'Automatic upload of ' . $filename . ' FAILED, nothing was delivered: ' . $label . ' (batch #' . $batchId . ', ' . $recordCount . ' record(s))', ['file' => $filename, 'records' => $recordCount, 'batch_id' => $batchId, 'outcome' => 'failed']);
            if ($e instanceof CplSftpLoginRefusedException) {
                $this->haltOnLoginRefusal($kind, $batchId, $at, $alertDate);
                return ['outcome' => 'failed', 'message' => 'Creditinfo\'s server refused the saved SFTP login. Nothing was sent, and automatic submission is stopped (and switched off) until a person fixes the SFTP settings and acknowledges it on the CPL dashboard.'];
            }
            $this->recordFailure($kind, $at, $label);
            $this->alert($kind, 'failed', $alertDate, 'CPL automatic ' . $kind . ' file: upload FAILED', 'The automatic upload of ' . $filename . ' failed (' . $label . ") and nothing was marked as reported.\n\nThe file is kept as a draft. The system will try again after " . self::RETRY_AFTER_FAILURE_HOURS . " hours.\n\nCPL Dashboard: " . $this->link('/reports/cpl-export/batches'));
            return ['outcome' => 'failed', 'message' => 'The upload failed and nothing was delivered (' . $label . ').'];
        }

        // 3. Record the confirmed delivery. If this write fails, the batch stays IN PROGRESS (= unknown), so it can never be sent twice.
        try {
            $db = Database::connection();
            $db->beginTransaction();
            try {
                $mark = $db->prepare("UPDATE cpl_auto_reported SET state = 'Reported', reported_at = ?, file_name = ? WHERE batch_id = ? AND state = 'Planned'");
                $mark->execute([$at, $filename, $batchId]);
                if ($mark->rowCount() < 1) {
                    throw new \RuntimeException('No loan row was marked as reported for batch #' . $batchId . '.');
                }
                $this->batches->updateRecord($batchId, [
                    'status' => 'Submitted',
                    'submission_outcome' => 'Awaiting Load Report',
                    'submission_notes' => 'Submitted automatically via SFTP to ' . $sent['host'] . ':' . $sent['remote_path'] . ' on ' . $at . ' (' . $sent['bytes'] . ' bytes). ' . $cite . ' Waiting for the Creditinfo load report.',
                    'submission_recorded_by' => null,
                    'submission_recorded_at' => $at,
                ]);
                $db->prepare('INSERT INTO cpl_submissions (batch_id, sequence_number, file_name, submitted_by, submitted_at, outcome, notes) VALUES (?, 1, ?, NULL, ?, ?, ?)')
                    ->execute([$batchId, $filename, $at, 'Awaiting Load Report', 'Automatic submission']);
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
        } catch (\Throwable $e) {
            error_log('CPL automatic file ' . $filename . ' was uploaded but could not be recorded on batch #' . $batchId . ': ' . get_class($e) . ' ' . self::safeText($e->getMessage(), 200));
            $submitted = false;
            try {
                $fresh = $this->batches->find($batchId);
                $submitted = $fresh && ($fresh['status'] ?? '') === 'Submitted';
            } catch (\Throwable $ignored) {
            }
            if ($submitted) {
                $this->audit('Upload', 'Automatic upload of ' . $filename . ' SENT and recorded as Submitted, but a follow-up step failed (batch #' . $batchId . ')', ['file' => $filename, 'records' => $recordCount, 'batch_id' => $batchId, 'outcome' => 'sent']);
                $this->alert($kind, 'unrecorded', $alertDate, 'CPL automatic ' . $kind . ' file: sent, but a follow-up step failed', $filename . " was uploaded to Creditinfo and the batch IS recorded as Submitted, but a follow-up write failed (" . get_class($e) . "). The technical team can read the details in the server log.\n\nCPL Dashboard: " . $this->link('/reports/cpl-export/batches/' . $batchId));
                return ['outcome' => 'sent', 'message' => $filename . ' was uploaded to Creditinfo and the batch IS recorded as Submitted; a follow-up step failed (see the server log).'];
            }
            $this->audit('Upload', 'Automatic upload of ' . $filename . ' SENT but NOT recorded: held as outcome-unknown (batch #' . $batchId . ', ' . $recordCount . ' record(s))', ['file' => $filename, 'records' => $recordCount, 'batch_id' => $batchId, 'outcome' => 'unrecorded']);
            $this->alert($kind, 'unrecorded', $alertDate, 'CPL automatic ' . $kind . ' file: uploaded but NOT recorded', $filename . " was uploaded to Creditinfo, but the system could not write that down (" . get_class($e) . ").\n\nIt is marked as outcome-unknown so it is never sent twice. Confirm on the CPL dashboard that Creditinfo has this file.\n\nCPL Dashboard: " . $this->link('/reports/cpl-export/batches'));
            return ['outcome' => 'uncertain', 'message' => 'The file was uploaded but could not be recorded; it is held as outcome-unknown so it cannot be sent twice.'];
        }

        $this->audit('Upload', 'Automatic upload of ' . $filename . ' SENT and recorded (' . $recordCount . ' record(s), batch #' . $batchId . ')', ['file' => $filename, 'records' => $recordCount, 'batch_id' => $batchId, 'outcome' => 'sent']);
        try {
            $this->settings->set('cpl_auto_last_success', json_encode(['kind' => $kind, 'file' => $filename, 'at' => $at, 'batch_id' => $batchId]), null);
        } catch (\Throwable $e) {
            error_log('CplAutoSubmissionService could not remember the last success: ' . get_class($e)); // the upload is recorded; a bookkeeping write must not undo that
        }
        return ['outcome' => 'sent', 'message' => $filename . ' was uploaded to Creditinfo and recorded (waiting for the load report).'];
    }

    /**
     * A person says what happened to an upload whose outcome was unknown. true = Creditinfo has the file (its loans are marked reported, the batch is
     * Submitted); false = it does not (the batch is a plain draft again; the next run builds a fresh file from everything not yet reported).
     *
     * Refused while a run may be in the middle of that very upload: it needs the run lock, unless the IN PROGRESS note is old enough (10 minutes) to belong
     * to a dead run. The batch is re-read inside the transaction with a row lock, so two people answering at once cannot both win.
     *
     * @throws \RuntimeException when the batch is not one that is waiting for this answer, or a run is busy
     */
    public function resolveUncertain(int $batchId, bool $creditinfoHasIt, ?int $userId): void
    {
        $batch = $this->batches->find($batchId);
        if (!$batch || !self::isUncertain($batch)) {
            throw new \RuntimeException(($batch && ($batch['status'] ?? '') === 'Submitted') ? 'This batch is already recorded as Submitted; it is not waiting for a confirmation.' : 'This batch is not waiting for a confirmation.');
        }
        $db = Database::connection();
        $lock = $this->lockName();
        $stmt = $db->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([$lock]);
        $haveLock = (int) $stmt->fetchColumn() === 1;
        try {
            if (!$haveLock && !$this->inProgressIsStale($batch)) {
                throw new \RuntimeException('An automatic run is busy right now and may be in the middle of this very upload. Wait a few minutes and try again.');
            }
            $this->doResolve($batchId, $creditinfoHasIt, $userId);
        } finally {
            if ($haveLock) {
                try {
                    $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
                } catch (\Throwable $e) {
                    error_log('CplAutoSubmissionService could not release its lock: ' . get_class($e));
                }
            }
        }
    }

    private function inProgressIsStale(array $batch): bool
    {
        $notes = (string) ($batch['submission_notes'] ?? '');
        if (!str_starts_with($notes, self::IN_PROGRESS_PREFIX)) {
            return false;
        }
        $recorded = strtotime((string) ($batch['submission_recorded_at'] ?? ''));
        return $recorded !== false && $recorded < ($this->clock)()->getTimestamp() - self::IN_PROGRESS_STALE_MINUTES * 60;
    }

    private function doResolve(int $batchId, bool $creditinfoHasIt, ?int $userId): void
    {
        $at = ($this->clock)()->format('Y-m-d H:i:s');
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $locked = $db->prepare('SELECT * FROM cpl_batches WHERE id = ? FOR UPDATE');
            $locked->execute([$batchId]);
            $batch = $locked->fetch();
            if (!$batch || !self::isUncertain($batch)) {
                throw new \RuntimeException(($batch && ($batch['status'] ?? '') === 'Submitted') ? 'This batch is already recorded as Submitted; it is not waiting for a confirmation.' : 'This batch is not waiting for a confirmation.');
            }
            if ($creditinfoHasIt) {
                $mark = $db->prepare("UPDATE cpl_auto_reported SET state = 'Reported', reported_at = ?, file_name = ? WHERE batch_id = ? AND state = 'Planned'");
                $mark->execute([$at, (string) (json_decode((string) $batch['legacy_summary'], true)['file_name'] ?? ''), $batchId]);
                if ($mark->rowCount() < 1) {
                    throw new \RuntimeException('No loan of this batch could be marked as reported, so nothing was changed.');
                }
                $this->batches->updateRecord($batchId, [
                    'status' => 'Submitted',
                    'submission_outcome' => 'Awaiting Load Report',
                    'submission_notes' => 'Confirmed by a person on ' . $at . ' that Creditinfo has this file. Waiting for the Creditinfo load report. (Earlier note: ' . mb_substr((string) $batch['submission_notes'], 0, 200) . ')',
                    'submission_recorded_by' => $userId,
                    'submission_recorded_at' => $at,
                ]);
            } else {
                $db->prepare("DELETE FROM cpl_auto_reported WHERE batch_id = ? AND state = 'Planned'")->execute([$batchId]);
                $this->batches->updateRecord($batchId, [
                    'status' => 'Rejected',
                    'rejected_by' => $userId,
                    'rejected_at' => $at,
                    'rejection_reason' => 'A person confirmed on ' . $at . ' that Creditinfo did NOT receive this file; the loans in it will be in the next automatic file.',
                    'submission_outcome' => null,
                    'submission_notes' => 'Confirmed by a person on ' . $at . ' that Creditinfo does not have this file. (Earlier note: ' . mb_substr((string) $batch['submission_notes'], 0, 200) . ')',
                ]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    // --------------------------------------------------------------- storage

    /**
     * Creates (or refreshes, while it is still an unsent draft) the batch and its "Planned" rows. For a daily file every other unsent daily draft is
     * withdrawn: this file already contains everything they held and more.
     *
     * @param array<int, array<string,mixed>> $records
     * @param array<int, array<string,mixed>> $exceptions
     * @param array<string,mixed> $summary
     * @param array<string,mixed>|null $existing
     */
    private function storeBatch(string $type, string $date, array $records, array $exceptions, string $content, array $summary, ?array $existing, string $filename): int
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $data = [
                'source' => 'SolidFin',
                'status' => $exceptions !== [] ? 'Draft' : 'Ready for Review',
                'total_records' => count($records),
                'blocking_error_count' => count($exceptions),
                'warning_count' => (int) ($summary['with_warnings'] ?? 0),
                'file_content' => $content,
                'legacy_exceptions' => json_encode($exceptions),
                'legacy_summary' => json_encode($summary),
                'generated_by' => null,
                'generated_at' => ($this->clock)()->format('Y-m-d H:i:s'),
                'supplier_reference_number' => mb_substr(trim($this->settings->supplierReferenceNumber()), 0, 10) ?: null,
                'submission_outcome' => null,
                'approval_request_id' => null,
                'approved_by' => null,
                'approved_at' => null,
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ];
            if ($existing) {
                $batchId = (int) $existing['id'];
                $this->batches->updateRecord($batchId, $data);
                $db->prepare("DELETE FROM cpl_auto_reported WHERE batch_id = ? AND state = 'Planned'")->execute([$batchId]);
            } else {
                $batchId = $this->batches->create($data + ['batch_type' => $type, 'month_end' => $date]);
            }

            // A loan already planned in another unsent draft moves to this batch; one that is Reported is never touched.
            $plan = $db->prepare("INSERT INTO cpl_auto_reported (followup_id, application_no, report_kind, period_key, batch_id, state)
                                  VALUES (?, ?, ?, ?, ?, 'Planned')
                                  ON DUPLICATE KEY UPDATE batch_id = IF(state = 'Planned', VALUES(batch_id), batch_id)");
            foreach ($records as $r) {
                $plan->execute([(int) $r['followup_id'], (string) $r['application_no'], $r['kind'], $r['kind'] === 'M' ? $date : CplAutoSelector::ONCE, $batchId]);
            }

            if ($type === 'Daily') {
                $db->prepare("UPDATE cpl_batches SET status = 'Rejected', rejected_at = NOW(), rejection_reason = ?
                              WHERE batch_type = 'Daily' AND is_uat_test = 0 AND source = 'SolidFin' AND id <> ? AND month_end < ?
                                AND status IN ('Draft', 'Ready for Review') AND submission_outcome IS NULL AND legacy_summary LIKE ?")
                    ->execute(['Superseded by the automatic daily batch of ' . $date . ', which holds everything not yet reported.', $batchId, $date, '%' . substr(json_encode(self::ORIGIN), 1, -1) . '%']);
            }
            $db->commit();
            return $batchId;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private function withdrawDraft(int $batchId, string $reason): void
    {
        Database::connection()->prepare("DELETE FROM cpl_auto_reported WHERE batch_id = ? AND state = 'Planned'")->execute([$batchId]);
        $this->batches->updateRecord($batchId, ['status' => 'Rejected', 'rejected_at' => ($this->clock)()->format('Y-m-d H:i:s'), 'rejection_reason' => $reason, 'total_records' => 0]);
    }

    /** An automatic batch may be rebuilt only while nothing has been sent from it: an unsent draft, or one a person or the system withdrew. */
    private function mayRefresh(array $batch): bool
    {
        return self::isAutoBatch($batch)
            && in_array($batch['status'] ?? '', ['Draft', 'Ready for Review', 'Rejected'], true)
            && empty($batch['submission_outcome']);
    }

    /**
     * A monthly batch staff (or the old "Local" generator) made for a month the automatic run now has to send: still a Draft or Ready for Review, never
     * submitted (no outcome, no submission row), not in any approval step. The automatic run takes it over.
     *
     * @param array<string,mixed> $batch
     */
    private function isStaleUnsentManualBatch(array $batch): bool
    {
        if (self::isAutoBatch($batch) || !in_array($batch['status'] ?? '', ['Draft', 'Ready for Review'], true) || !empty($batch['submission_outcome']) || !empty($batch['approval_request_id'])
            || !empty($batch['approved_by']) || !empty($batch['approved_at']) || !empty($batch['rejected_by'])) {
            return false;
        }
        if (!self::isLoanQuestionsKind($batch)) {
            return false; // a full-book batch (e.g. cpl_legacy_extract.php) or anything else of unknown origin is NEVER rebuilt or sent by the automatic run
        }
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM cpl_submissions WHERE batch_id = ?');
        $stmt->execute([(int) $batch['id']]);
        return (int) $stmt->fetchColumn() === 0;
    }

    /** @return array<string,bool> */
    private function reportedSet(): array
    {
        $set = [];
        foreach (Database::connection()->query("SELECT followup_id, report_kind FROM cpl_auto_reported WHERE state = 'Reported' AND report_kind IN ('R', 'C')")->fetchAll() as $r) {
            $set[CplAutoSelector::reportedKey((int) $r['followup_id'], $r['report_kind'])] = true;
        }
        return $set;
    }

    /** @return array<string,mixed>|null an automatic batch of this type whose upload outcome is unknown */
    private function uncertainBatch(string $type): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM cpl_batches WHERE batch_type = ? AND is_uat_test = 0 AND source = 'SolidFin' AND status <> 'Submitted' AND submission_outcome = 'Awaiting Load Report'
               AND (submission_notes LIKE ? OR submission_notes LIKE ?) ORDER BY id DESC"
        );
        $stmt->execute([$type, self::IN_PROGRESS_PREFIX . '%', self::UNCLEAR_PREFIX . '%']);
        foreach ($stmt->fetchAll() as $b) {
            if (self::isUncertain($b)) {
                return $b;
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function heldBecauseUncertain(string $kind, array $batch, string $today, bool $dryRun): array
    {
        $message = 'The automatic ' . $kind . ' upload of ' . $batch['month_end'] . ' (batch #' . $batch['id'] . ') has an unknown outcome, so nothing new is sent until a person confirms whether Creditinfo has it.';
        if (!$dryRun) {
            $this->alert($kind, 'uncertain', $today, 'CPL automatic ' . $kind . ' file: waiting for your confirmation', $message . "\n\nAsk Creditinfo whether they received the file, then open the CPL Dashboard and answer: " . $this->link('/reports/cpl-export/batches'));
        }
        return $this->result($kind, 'uncertain_blocked', $message, $dryRun, ['batch_id' => (int) $batch['id']]);
    }

    // ------------------------------------------------------- dates and names

    public function sendDay(): int
    {
        $d = (int) $this->settings->get('cpl_monthly_send_day', (string) self::DEFAULT_SEND_DAY);
        return $d >= 1 && $d <= 15 ? $d : self::DEFAULT_SEND_DAY;
    }

    /** The first day the monthly file for $monthEnd may be sent: the Nth working day of the following month. */
    public function sendFromDate(string $monthEnd): string
    {
        $day = new \DateTimeImmutable(date('Y-m-01', (int) strtotime($monthEnd . ' +1 day')));
        $count = 0;
        for ($i = 0; $i < 45; $i++) {
            if (($this->isBusinessDay)($day)) {
                $count++;
                if ($count >= $this->sendDay()) {
                    return $day->format('Y-m-d');
                }
            }
            $day = $day->modify('+1 day');
        }
        return $day->format('Y-m-d');
    }

    public static function isRealDate(string $date): bool
    {
        return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** The last day of the month that contains $date. Goes through the FIRST of the month, so a 31st can never skip a short month. */
    public static function monthEnd(string $date): string
    {
        return date('Y-m-t', (int) strtotime(substr($date, 0, 8) . '01'));
    }

    /** The last day of the month after the one $date is in (2018-01-31 -> 2018-02-28, never 2018-03-03). */
    public static function nextMonthEnd(string $date): string
    {
        return date('Y-m-t', (int) strtotime(substr($date, 0, 8) . '01 +1 month'));
    }

    /** The last day of the month before the one $date is in: the latest COMPLETED month end. */
    public static function previousMonthEnd(string $date): string
    {
        return date('Y-m-t', (int) strtotime(substr($date, 0, 8) . '01 -1 month'));
    }

    /**
     * Month ends from $from to $to inclusive, oldest first. $max is only a safety bound (100 years); the per-run cap is applied by doMonthly AFTER the
     * already-Submitted leading months are skipped, never to this list.
     *
     * @return string[]
     */
    public static function monthEnds(string $from, string $to, int $max = 1200): array
    {
        $out = [];
        $m = self::monthEnd($from);
        while ($m <= $to && count($out) < $max) {
            $out[] = $m;
            $m = self::nextMonthEnd($m);
        }
        return $out;
    }

    /**
     * The first month end the monthly catch-up starts from: the month of the first tracked loan's pay-out (its book first contains it). Null when no
     * loan is tracked yet or none was paid out by the latest completed month end.
     *
     * @param array<int, array<string,mixed>>|null $entries null = ask the database
     * @param string[] $excluded
     */
    private function monthlyStart(?array $entries, string $latest, array $excluded): ?string
    {
        $first = null;
        if ($entries === null) {
            $v = Database::connection()->query("SELECT MIN(disbursed_on) FROM loan_followups WHERE status = 'Open' AND approved = 'Yes' AND disbursed = 'Yes' AND disbursed_on IS NOT NULL AND pay_day IS NOT NULL AND approved_amount IS NOT NULL")->fetchColumn();
            $first = $v ? substr((string) $v, 0, 10) : null;
        } else {
            foreach ($this->selector->tracked($entries, $excluded) as $e) {
                $d = (string) $e['followup']['disbursed_on'];
                $first = $first === null || $d < $first ? $d : $first;
            }
        }
        if ($first === null) {
            return null;
        }
        $start = self::monthEnd($first);
        return $start > $latest ? null : $start;
    }

    /** {supplier ref}_{recipient}_L702_{M|D}_{CCYYMMDD}_1_1.txt -- always the LIVE file type, whatever the environment says. */
    public function liveFilename(string $mOrD, string $date): string
    {
        $ref = preg_replace('/[^A-Za-z0-9_-]/', '_', trim($this->settings->supplierReferenceNumber()) ?: 'PENDING');
        $recipient = preg_replace('/[^A-Za-z0-9]/', '', $this->settings->recipient()) ?: 'ALL';
        return $ref . '_' . $recipient . '_L702_' . $mOrD . '_' . str_replace('-', '', $date) . '_1_1.txt';
    }

    // ------------------------------------------------------------- alerting

    /**
     * Counts and a link only: never a name, a loan number or raw error text.
     *
     * @param array{code:string, message:string} $gate
     */
    private function alertHeld(string $kind, array $gate, string $date, string $filename, int $records, int $exceptions, int $batchId): void
    {
        if (in_array($gate['code'], ['nothing_to_report', 'backoff', 'halted', 'refused_instance'], true)) {
            return; // nothing new to say (a halt is e-mailed once when it happens; a demo copy sends no alerts)
        }
        $text = $gate['message'] . "\n\nThe " . $kind . ' file ' . $filename . ' would contain ' . $records . ' record(s)' . ($exceptions > 0 ? '; ' . $exceptions . ' loan(s) cannot be reported yet' : '') . ".\n"
            . "\nSee exactly what would be sent on the CPL Dashboard (batch #" . $batchId . '): ' . $this->link('/reports/cpl-export/batches/' . $batchId) . "\nAutomatic submission is controlled on that page.";
        if ($gate['code'] === 'draft_switch_off') {
            // "the switch is off" is not news every day: say it once per switch state (the day it was last switched)
            $changed = trim($this->settings->get('cpl_auto_switch_changed_at', ''));
            $this->alert($kind, $gate['code'], $date, 'CPL automatic ' . $kind . ' file prepared (switch is OFF)', $text, $changed !== '' ? (int) str_replace('-', '', substr($changed, 0, 10)) : 1);
            return;
        }
        $this->alert($kind, $gate['code'], $date, 'CPL automatic ' . $kind . ' file held back', $text);
    }

    /**
     * One e-mail per person per kind, reason and day (or per $sourceId). Never throws: an alert problem must not undo a run.
     *
     * @return int how many e-mails were queued
     */
    private function alert(string $kind, string $reason, string $date, string $subject, string $message, ?int $sourceId = null): int
    {
        try {
            $table = 'cpl_auto_' . $kind . '_' . $reason;
            $sourceId ??= (int) str_replace('-', '', $date);
            $db = Database::connection();
            $exists = $db->prepare('SELECT 1 FROM notification_queue WHERE user_id = ? AND source_module = ? AND source_table = ? AND source_id = ? LIMIT 1');
            $queue = new NotificationQueue();
            $count = 0;
            $recipients = ($this->recipients)();
            if ($this->recipientsWithEmail($recipients) === []) {
                error_log('CplAutoSubmissionService: no one with an e-mail address is set to receive CPL alerts, so "' . $reason . '" (' . $kind . ') was not e-mailed to anyone.');
                return 0;
            }
            foreach ($recipients as $user) {
                if (trim((string) ($user['email'] ?? '')) === '') {
                    continue;
                }
                $exists->execute([(int) $user['id'], self::SOURCE_MODULE, $table, $sourceId]);
                if ($exists->fetchColumn()) {
                    continue;
                }
                $queue->create([
                    'user_id' => (int) $user['id'],
                    'channel' => 'Email',
                    'recipient_name' => $user['name'],
                    'recipient_contact' => $user['email'],
                    'subject' => mb_substr($subject, 0, 150),
                    'message' => $message,
                    'source_module' => self::SOURCE_MODULE,
                    'source_table' => $table,
                    'source_id' => $sourceId,
                    'status' => 'Pending',
                ]);
                $count++;
            }
            return $count;
        } catch (\Throwable $e) {
            error_log('CplAutoSubmissionService::alert failed: ' . get_class($e));
            return 0;
        }
    }

    /**
     * @param array<int, array<string,mixed>> $users
     * @return array<int, array<string,mixed>>
     */
    private function recipientsWithEmail(array $users): array
    {
        return array_values(array_filter($users, static fn (array $u): bool => trim((string) ($u['email'] ?? '')) !== ''));
    }

    /** Who is e-mailed about this (only people with an e-mail address). @return array<int, array{id:int,name:string,email:string}> */
    public function recipientList(): array
    {
        return $this->recipientsWithEmail(($this->recipients)());
    }

    /**
     * An absolute link for an e-mail, built from the configured public address - NEVER from the request: under cron there is no request, and url() would
     * read the cron script's own SCRIPT_NAME. Order: the CPL setting cpl_public_base_url, then config/app.php 'base_url'. When neither is a usable
     * http(s) address the bare path is returned (never a wrong host).
     */
    public function link(string $path): string
    {
        return $this->publicBaseUrl() . '/' . ltrim($path, '/');
    }

    /** The public base address (no trailing slash), or '' when none is configured. */
    public function publicBaseUrl(): string
    {
        $candidates = [trim($this->settings->get('cpl_public_base_url', ''))];
        try {
            $config = defined('ROOT_PATH') && is_file(ROOT_PATH . '/config/app.php') ? require ROOT_PATH . '/config/app.php' : [];
            $candidates[] = is_array($config) ? trim((string) ($config['base_url'] ?? '')) : '';
        } catch (\Throwable $e) {
            error_log('CplAutoSubmissionService could not read config/app.php: ' . get_class($e));
        }
        foreach ($candidates as $c) {
            if ($c !== '' && preg_match('#^https?://[A-Za-z0-9.\-]+(:\d+)?(/[A-Za-z0-9._~/\-]*)?$#D', $c)) {
                return rtrim($c, '/');
            }
        }
        return '';
    }

    /** @return array<int, array{id:int,name:string,email:string}> */
    private function defaultRecipients(): array
    {
        $users = (new User())->usersWithPermission(self::ALERT_PERMISSION);
        $extra = array_values(array_filter(array_map('intval', preg_split('/[\s,;]+/', $this->settings->get('cpl_auto_alert_user_ids', '')) ?: [])));
        if ($extra !== []) {
            $stmt = Database::connection()->prepare('SELECT id, name, email FROM users WHERE is_active = 1 AND id IN (' . implode(',', array_fill(0, count($extra), '?')) . ')');
            $stmt->execute($extra);
            $users = array_merge($users, $stmt->fetchAll());
        }
        $byId = [];
        foreach ($users as $u) {
            $byId[(int) $u['id']] = $u;
        }
        return array_values($byId);
    }

    private function audit(string $action, string $text, array $meta = []): void
    {
        try {
            Audit::log($action, 'CPL', $text, $meta);
        } catch (\Throwable $e) {
            error_log('CplAutoSubmissionService audit failed: ' . get_class($e));
        }
    }

    /** A message made safe to store or log: plain ASCII, long digit runs (ids, phone numbers) and file paths masked, truncated. */
    public static function safeText(string $text, int $max = 200): string
    {
        $t = trim((string) preg_replace('/\s+/', ' ', AsciiText::clean($text)));
        $t = (string) preg_replace('/\d{6,}/', '#', $t);
        $t = (string) preg_replace('~(?:[A-Za-z]:)?[\\\\/][\w.\-]+(?:[\\\\/][\w.\-]+)+~', '[path]', $t);
        return mb_substr($t, 0, $max);
    }

    /** A fixed phrase for why an upload failed (never the raw exception text). */
    public static function reasonLabel(\Throwable $e): string
    {
        if ($e instanceof CplSftpLoginRefusedException) {
            return 'the server refused the saved login';
        }
        if ($e instanceof CplSftpUncertainException) {
            return 'the connection broke or the write was not confirmed';
        }
        if (stripos($e->getMessage(), 'Could not connect') !== false) {
            return 'could not connect to the server';
        }
        return 'the server or the system refused the upload';
    }

    // ----------------------------------------------------- remembered state

    /** @param array<string,mixed> $result */
    private function remember(string $kind, \DateTimeImmutable $now, string $today, array $result): void
    {
        try {
            $this->settings->set('cpl_auto_last_' . $kind . '_run', json_encode([
                'at' => $now->format('Y-m-d H:i:s'),
                'date' => $today,
                'outcome' => $result['outcome'],
                'message' => mb_substr((string) $result['message'], 0, 500),
                'records' => $result['records'] ?? null,
                'batch_id' => $result['batch_id'] ?? null,
                'file' => $result['file'] ?? null,
            ]), null);
        } catch (\Throwable $e) {
            error_log('CplAutoSubmissionService could not remember the run: ' . get_class($e));
        }
    }

    private function recordFailure(string $kind, string $at, string $message): void
    {
        try {
            $all = json_decode($this->settings->get('cpl_auto_last_failure', ''), true);
            $all = is_array($all) ? $all : [];
            $all[$kind] = ['at' => $at, 'message' => $message];
            $this->settings->set('cpl_auto_last_failure', json_encode($all), null);
        } catch (\Throwable $e) {
            error_log('CplAutoSubmissionService could not remember the failure: ' . get_class($e));
        }
    }

    /** @return array{at:string, message:string}|null */
    private function lastFailure(string $kind): ?array
    {
        $all = json_decode($this->settings->get('cpl_auto_last_failure', ''), true);
        return is_array($all) && isset($all[$kind]['at']) ? $all[$kind] : null;
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function result(string $kind, string $outcome, string $message, bool $dryRun, array $extra = []): array
    {
        return ['kind' => $kind, 'outcome' => $outcome, 'message' => $message, 'dry_run' => $dryRun] + $extra + ['sent' => false];
    }

    // ------------------------------------------------ standing approval, halt

    /** @return array<string,mixed>|null who approved, when, for which destination and which preview */
    public function standingApproval(): ?array
    {
        $a = json_decode($this->settings->get('cpl_auto_standing_approval', ''), true);
        return is_array($a) && !empty($a['user_id']) && !empty($a['at']) ? $a : null;
    }

    /** What a standing approval is bound to: environment, live SFTP switch, supplier, recipient and the SFTP destination (never the password). */
    public function currentFingerprint(): string
    {
        return sha1(implode('|', [
            $this->settings->submissionEnvironment(), $this->settings->get('sftp_submission_enabled', 'off'),
            trim($this->settings->supplierReferenceNumber()), $this->settings->recipient(), $this->sftp->connectionFingerprint(), trim($this->settings->tradingName()),
        ]));
    }

    /** Removes the standing approval (the switch itself is the caller's business). */
    public function revokeStandingApproval(string $reason, ?int $userId): void
    {
        $had = $this->standingApproval() !== null;
        $this->settings->set('cpl_auto_standing_approval', '', $userId);
        if ($had) {
            $this->settings->set('cpl_auto_approval_revoked', json_encode(['at' => ($this->clock)()->format('Y-m-d H:i:s'), 'reason' => mb_substr($reason, 0, 200)]), $userId);
            $this->audit('Update', 'The standing approval of the automatic CPL submission was REVOKED: ' . $reason);
        }
    }

    /**
     * Called after the CPL settings were saved: when the destination the standing approval was given for has changed, the approval is revoked and the
     * switch turned off. @return bool true when it was revoked
     */
    public function revokeIfSettingsChanged(?int $userId): bool
    {
        $a = $this->standingApproval();
        if ($a === null || ($a['fingerprint'] ?? '') === $this->currentFingerprint()) {
            return false;
        }
        $this->revokeStandingApproval('the submission environment, supplier reference, trading name, recipient or SFTP destination was changed', $userId);
        $this->settings->set('cpl_auto_submit_enabled', '0', $userId);
        $this->settings->set('cpl_auto_switch_changed_at', ($this->clock)()->format('Y-m-d H:i:s'), $userId);
        return true;
    }

    /** @return array{at:string, kind:string, reason:string}|null the global hard stop, until a person acknowledges it */
    public function haltedReason(): ?array
    {
        $h = json_decode($this->settings->get('cpl_auto_halted_reason', ''), true);
        return is_array($h) && !empty($h['at']) ? $h : null;
    }

    /**
     * A refused SFTP login: ONE global hard stop. The switch is turned off, the standing approval revoked, one alert goes out, and nothing is retried by
     * any run (daily or monthly) until a person acknowledges it after fixing the SFTP settings. Never retried on a timer.
     */
    private function haltOnLoginRefusal(string $kind, int $batchId, string $at, string $alertDate): void
    {
        try {
            $this->settings->set('cpl_auto_halted_reason', json_encode(['at' => $at, 'kind' => $kind, 'batch_id' => $batchId, 'reason' => 'the SFTP login was refused']), null);
            $this->settings->set('cpl_auto_submit_enabled', '0', null);
            $this->settings->set('cpl_auto_switch_changed_at', $at, null);
            $this->revokeStandingApproval('Creditinfo\'s server refused the SFTP login', null);
        } catch (\Throwable $e) {
            error_log('CplAutoSubmissionService could not record the login halt: ' . get_class($e));
        }
        $this->audit('Update', 'Automatic CPL submission HALTED: the SFTP login was refused (batch #' . $batchId . '); the switch was turned off', ['batch_id' => $batchId, 'outcome' => 'halted']);
        $this->alert('sftp', 'login_refused', $alertDate, 'CPL automatic submission STOPPED: the SFTP login was refused', "Creditinfo's SFTP server refused the saved login while the " . $kind . " file was being sent. Nothing was sent.\n\nAutomatic submission is switched OFF and will not try again - repeated wrong logins can lock the account. Ask your administrator to check the SFTP user name and password in Public Defaults Settings, then acknowledge this on the CPL Dashboard and turn automatic submission on again.\n\nCPL Dashboard: " . $this->link('/reports/cpl-export/batches'), (int) str_replace('-', '', substr($at, 0, 10)));
    }

    /** A person confirms the SFTP settings were fixed: the hard stop is lifted. The switch stays OFF; turning it on again is a new approval. */
    public function acknowledgeHalt(?int $userId): bool
    {
        if ($this->haltedReason() === null) {
            return false;
        }
        $this->settings->set('cpl_auto_halted_reason', '', $userId);
        $this->audit('Update', 'Acknowledged the automatic CPL submission halt (SFTP login refusal); the switch stays OFF until turned on again');
        return true;
    }

    // ------------------------------------------------------- cron heartbeats

    /** @return array{daily:?string, monthly:?string} */
    private function loadHeartbeats(): array
    {
        $out = ['daily' => null, 'monthly' => null];
        try {
            $rows = Database::connection()->query("SELECT job_key, last_run_at FROM scheduled_job_heartbeats WHERE job_key IN ('cpl_auto_submit_daily', 'cpl_auto_submit_monthly')")->fetchAll();
            foreach ($rows as $r) {
                $out[$r['job_key'] === 'cpl_auto_submit_daily' ? 'daily' : 'monthly'] = (string) $r['last_run_at'];
            }
        } catch (\Throwable $e) {
            error_log('CplAutoSubmissionService could not read the job heartbeats: ' . get_class($e));
        }
        return $out;
    }

    /**
     * Has each scheduled job reported in the last 30 hours? 'problem' = the switch is ON and one of them has not (or never did).
     *
     * @return array{daily: array<string,mixed>, monthly: array<string,mixed>, problem: bool, any_missing: bool}
     */
    public function cronHealth(): array
    {
        $now = ($this->clock)();
        $beats = ($this->heartbeats)();
        $out = [];
        $anyBad = false;
        $anyMissing = false;
        foreach (['daily', 'monthly'] as $k) {
            $last = $beats[$k] ?? null;
            $ts = $last ? strtotime($last) : false;
            $age = $ts !== false ? ($now->getTimestamp() - $ts) / 3600 : null;
            $stale = $age === null || $age > self::HEARTBEAT_STALE_HOURS;
            $anyBad = $anyBad || $stale;
            $anyMissing = $anyMissing || $age === null;
            $out[$k] = ['last' => $ts !== false ? $last : null, 'age_hours' => $age === null ? null : round($age, 1), 'stale' => $stale];
        }
        return $out + ['problem' => $this->isEnabled() && $anyBad, 'any_missing' => $anyMissing];
    }

    /**
     * Called by the scheduled jobs themselves (and by the health check job, which keeps running when these do not): e-mails the staff, once a day, when the
     * switch is ON and a CPL job has not reported for more than 30 hours. A switch turned on less than 30 hours ago has had no time to run yet and is not
     * reported for a job that never reported.
     *
     * @return bool true when there is a problem
     */
    public function watchCron(): bool
    {
        $h = $this->cronHealth();
        if (!$h['problem']) {
            return false;
        }
        $now = ($this->clock)();
        $enabledAt = strtotime($this->settings->get('cpl_auto_enabled_at', ''));
        $fresh = $enabledAt !== false && $enabledAt > $now->getTimestamp() - self::HEARTBEAT_STALE_HOURS * 3600;
        if ($fresh && $h['any_missing']) {
            return true;
        }
        $part = static fn (array $x): string => $x['last'] === null ? 'never reported' : 'last reported ' . $x['last'];
        $this->alert('cron', 'heartbeat_stale', $now->format('Y-m-d'), 'CPL automatic submission: the scheduled job is not running', 'Automatic CPL submission is ON, but a scheduled job has not reported in over ' . self::HEARTBEAT_STALE_HOURS . ' hours (daily job: ' . $part($h['daily']) . '; monthly job: ' . $part($h['monthly']) . ").\n\nNothing is being sent. Ask the technical team to check the crontab.\n\nCPL Dashboard: " . $this->link('/reports/cpl-export/batches'));
        return true;
    }

    // ------------------------------------------------- dashboard + switching

    /** The default source of data: every paid-out Loan Questions loan with its application, instalments and payments. @return array<int, array<string,mixed>> */
    public function loadEntries(): array
    {
        $model = new LoanFollowup();
        $instalments = $model->instalmentsByFollowup();
        $payments = $model->paymentsByInstalment();
        $entries = [];
        foreach ($model->disbursedWithApplications() as $f) {
            $app = $model->application((int) $f['id']);
            if ($app) {
                $entries[] = ['followup' => $f, 'application' => $app, 'instalments' => $instalments[(int) $f['id']] ?? [], 'payments' => $payments];
            }
        }
        return $entries;
    }

    /**
     * Everything the dashboard panel shows.
     *
     * @return array<string,mixed>
     */
    public function panel(int $autoPage = 1): array
    {
        $db = Database::connection();
        $now = ($this->clock)();
        $today = $now->format('Y-m-d');
        $json = fn (string $key): ?array => (($d = json_decode($this->settings->get($key, ''), true)) && is_array($d)) ? $d : null;

        $tracked = (int) $db->query("SELECT COUNT(*) FROM loan_followups WHERE status = 'Open' AND approved = 'Yes' AND disbursed = 'Yes' AND disbursed_on IS NOT NULL AND pay_day IS NOT NULL AND approved_amount IS NOT NULL")->fetchColumn();
        $open = (int) $db->query("SELECT COUNT(*) FROM loan_followups WHERE status = 'Open'")->fetchColumn();
        $unsent = $db->query("SELECT id, batch_type, month_end, status, total_records, blocking_error_count FROM cpl_batches
                              WHERE is_uat_test = 0 AND source = 'SolidFin' AND status IN ('Draft', 'Ready for Review') AND submission_outcome IS NULL
                                AND legacy_summary LIKE '%\"origin\":\"Automatic%' ORDER BY month_end DESC LIMIT 10")->fetchAll();
        $uncertain = [];
        foreach (['Daily', 'Monthly'] as $type) {
            if (($u = $this->uncertainBatch($type)) !== null) {
                $uncertain[] = $u;
            }
        }

        $next = $this->nextUnsentMonthEnd($today);

        $perPage = 30;
        $autoTotal = $this->batches->countAutoFiles();
        $autoPages = max(1, (int) ceil($autoTotal / $perPage));
        $autoPage = max(1, min($autoPage, $autoPages));

        $approval = $this->standingApproval();
        $approverName = '';
        if ($approval !== null) {
            $stmt = $db->prepare('SELECT name FROM users WHERE id = ?');
            $stmt->execute([(int) $approval['user_id']]);
            $approverName = (string) ($stmt->fetchColumn() ?: '');
        }

        return [
            'enabled' => $this->isEnabled(),
            'restricted_instance' => $this->isRestrictedInstance(),
            'production' => $this->settings->isProductionEnvironment(),
            'sftp_ready' => $this->sftp->isReady(),
            'last_daily' => $json('cpl_auto_last_daily_run'),
            'last_monthly' => $json('cpl_auto_last_monthly_run'),
            'last_success' => $json('cpl_auto_last_success'),
            'next_daily' => 'once a day, when the scheduled job runs (see the crontab; the last run is shown above)',
            'next_monthly_month_end' => $next,
            'next_monthly_due' => $this->sendFromDate($next),
            'tracked_loans' => $tracked,
            'awaiting_answer' => max(0, $open - $tracked),
            'unsent' => $unsent,
            'uncertain' => $uncertain,
            'enabled_by' => $this->settings->get('cpl_auto_enabled_by', ''),
            'enabled_at' => $this->settings->get('cpl_auto_enabled_at', ''),
            'since' => trim($this->settings->get('cpl_auto_daily_since', '')),
            'coverage_from' => LoanFollowup::TRACK_FROM,
            'warning' => self::COMPLETENESS_WARNING,
            'send_day' => $this->sendDay(),
            'standing_approval' => $approval === null ? null : $approval + ['user_name' => $approverName],
            'approval_revoked' => $json('cpl_auto_approval_revoked'),
            'halted' => $this->haltedReason(),
            'cron' => $this->cronHealth(),
            'recipients' => $this->recipientList(),
            'auto_files' => $this->batches->autoFiles($perPage, ($autoPage - 1) * $perPage),
            'auto_total' => $autoTotal,
            'auto_page' => $autoPage,
            'auto_pages' => $autoPages,
        ];
    }

    /** The oldest completed month end that has not been sent yet (the one the next monthly run works on); the latest one when everything is sent. */
    public function nextUnsentMonthEnd(string $today): string
    {
        $latest = self::previousMonthEnd($today);
        $start = $this->monthlyStart(null, $latest, CplAutoSelector::parseExcludedNames($this->settings->get('cpl_auto_excluded_names', '')));
        if ($start === null) {
            return $latest;
        }
        foreach (self::monthEnds($start, $latest) as $m) {
            $b = $this->batches->findByTypeAndMonth('Monthly', $m, false);
            if (!$b || ($b['status'] ?? '') !== 'Submitted') {
                return $m;
            }
        }
        return self::nextMonthEnd($latest);
    }

    /**
     * What the first files would hold if the switch were on right now (shown on the confirmation page, and recorded in the standing approval as the
     * size the person approved). Read-only.
     *
     * @return array{registrations:int, closures:int, blocked:int, oldest_paid_out:?string, daily_records:int, monthly_records:int, monthly_months:int}
     */
    public function firstFilePreview(?string $since = null): array
    {
        $now = ($this->clock)();
        $today = $now->format('Y-m-d');
        $entries = ($this->entriesLoader)();
        $excluded = CplAutoSelector::parseExcludedNames($this->settings->get('cpl_auto_excluded_names', ''));
        $sel = $this->selector->daily($entries, $this->reportedSet(), $today, ($since !== null && $since !== '') ? $since : null, $excluded);
        $oldest = null;
        foreach ($sel['records'] as $r) {
            if ($r['kind'] === 'R' && ($oldest === null || $r['event_date'] < $oldest)) {
                $oldest = $r['event_date'];
            }
        }

        // the monthly files that would be sent: the size of the biggest (latest) one, and how many months are waiting
        $latest = self::previousMonthEnd($today);
        $monthlyRecords = 0;
        $months = 0;
        $start = $this->monthlyStart($entries, $latest, $excluded);
        if ($start !== null) {
            foreach (self::monthEnds($start, $latest) as $m) {
                $b = $this->batches->findByTypeAndMonth('Monthly', $m, false);
                if ($b && ($b['status'] ?? '') === 'Submitted') {
                    continue;
                }
                $months++;
            }
            try {
                $built = $this->selector->monthly($entries, $latest, $now->format('Y-m-d H:i:s'), trim($this->settings->supplierReferenceNumber()), $this->settings->tradingName(), $excluded);
                $monthlyRecords = (int) $built['result']['records'];
            } catch (\Throwable $e) {
                error_log('CplAutoSubmissionService preview of the monthly file failed: ' . get_class($e));
            }
        }
        return [
            'registrations' => $sel['registrations'], 'closures' => $sel['closures'], 'blocked' => count($sel['exceptions']), 'oldest_paid_out' => $oldest,
            'daily_records' => count($sel['records']), 'monthly_records' => $monthlyRecords, 'monthly_months' => $months,
        ];
    }

    /**
     * What a person approves when they press "turn on": the preview is measured HERE, on the server, again at that moment. The numbers posted by the page
     * are only used to notice that the page is stale (a "stale" answer means: show the page again with fresh numbers instead of enabling). Posted
     * numbers are never stored.
     *
     * @param array{daily?:mixed, monthly?:mixed}|null $posted what the page showed (null/incomplete counts as different)
     * @return array{preview: array{daily:int, monthly:int}, stale: bool}
     */
    public function previewForEnable(?string $since, ?array $posted): array
    {
        $since = ($since !== null && self::isRealDate($since)) ? $since : null;
        $m = $this->firstFilePreview($since);
        $measured = ['daily' => (int) $m['daily_records'], 'monthly' => (int) $m['monthly_records']];
        return ['preview' => $measured, 'stale' => self::previewDiffers($posted, $measured)];
    }

    /** true when the posted preview is missing or differs from the measured one by more than 10% (of the measured number) in daily or monthly records. */
    public static function previewDiffers(?array $posted, array $measured): bool
    {
        foreach (['daily', 'monthly'] as $k) {
            if (!isset($posted[$k]) || !is_numeric($posted[$k])) {
                return true;
            }
            if (abs((int) $posted[$k] - (int) $measured[$k]) > 0.10 * (int) $measured[$k]) {
                return true;
            }
        }
        return false;
    }

    /**
     * Everything that currently stops the switch from being turned on (shown on the confirmation page).
     *
     * @return string[]
     */
    public function enableBlockers(?int $userId = null): array
    {
        $out = [];
        if ($this->isRestrictedInstance()) {
            $out[] = 'This is a demo or sandbox copy of the system. It never sends live files to Creditinfo, so automatic submission cannot be turned on here.';
        }
        if ($this->haltedReason() !== null) {
            $out[] = 'Automatic submission was stopped because the SFTP login was refused. Fix the SFTP settings and acknowledge the stop on the CPL Dashboard first.';
        }
        if ($this->recipientList() === [] && !$this->fallbackRecipient($userId)) {
            $out[] = 'Nobody with an e-mail address is set to receive the CPL alerts (everybody who may use the CPL reports, plus the extra user ids in CPL Settings). Alerts would go nowhere.';
        }
        return $out;
    }

    /** @return array{id:int,name:string,email:string}|null the person turning it on, when they have an e-mail address */
    private function fallbackRecipient(?int $userId): ?array
    {
        if (!$userId) {
            return null;
        }
        $stmt = Database::connection()->prepare("SELECT id, name, email FROM users WHERE id = ? AND is_active = 1 AND email IS NOT NULL AND email <> ''");
        $stmt->execute([$userId]);
        $u = $stmt->fetch();
        return $u ?: null;
    }

    /**
     * Switches automatic submission on or off. Turning it on is the STANDING APPROVAL of the automatic path (the maker-checker control for CPL batches is not
     * bypassed silently): it needs the acknowledgements below, is recorded with who and when, and is revoked by turning the switch off or by changing the
     * environment or the SFTP destination.
     *
     * @param array{daily?:int, monthly?:int}|null $preview the record counts of the preview the person looked at (null = measured now)
     * @throws \RuntimeException when turning on is not allowed or the acknowledgements are missing
     */
    public function setEnabled(bool $on, ?int $userId, bool $acknowledgedCompleteness = false, ?string $since = null, bool $acknowledgedStandingApproval = false, ?array $preview = null, bool $acknowledgedNoHeartbeat = false): void
    {
        $at = ($this->clock)()->format('Y-m-d H:i:s');
        if (!$on) {
            $this->settings->set('cpl_auto_submit_enabled', '0', $userId);
            $this->settings->set('cpl_auto_switch_changed_at', $at, $userId);
            $this->revokeStandingApproval('the switch was turned off', $userId);
            return;
        }

        if ($this->isRestrictedInstance()) {
            throw new \RuntimeException('This is a demo or sandbox copy of the system; automatic submission to Creditinfo cannot be turned on here.');
        }
        if ($this->haltedReason() !== null) {
            throw new \RuntimeException('Automatic submission was stopped because the SFTP login was refused. Fix the SFTP settings and acknowledge the stop on the CPL Dashboard before turning it on again.');
        }
        if (!$acknowledgedCompleteness) {
            throw new \RuntimeException('Tick the box to confirm you understand the monthly file is not the full historic loan book.');
        }
        if (!$acknowledgedStandingApproval) {
            throw new \RuntimeException('Tick the box to give the standing approval: from now on live files are uploaded by the scheduled job without a second person approving each one.');
        }
        if ($since !== null && $since !== '' && !self::isRealDate($since)) {
            throw new \RuntimeException('The "only loans paid out on or after" date must be a real date.');
        }
        if ($this->recipientList() === []) {
            $fallback = $this->fallbackRecipient($userId);
            if ($fallback === null) {
                throw new \RuntimeException('Nobody with an e-mail address would receive the alerts. Give a user an e-mail address, or list one in "Also e-mail these user ids" in CPL Settings, first.');
            }
            $ids = trim($this->settings->get('cpl_auto_alert_user_ids', ''));
            $this->settings->set('cpl_auto_alert_user_ids', $ids === '' ? (string) $fallback['id'] : $ids . ', ' . $fallback['id'], $userId);
            error_log('CplAutoSubmissionService: nobody was set to receive the alerts, so the person turning it on was added to cpl_auto_alert_user_ids.');
        }
        if ($this->cronHealth()['any_missing'] && !$acknowledgedNoHeartbeat) {
            throw new \RuntimeException('The scheduled CPL job has never reported in. Install the cron job first (see the crontab lines on this page), or tick the extra box to confirm you know nothing will run until it is installed.');
        }

        if ($preview === null) {
            $measured = $this->firstFilePreview($since);
            $preview = ['daily' => $measured['daily_records'], 'monthly' => $measured['monthly_records']];
        }

        $this->settings->set('cpl_auto_daily_since', $since ?? '', $userId);
        $this->settings->set('cpl_auto_enabled_by', (string) ($userId ?? ''), $userId);
        $this->settings->set('cpl_auto_enabled_at', $at, $userId);
        $this->settings->set('cpl_auto_switch_changed_at', $at, $userId);
        $this->settings->set('cpl_auto_standing_approval', json_encode([
            'user_id' => (int) $userId,
            'at' => $at,
            'fingerprint' => $this->currentFingerprint(),
            'preview' => ['daily' => (int) ($preview['daily'] ?? 0), 'monthly' => (int) ($preview['monthly'] ?? 0)],
            'since' => (string) ($since ?? ''),
        ]), $userId);
        $this->settings->set('cpl_auto_approval_revoked', '', $userId);
        $this->settings->set('cpl_auto_submit_enabled', '1', $userId);
    }
}
