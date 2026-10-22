<?php

namespace App\Services;

use App\Core\Database;

/**
 * One time-ordered account of everything that has happened to a loan, drawn from every place the system records it: the loan's own
 * status history, its reschedules, disbursement (including any Collexia EnCr payout), payments, debit orders and their cancellation
 * requests, top-ups, and the audit trail.
 *
 * The audit trail is matched two ways: by the loan id that newer entries carry in their metadata, and -- because older entries only
 * name the loan in their text -- by "loan #N", the loan number, and "debit order #N" / "reschedule #N" for this loan's own debit orders
 * and reschedules, always as whole numbers so loan #8 never picks up loan #80.
 *
 * Read-only: it never writes anything.
 */
class LoanTimelineService
{
    /**
     * @return array<int, array{at: string, kind: string, title: string, detail: string, actor: string, link: ?string}> newest first
     */
    public static function forLoan(int $loanId, ?\PDO $db = null, ?int $clockOffset = null): array
    {
        $db ??= Database::connection();
        $q = function (string $sql, array $params = []) use ($db): array {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        };
        $money = fn ($v) => 'N$' . number_format((float) $v, 2);

        // TWO CLOCKS. Some columns are stamped by the application (payments.posted_at, reschedule approved/implemented_at, disbursed_at) and
        // others by the database (every created_at, the audit trail, the loan status history). On this host the two differ by hours, so
        // sorted raw the same event appears twice, hours apart. Everything is put on the APPLICATION's clock -- the one the screens treat as
        // "now" -- by shifting database-stamped times by the measured difference (rounded to 15 minutes, so query latency never shows).
        $dbNow = strtotime((string) $db->query('SELECT NOW()')->fetchColumn());
        $offset = $clockOffset ?? (int) (round((time() - $dbNow) / 900) * 900);   // $clockOffset: a test can pin the difference
        $dbClock = fn (?string $t) => $t ? date('Y-m-d H:i:s', strtotime($t) + $offset) : null;

        $loan = $q('SELECT l.*, u.name AS creator FROM loans l LEFT JOIN users u ON u.id = l.created_by WHERE l.id = ?', [$loanId])[0] ?? null;
        if (!$loan) {
            return [];
        }

        $events = [];
        $add = function (?string $at, string $kind, string $title, string $detail = '', ?string $actor = null, ?string $link = null) use (&$events): void {
            if ($at) {
                $events[] = ['at' => $at, 'kind' => $kind, 'title' => $title, 'detail' => $detail, 'actor' => (string) $actor, 'link' => $link];
            }
        };

        $add($dbClock($loan['created_at']), 'loan', 'Loan created', $money($loan['principal_amount']) . ' · ' . $loan['loan_no'], $loan['creator']);

        foreach ($q('SELECT h.*, u.name FROM loan_status_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.loan_id = ?', [$loanId]) as $h) {
            $title = ($h['old_status'] && $h['old_status'] !== $h['new_status']) ? $h['old_status'] . ' → ' . $h['new_status'] : $h['new_status'];
            $add($dbClock($h['changed_at']), 'status', $title, (string) $h['notes'], $h['name']);
        }

        $reschedules = $q(
            'SELECT r.*, ur.name AS requester, ua.name AS approver, ui.name AS implementer, uv.name AS reviewer
             FROM loan_reschedules r
             LEFT JOIN users ur ON ur.id = r.requested_by LEFT JOIN users ua ON ua.id = r.approved_by
             LEFT JOIN users ui ON ui.id = r.implemented_by LEFT JOIN users uv ON uv.id = r.reviewed_by
             WHERE r.loan_id = ?',
            [$loanId]
        );
        $rescheduleIds = [];
        foreach ($reschedules as $r) {
            $rescheduleIds[] = (int) $r['id'];
            $link = '/reschedules/' . (int) $r['id'];
            $no = $r['reschedule_no'];
            $add($dbClock($r['created_at']), 'reschedule', 'Reschedule requested (' . $no . ')',
                $r['old_term_months'] . ' → ' . $r['new_term_months'] . ' months, instalment ' . $money($r['old_installment_amount']) . ' → ' . $money($r['new_installment_amount'])
                . ((float) $r['waived_amount'] > 0 ? ', waiver ' . $money($r['waived_amount']) : '') . '. Reason: ' . $r['reason'], $r['requester'], $link);
            $add($r['reviewed_at'], 'reschedule', 'Reschedule reviewed (' . $no . ')', '', $r['reviewer'], $link);
            $add($r['approved_at'], 'reschedule', 'Reschedule approved (' . $no . ')', '', $r['approver'], $link);
            $add($r['implemented_at'], 'reschedule', 'Reschedule implemented (' . $no . ')', 'The loan now follows its new schedule.', $r['implementer'], $link);
            if (in_array($r['status'], ['Rejected', 'Cancelled'], true)) {
                $add($dbClock($r['updated_at'] ?: $r['created_at']), 'reschedule', 'Reschedule ' . strtolower($r['status']) . ' (' . $no . ')', (string) $r['rejection_reason'], null, $link);
            }
        }

        foreach ($q('SELECT d.*, u.name FROM loan_disbursements d LEFT JOIN users u ON u.id = d.disbursed_by WHERE d.loan_id = ?', [$loanId]) as $d) {
            $detail = $d['disbursement_method'] . ($d['reference_no'] ? ' · reference ' . $d['reference_no'] : '');
            if (!empty($d['encr_reference_no'])) {
                $detail .= ' · Collexia EnCr payout ' . $d['encr_state'] . ($d['encr_last_message'] ? ' (' . $d['encr_last_message'] . ')' : '');
            }
            $add($d['disbursed_at'] ?: $dbClock($d['created_at']), 'disbursement', ($d['status'] === 'Cancelled' ? 'Disbursement cancelled: ' : 'Loan disbursed: ') . $money($d['amount']), $detail, $d['name']);
        }

        foreach ($q('SELECT p.*, u.name FROM payments p LEFT JOIN users u ON u.id = p.posted_by WHERE p.loan_id = ?', [$loanId]) as $p) {
            $verb = ['Reversed' => 'Payment reversed', 'Cancelled' => 'Payment cancelled', 'Pending' => 'Payment pending'][$p['status']] ?? 'Payment received';
            $add($p['posted_at'] ?: $dbClock($p['receipt_date'] ?: $p['created_at']), 'payment', $verb . ': ' . $money($p['amount_received']),
                $p['payment_no'] . ' · ' . $p['payment_source'] . ($p['reference_no'] ? ' · reference ' . $p['reference_no'] : ''), $p['name']);
        }

        $debitOrderIds = [];
        foreach ($q('SELECT d.*, u.name FROM debit_orders d LEFT JOIN users u ON u.id = d.created_by WHERE d.loan_id = ?', [$loanId]) as $d) {
            $debitOrderIds[] = (int) $d['id'];
            $add($dbClock($d['created_at']), 'debit_order', 'Debit order registered (' . $d['debit_order_no'] . ')', 'Now ' . strtolower($d['status']) . ' · mandate: ' . ($d['collexia_api_status'] ?? 'n/a'), $d['name'], '/debit-orders/' . (int) $d['id']);
        }
        foreach ($q('SELECT c.*, u.name FROM debit_order_cancellations c LEFT JOIN users u ON u.id = c.requested_by WHERE c.loan_id = ?', [$loanId]) as $c) {
            $add($dbClock($c['created_at']), 'debit_order', 'Debit order cancellation requested (' . $c['cancellation_no'] . ')', 'Reason: ' . $c['reason'] . ' · status ' . $c['status'], $c['name']);
        }

        foreach ($q('SELECT t.*, u.name FROM loan_topups t LEFT JOIN users u ON u.id = t.created_by WHERE t.loan_id = ?', [$loanId]) as $t) {
            $add($dbClock($t['created_at']), 'topup', 'Loan topped up by ' . $money($t['topup_amount']), '', $t['name']);
            if ($t['status'] === 'Reversed') {
                $add($t['reversed_at'], 'topup', 'Top-up reversed (' . $money($t['topup_amount']) . ')', '');
            }
        }

        // The audit trail: by the loan id in newer entries' metadata, and by wording for older ones -- whole numbers only.
        $patterns = ['loan #' . $loanId];
        foreach ($debitOrderIds as $id) {
            $patterns[] = 'debit order #' . $id;
        }
        foreach ($rescheduleIds as $id) {
            $patterns[] = 'reschedule #' . $id;
        }
        $regex = '(' . implode('|', array_map('preg_quote', $patterns)) . ')([^0-9]|$)';
        $audit = $q(
            'SELECT a.*, u.name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
             WHERE a.metadata LIKE ? OR a.metadata LIKE ? OR a.description REGEXP ? OR a.description LIKE ?
             ORDER BY a.id DESC LIMIT 600',
            ['%"loan_id":' . $loanId . ',%', '%"loan_id":' . $loanId . '}%', $regex, '%' . $loan['loan_no'] . '%']
        );
        foreach ($audit as $a) {
            $add($dbClock($a['created_at']), 'audit', $a['action'] . ' · ' . $a['module_name'], (string) $a['description'], $a['name']);
        }

        usort($events, fn ($x, $y) => strcmp($y['at'], $x['at']));
        return $events;
    }

    /** Labels and badge colours for the kinds above, shared by the page. */
    public static function kinds(): array
    {
        return [
            'loan' => ['Loan', 'secondary'],
            'status' => ['Status', 'primary'],
            'reschedule' => ['Reschedule', 'warning'],
            'disbursement' => ['Disbursement', 'info'],
            'payment' => ['Payment', 'success'],
            'debit_order' => ['Debit order', 'dark'],
            'topup' => ['Top-up', 'info'],
            'audit' => ['Audit trail', 'light'],
        ];
    }
}
