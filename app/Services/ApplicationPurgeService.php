<?php

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\NotificationQueue;
use App\Models\SystemSetting;
use App\Models\User;

/**
 * Deletes applications that were never approved, N days after their last
 * status change, after warning staff first. No per-deletion approval is
 * needed (staff are alerted, and can click "Keep"). Built so that a mistake
 * can only ever under-delete:
 *
 *  - Off by default (system_settings.application_purge_enabled = 0): the cron
 *    then only REPORTS what it would do, and sends no warnings.
 *  - Only Submitted/Screening/Documents Required/Rejected/Cancelled; never
 *    Approved/Converted, never anything linked to a borrower or a loan, never
 *    under a legal hold (on the application or on any of its child records).
 *  - Loan Questions guard: loans are approved in the legacy system, so a
 *    "Submitted" application may be a real paid-out loan. Never deleted if a
 *    loan question says approved/disbursed. An OPEN-status application is
 *    deleted only when staff answered "not approved, not going to approve";
 *    anything with a missing or unanswered question is only FLAGGED to staff.
 *  - Warn first: deletion needs purge_warned_at older than warning_days, newer
 *    than the last status change, AND a warning e-mail that was really Sent.
 *  - Creditinfo consent / report-cache rows must never be deleted (audit
 *    record), so such applications are "tombstoned": all personal data wiped,
 *    the row kept with purged_at set.
 *  - Files are first moved to storage/purged/<date>/ (quarantine) and only
 *    really deleted after application_purge_quarantine_days, because the
 *    database backup does not contain uploaded files.
 */
final class ApplicationPurgeService
{
    public const OPEN_STATUSES = ['Submitted', 'Screening', 'Documents Required'];
    public const CLOSED_STATUSES = ['Rejected', 'Cancelled'];
    public const SOURCE_MODULE = 'Application Purge';

    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    public static function setting(string $key, string $default): string
    {
        return (string) ((new SystemSetting())->get($key, $default) ?? $default);
    }

    public static function enabled(): bool
    {
        return self::setting('application_purge_enabled', '0') === '1';
    }

    /** Never less than warning + 7, so nobody can type 0 and wipe everything on the next run. */
    public static function retentionDays(): int
    {
        return max((int) self::setting('application_retention_days', '30'), self::warningDays() + 7);
    }

    public static function warningDays(): int
    {
        return max(1, (int) self::setting('application_purge_warning_days', '7'));
    }

    public static function quarantineDays(): int
    {
        return max(1, (int) self::setting('application_purge_quarantine_days', '14'));
    }

    /** SQL fragment: the age anchor. NOT updated_at (it moves on every edit). */
    private const ANCHOR = "COALESCE((SELECT MAX(h.changed_at) FROM loan_application_status_history h WHERE h.application_id = a.id), a.created_at)";

    /** An unanswered / not-definitely-"no" loan question (or none at all for an open-status application). */
    private static function unsettledQuestionSql(): string
    {
        $open = "'" . implode("','", self::OPEN_STATUSES) . "'";
        return "(
            EXISTS (SELECT 1 FROM loan_followups f2 WHERE f2.application_id = a.id AND f2.status = 'Open'
                    AND NOT COALESCE(f2.approved = 'No' AND f2.plans_to_approve = 'No', 0))
            OR (a.status IN ($open) AND NOT EXISTS (SELECT 1 FROM loan_followups f3 WHERE f3.application_id = a.id AND f3.approved = 'No' AND f3.plans_to_approve = 'No'))
        )";
    }

    /** Active legal hold on the application or on any of its child records. */
    private static function heldSql(): string
    {
        $h = "SELECT 1 FROM legal_holds lh WHERE lh.is_active = 1 AND ";
        return "(
            EXISTS ($h lh.resource_table = 'loan_applications' AND lh.resource_id = a.id)
            OR EXISTS ($h lh.resource_table = 'loan_application_documents' AND lh.resource_id IN (SELECT d.id FROM loan_application_documents d WHERE d.application_id = a.id))
            OR EXISTS ($h lh.resource_table = 'generated_documents' AND lh.resource_id IN (SELECT g.id FROM generated_documents g WHERE g.application_id = a.id))
            OR EXISTS ($h lh.resource_table = 'loan_followups' AND lh.resource_id IN (SELECT f.id FROM loan_followups f WHERE f.application_id = a.id))
            OR EXISTS ($h lh.resource_table = 'credit_bureau_consents' AND lh.resource_id IN (SELECT c.id FROM credit_bureau_consents c WHERE c.application_id = a.id))
            OR EXISTS ($h lh.resource_table = 'creditinfo_report_cache' AND lh.resource_id IN (SELECT k.id FROM creditinfo_report_cache k WHERE k.application_id = a.id))
            OR EXISTS ($h lh.resource_table = 'creditinfo_report_content' AND lh.resource_id IN (SELECT cc.id FROM creditinfo_report_content cc JOIN creditinfo_report_cache k2 ON k2.id = cc.report_cache_id WHERE k2.application_id = a.id))
        )";
    }

    /** SQL fragment: every rule that makes an application eligible regardless of age. */
    private static function eligibleWhere(): string
    {
        $open = "'" . implode("','", self::OPEN_STATUSES) . "'";
        $closed = "'" . implode("','", self::CLOSED_STATUSES) . "'";
        return "a.purged_at IS NULL
            AND a.borrower_id IS NULL
            AND a.status IN ($open, $closed)
            AND NOT EXISTS (SELECT 1 FROM loans l WHERE l.application_id = a.id)
            AND NOT EXISTS (SELECT 1 FROM creditinfo_public_defaults d WHERE d.loan_application_id = a.id)
            AND NOT " . self::heldSql() . "
            AND NOT EXISTS (SELECT 1 FROM loan_followups f WHERE f.application_id = a.id AND (f.approved = 'Yes' OR f.disbursed = 'Yes'))
            AND NOT " . self::unsettledQuestionSql();
    }

    /** A warning e-mail covering this application was really delivered (status Sent), around the time it was stamped. */
    private const WARNING_SENT = "EXISTS (SELECT 1 FROM notification_queue q WHERE q.source_module = 'Application Purge' AND q.source_table = 'application_purge_warning'
            AND q.status = 'Sent' AND q.created_at >= (a.purge_warned_at - INTERVAL 1 DAY) AND q.created_at <= (a.purge_warned_at + INTERVAL 1 DAY))";

    /** Old applications held back ONLY by an unanswered / missing Loan Question: staff must answer it (flagged, never deleted). */
    public function blockedByLoanQuestion(?int $days = null): array
    {
        $days = $days ?? self::retentionDays();
        $open = "'" . implode("','", self::OPEN_STATUSES) . "'";
        $closed = "'" . implode("','", self::CLOSED_STATUSES) . "'";
        $sql = "SELECT a.id, a.application_no, a.status, " . self::ANCHOR . " AS anchor_at
                FROM loan_applications a
                WHERE a.purged_at IS NULL AND a.borrower_id IS NULL AND a.status IN ($open, $closed)
                  AND " . self::ANCHOR . " <= (NOW() - INTERVAL $days DAY)
                  AND NOT EXISTS (SELECT 1 FROM loans l WHERE l.application_id = a.id)
                  AND NOT EXISTS (SELECT 1 FROM loan_followups f WHERE f.application_id = a.id AND (f.approved = 'Yes' OR f.disbursed = 'Yes'))
                  AND " . self::unsettledQuestionSql() . "
                ORDER BY anchor_at";
        return $this->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Eligible applications older than $days since their last status change.
     * With $mustBeWarned only those whose (delivered) warning is old enough and postdates the last status change.
     * @return array<int, array<string,mixed>>
     */
    public function eligible(?int $days = null, bool $mustBeWarned = false, ?int $limit = null): array
    {
        $days = $days ?? self::retentionDays();
        $warn = self::warningDays();
        $sql = "SELECT a.id, a.application_no, a.status, a.purge_warned_at,
                       " . self::ANCHOR . " AS anchor_at,
                       DATE_ADD(" . self::ANCHOR . ", INTERVAL $days DAY) AS due_at
                FROM loan_applications a
                WHERE " . self::eligibleWhere() . "
                  AND " . self::ANCHOR . " <= (NOW() - INTERVAL $days DAY)";
        if ($mustBeWarned) {
            $sql .= " AND a.purge_warned_at IS NOT NULL AND a.purge_warned_at <= (NOW() - INTERVAL $warn DAY)
                      AND a.purge_warned_at >= " . self::ANCHOR . " AND " . self::WARNING_SENT;
        }
        $sql .= ' ORDER BY anchor_at' . ($limit ? ' LIMIT ' . (int) $limit : '');
        return $this->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Applications that will be deleted within the warning window (staff must be told). */
    public function dueSoon(): array
    {
        $days = self::retentionDays();
        $warn = self::warningDays();
        $sql = "SELECT a.id, a.application_no, a.status, a.purge_warned_at,
                       " . self::ANCHOR . " AS anchor_at,
                       DATE_ADD(" . self::ANCHOR . ", INTERVAL $days DAY) AS due_at
                FROM loan_applications a
                WHERE " . self::eligibleWhere() . "
                  AND " . self::ANCHOR . " <= (NOW() - INTERVAL " . ($days - $warn) . " DAY)
                ORDER BY anchor_at";
        return $this->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Phase 0: anything warned that is no longer eligible, or whose last status change is newer than the warning, loses its stamp. */
    public function clearStaleWarnings(): int
    {
        $days = self::retentionDays();
        $warn = self::warningDays();
        $stmt = $this->db->prepare("UPDATE loan_applications a SET a.purge_warned_at = NULL
            WHERE a.purge_warned_at IS NOT NULL AND a.purged_at IS NULL
              AND (NOT (" . self::eligibleWhere() . " AND " . self::ANCHOR . " <= (NOW() - INTERVAL " . ($days - $warn) . " DAY))
                   OR a.purge_warned_at < " . self::ANCHOR . ")");
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Phase 1: one digest e-mail per recipient per day, then stamp purge_warned_at (only when a digest was queued).
     * At most 50 applications per run get a first warning (a big backlog is worked off over several days).
     * @return array{warned:int, emails:int}
     */
    public function warn(): array
    {
        $due = array_values(array_filter($this->dueSoon(), fn ($r) => $r['purge_warned_at'] === null));
        $due = array_slice($due, 0, 50);
        $blocked = $this->blockedByLoanQuestion();
        if (!$due && !$blocked) {
            return ['warned' => 0, 'emails' => 0];
        }
        $recipients = [];
        $userModel = new User();
        foreach (['applications.screen', 'loans.followups'] as $perm) {
            foreach ($userModel->usersWithPermission($perm) as $u) {
                if (!empty($u['email'])) {
                    $recipients[(int) $u['id']] = $u;
                }
            }
        }
        $earliest = date('Y-m-d', strtotime('+' . self::warningDays() . ' days'));
        $lines = [];
        foreach (array_slice($due, 0, 30) as $r) {
            $when = max(substr((string) $r['due_at'], 0, 10), $earliest);
            $lines[] = $r['application_no'] . ' (' . $r['status'] . ') will be deleted on or after ' . $when;
        }
        if (count($due) > 30) {
            $lines[] = '...and ' . (count($due) - 30) . ' more.';
        }
        $body = '';
        if ($due) {
            $body .= "These applications were never approved and will be deleted (with their documents) to save space:\n\n" . implode("\n", $lines)
                . "\n\nIf any of them should be kept, open the application and click \"Keep it\" before that date. Nobody has to approve the deletion.\n";
        }
        if ($blocked) {
            $body .= "\nThese old applications are NOT being deleted because their Loan Question is unanswered or missing. Please answer it in Loan Questions (Approved? Yes/No) so they can be cleaned up or kept correctly: "
                . implode(', ', array_slice(array_column($blocked, 'application_no'), 0, 30)) . (count($blocked) > 30 ? ' and ' . (count($blocked) - 30) . ' more' : '') . ".\n";
        }

        $queue = new NotificationQueue();
        $exists = $this->db->prepare('SELECT 1 FROM notification_queue WHERE user_id = ? AND source_module = ? AND source_table = ? AND source_id = ? LIMIT 1');
        $day = (int) date('Ymd');
        $emails = 0;
        foreach ($recipients as $u) {
            $exists->execute([$u['id'], self::SOURCE_MODULE, 'application_purge_warning', $day]);
            if ($exists->fetchColumn()) {
                continue;
            }
            $queue->create([
                'user_id' => $u['id'],
                'channel' => 'Email',
                'recipient_name' => $u['name'],
                'recipient_contact' => $u['email'],
                'subject' => 'Applications that will be deleted to free space',
                'message' => $body,
                'source_module' => self::SOURCE_MODULE,
                'source_table' => 'application_purge_warning',
                'source_id' => $day,
                'status' => 'Pending',
            ]);
            $emails++;
        }

        $warned = 0;
        if ($due && $emails > 0) { // only stamp when a digest naming them was just queued
            $stamp = $this->db->prepare('UPDATE loan_applications SET purge_warned_at = NOW() WHERE id = ? AND purge_warned_at IS NULL');
            foreach ($due as $r) {
                $stamp->execute([(int) $r['id']]);
                $warned += $stamp->rowCount();
            }
        }
        Audit::log('Update', 'Applications', 'Warned staff that ' . $warned . ' unapproved application(s) will be deleted', ['recipients' => count($recipients)]);
        return ['warned' => $warned, 'emails' => $emails];
    }

    /** Dry-run: exactly what purge() would do, writing nothing. */
    public function plan(int $id): array
    {
        $count = function (string $sql, array $p = []) {
            $s = $this->db->prepare($sql);
            $s->execute($p);
            return (int) $s->fetchColumn();
        };
        $files = $this->collectFiles($id);
        $bytes = 0;
        foreach ($files as $f) {
            $bytes += is_file($f) ? (int) filesize($f) : 0;
        }
        $keep = $count('SELECT (SELECT COUNT(*) FROM credit_bureau_consents WHERE application_id = ?) + (SELECT COUNT(*) FROM creditinfo_report_cache WHERE application_id = ?)', [$id, $id]) > 0;
        return [
            'mode' => $keep ? 'tombstone (personal data wiped, row kept: Creditinfo record must be kept)' : 'delete',
            'documents' => $count('SELECT COUNT(*) FROM loan_application_documents WHERE application_id = ?', [$id]),
            'generated_documents' => $count('SELECT COUNT(*) FROM generated_documents WHERE application_id = ?', [$id]),
            'loan_questions' => $count('SELECT COUNT(*) FROM loan_followups WHERE application_id = ?', [$id]),
            'files' => count($files),
            'bytes' => $bytes,
        ];
    }

    /** @return array{ok:bool, mode:string, files:int, bytes:int, reason?:string} */
    public function purge(int $id): array
    {
        $files = [];
        $mode = 'delete';
        $app = null;
        try {
            $this->db->beginTransaction();
            $row = $this->db->prepare('SELECT a.* FROM loan_applications a WHERE a.id = ? FOR UPDATE');
            $row->execute([$id]);
            $app = $row->fetch(\PDO::FETCH_ASSOC);
            $days = self::retentionDays();
            $warn = self::warningDays();
            $still = $app ? $this->db->prepare("SELECT 1 FROM loan_applications a WHERE a.id = ? AND " . self::eligibleWhere() . " AND " . self::ANCHOR . " <= (NOW() - INTERVAL $days DAY)
                AND a.purge_warned_at IS NOT NULL AND a.purge_warned_at <= (NOW() - INTERVAL $warn DAY) AND a.purge_warned_at >= " . self::ANCHOR . " AND " . self::WARNING_SENT) : null;
            if ($still) {
                $still->execute([$id]);
            }
            if (!$app || !$still || !$still->fetchColumn()) {
                $this->db->rollBack();
                return ['ok' => false, 'mode' => '', 'files' => 0, 'bytes' => 0, 'reason' => 'no longer eligible'];
            }

            $files = $this->collectFiles($id, $app['application_no']);
            $dirs = $this->dirs((string) $app['application_no']);

            // Creditinfo report content is purgeable; the cache/consent rows themselves are an audit record.
            $this->db->prepare('UPDATE creditinfo_report_cache SET purged_at = NOW() WHERE application_id = ? AND purged_at IS NULL')->execute([$id]);
            $this->db->prepare('DELETE c FROM creditinfo_report_content c JOIN creditinfo_report_cache k ON k.id = c.report_cache_id WHERE k.application_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM generated_documents WHERE application_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM loan_followups WHERE application_id = ?')->execute([$id]);
            // Any queued/sent message about this application (staff alerts, acknowledgements, correction texts).
            $this->db->prepare("DELETE FROM notification_queue WHERE source_id = ? AND source_table IN ('loan_applications','sms_new_application','sms_approved')")->execute([$id]);
            $this->db->prepare("DELETE FROM notification_queue WHERE source_id = ? AND source_module = 'Applicant SMS'")->execute([$id]);

            $keepRecord = (int) $this->db->query('SELECT (SELECT COUNT(*) FROM credit_bureau_consents WHERE application_id = ' . (int) $id . ') + (SELECT COUNT(*) FROM creditinfo_report_cache WHERE application_id = ' . (int) $id . ')')->fetchColumn() > 0;
            if ($keepRecord) {
                $mode = 'tombstone';
                foreach (['loan_application_documents', 'loan_application_bank_analysis', 'loan_application_screening', 'rejected_applications', 'online_application_otp'] as $t) {
                    $this->db->prepare("DELETE FROM $t WHERE application_id = ?")->execute([$id]);
                }
                $this->db->prepare('UPDATE loan_application_status_history SET notes = NULL WHERE application_id = ?')->execute([$id]);
                $this->db->prepare("UPDATE loan_applications SET requested_purpose = NULL, applicant_first_name = NULL, applicant_middle_name = NULL, applicant_last_name = NULL,
                    applicant_id_number = NULL, applicant_phone = NULL, applicant_email = NULL, applicant_address = NULL, employer_name = NULL, employee_no = NULL,
                    gross_salary = 0, net_salary = 0, bank_name = NULL, bank_account_name = NULL, bank_account_number = NULL, bank_branch_code = NULL,
                    rejection_reason = NULL, ip_address = NULL, user_agent = NULL, extra_data = NULL, purged_at = NOW(), purge_warned_at = NULL WHERE id = ?")->execute([$id]);
            } else {
                $this->db->prepare('DELETE FROM loan_applications WHERE id = ?')->execute([$id]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('ApplicationPurgeService: purge of #' . $id . ' failed: ' . $e->getMessage());
            return ['ok' => false, 'mode' => '', 'files' => 0, 'bytes' => 0, 'reason' => $e->getMessage()];
        }

        // After the commit only. Files go to quarantine first (the DB backup does not contain uploads).
        $bytes = 0;
        $moved = 0;
        $quarantine = $this->quarantineRoot() . '/' . date('Ymd') . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $app['application_no']);
        $shared = $this->db->prepare('SELECT 1 FROM borrower_documents WHERE file_path = ? LIMIT 1');
        $sharedApp = $this->db->prepare('SELECT 1 FROM loan_application_documents WHERE file_path = ? LIMIT 1');
        $n = 0;
        foreach ($files as $rel => $abs) {
            try {
                $shared->execute([$rel]);
                $sharedApp->execute([$rel]);
                if ($shared->fetchColumn() || $sharedApp->fetchColumn() || !is_file($abs)) {
                    continue;
                }
                if (!is_dir($quarantine)) {
                    @mkdir($quarantine, 0775, true);
                }
                $size = (int) filesize($abs);
                if (@rename($abs, $quarantine . '/' . (++$n) . '_' . basename($abs))) {
                    $bytes += $size;
                    $moved++;
                } else {
                    error_log('ApplicationPurgeService: could not quarantine ' . $rel);
                }
            } catch (\Throwable $e) {
                error_log('ApplicationPurgeService: orphan file ' . $rel . ': ' . $e->getMessage());
            }
        }
        foreach ($dirs as $d) {
            if (is_dir($d)) {
                @rmdir($d); // only succeeds when empty
            }
        }
        Audit::log('Delete', 'Applications', 'Purged unapproved application ' . ($app['application_no'] ?? '#' . $id) . ' (' . $mode . ', ' . $moved . ' file(s), ' . round($bytes / 1048576, 2) . ' MB moved to quarantine)', ['application_id' => $id, 'mode' => $mode]);
        return ['ok' => true, 'mode' => $mode, 'files' => $moved, 'bytes' => $bytes];
    }

    /** Really deletes quarantine folders older than application_purge_quarantine_days. @return array{folders:int, bytes:int} */
    public function sweepQuarantine(): array
    {
        $root = $this->quarantineRoot();
        $folders = 0;
        $bytes = 0;
        if (!is_dir($root)) {
            return ['folders' => 0, 'bytes' => 0];
        }
        $cutoff = date('Ymd', strtotime('-' . self::quarantineDays() . ' days'));
        foreach (scandir($root) ?: [] as $day) {
            if (!preg_match('/^\d{8}$/', $day) || $day >= $cutoff) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $day, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                if ($f->isDir()) {
                    @rmdir($f->getPathname());
                } else {
                    $bytes += (int) $f->getSize();
                    @unlink($f->getPathname());
                }
            }
            if (@rmdir($root . '/' . $day)) {
                $folders++;
            }
        }
        return ['folders' => $folders, 'bytes' => $bytes];
    }

    /** "Keep": one status-history note moves the age anchor and clears the warning. */
    public function extend(int $id, ?int $userId, string $note = 'Kept: deletion postponed'): void
    {
        $this->db->prepare('INSERT INTO loan_application_status_history (application_id, old_status, new_status, notes, changed_by) SELECT id, status, status, ?, ? FROM loan_applications WHERE id = ?')
            ->execute([$note, $userId, $id]);
        $this->db->prepare('UPDATE loan_applications SET purge_warned_at = NULL WHERE id = ?')->execute([$id]);
    }

    private function quarantineRoot(): string
    {
        return (realpath(STORAGE_PATH) ?: STORAGE_PATH) . '/purged';
    }

    /** @return array<string,string> relative path => absolute path (only files inside storage/uploads or storage/generated_documents) */
    private function collectFiles(int $id, ?string $applicationNo = null): array
    {
        if ($applicationNo === null) {
            $s = $this->db->prepare('SELECT application_no FROM loan_applications WHERE id = ?');
            $s->execute([$id]);
            $applicationNo = (string) $s->fetchColumn();
        }
        $rel = [];
        $queries = [
            'SELECT file_path FROM loan_application_documents WHERE application_id = ?',
            'SELECT file_path FROM generated_documents WHERE application_id = ?',
            'SELECT t.file_path FROM document_attachments t JOIN generated_documents g ON g.id = t.generated_document_id WHERE g.application_id = ?',
            'SELECT t.signature_file FROM document_signatures t JOIN generated_documents g ON g.id = t.generated_document_id WHERE g.application_id = ?',
        ];
        foreach ($queries as $q) {
            $s = $this->db->prepare($q);
            $s->execute([$id]);
            foreach ($s->fetchAll(\PDO::FETCH_COLUMN) as $p) {
                if ($p) {
                    $rel[] = (string) $p;
                }
            }
        }
        $out = [];
        $base = realpath(STORAGE_PATH) ?: STORAGE_PATH;
        foreach ($rel as $p) {
            $abs = $this->safePath($base, $p);
            if ($abs !== null) {
                $out[$p] = $abs;
            } else {
                error_log('ApplicationPurgeService: skipped path outside the allowed folders: ' . $p);
            }
        }
        foreach ($this->dirs($applicationNo) as $dir) {
            if (is_dir($dir)) {
                foreach (scandir($dir) ?: [] as $f) {
                    $abs = $this->safePath($base, substr($dir, strlen($base) + 1) . '/' . $f);
                    if ($f !== '.' && $f !== '..' && $abs !== null && is_file($abs)) {
                        $out[substr($dir, strlen($base) + 1) . '/' . $f] = $abs;
                    }
                }
            }
        }
        return $out;
    }

    /** The two upload folders for an application number. */
    private function dirs(string $applicationNo): array
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $applicationNo);
        if ($safe === '' || $safe === null) {
            return [];
        }
        $base = realpath(STORAGE_PATH) ?: STORAGE_PATH;
        return [$base . '/uploads/loan_applications/' . $safe, $base . '/uploads/applications/' . $safe];
    }

    /** Resolves a stored relative path; only files inside storage/uploads or storage/generated_documents are ever touched. */
    private function safePath(string $base, string $relative): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || strpos($relative, '..') !== false) {
            return null;
        }
        $real = realpath($base . '/' . $relative);
        if ($real === false) {
            return null;
        }
        $real = str_replace('\\', '/', $real);
        foreach (['uploads', 'generated_documents'] as $root) {
            $r = realpath($base . '/' . $root);
            if ($r !== false && strpos($real, str_replace('\\', '/', $r) . '/') === 0) {
                return $real;
            }
        }
        return null;
    }
}
