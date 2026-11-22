<?php

namespace App\Models;

use App\Core\Model;

/** Storage for the loan questions (see LoanFollowupFlow for the rules). */
class LoanFollowup extends Model
{
    /** Applications received from this day on are tracked (the first online applications of the new system). */
    public const TRACK_FROM = '2026-10-29';

    /**
     * Makes sure every tracked application has a row. Returns how many were added.
     *
     * @param string[] $excludedNames full names ("First Last") of test applicants to leave out
     */
    public function syncApplications(array $excludedNames = [], string $from = self::TRACK_FROM): int
    {
        $rows = $this->all(
            "SELECT a.id, a.application_no, a.applicant_first_name, a.applicant_last_name
             FROM loan_applications a LEFT JOIN loan_followups f ON f.application_id = a.id
             WHERE f.id IS NULL AND a.purged_at IS NULL AND a.created_at >= ? AND a.status NOT IN ('Rejected', 'Cancelled')
             ORDER BY a.id",
            [$from . ' 00:00:00']
        );
        $excluded = array_map(static fn (string $n): string => strtolower(trim((string) preg_replace('/\s+/', ' ', $n))), $excludedNames);
        $added = 0;
        foreach ($rows as $r) {
            $full = strtolower(trim((string) preg_replace('/\s+/', ' ', $r['applicant_first_name'] . ' ' . $r['applicant_last_name'])));
            if ($full !== '' && in_array($full, $excluded, true)) {
                continue;
            }
            // INSERT IGNORE: two people opening the page (or the page and the daily job) at the same moment cannot collide on the unique application
            $stmt = $this->db->prepare('INSERT IGNORE INTO loan_followups (application_id, application_no) VALUES (?, ?)');
            $stmt->execute([(int) $r['id'], $r['application_no']]);
            $added += $stmt->rowCount();
        }
        return $added;
    }

    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM loan_followups WHERE id = ?', [$id]);
    }

    /** The row, locked until the surrounding transaction ends (call it inside transaction()). */
    public function lock(int $id): ?array
    {
        return $this->one('SELECT * FROM loan_followups WHERE id = ? FOR UPDATE', [$id]);
    }

    /** Closes an open application that was not paid out, in one conditional statement. Returns whether it was closed. */
    public function closeUnlessPaidOut(int $id, string $reason, ?int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE loan_followups SET status = 'Closed', closed_reason = ?, last_answered_by = ?, last_answered_at = NOW()
                                    WHERE id = ? AND status = 'Open' AND (disbursed IS NULL OR disbursed <> 'Yes')");
        $stmt->execute([$reason, $userId, $id]);
        return $stmt->rowCount() === 1;
    }

    public function deleteInstalments(int $followupId): void
    {
        $this->db->prepare('DELETE FROM loan_followup_instalments WHERE followup_id = ?')->execute([$followupId]);
    }

    public function addPayment(int $instalmentId, float $amount, string $paidOn, ?int $userId): void
    {
        $this->insert('loan_followup_payments', ['instalment_id' => $instalmentId, 'amount' => $amount, 'paid_on' => $paidOn, 'answered_by' => $userId]);
    }

    /** @return array<int, array<int, array{amount:string, paid_on:string}>> payments of one loan's instalments, by instalment id */
    public function paymentsByInstalment(?int $followupId = null): array
    {
        $sql = 'SELECT p.instalment_id, p.amount, p.paid_on FROM loan_followup_payments p JOIN loan_followup_instalments i ON i.id = p.instalment_id';
        $params = [];
        if ($followupId !== null) {
            $sql .= ' WHERE i.followup_id = ?';
            $params[] = $followupId;
        }
        $out = [];
        foreach ($this->all($sql . ' ORDER BY p.paid_on, p.id', $params) as $r) {
            $out[(int) $r['instalment_id']][] = ['amount' => $r['amount'], 'paid_on' => $r['paid_on']];
        }
        return $out;
    }

    /** @return array<int, array<string,mixed>> open follow-ups with their application, oldest first */
    public function openWithApplications(): array
    {
        return $this->all(
            "SELECT f.*, a.applicant_first_name, a.applicant_middle_name, a.applicant_last_name, a.applicant_phone, a.applicant_id_number, a.requested_amount,
                    a.requested_term_months, a.payment_day AS application_pay_day, a.created_at AS applied_at
             FROM loan_followups f JOIN loan_applications a ON a.id = f.application_id
             WHERE f.status = 'Open' AND a.purged_at IS NULL ORDER BY a.created_at, f.id"
        );
    }

    public function application(int $followupId): ?array
    {
        return $this->one('SELECT a.* FROM loan_applications a JOIN loan_followups f ON f.application_id = a.id WHERE f.id = ?', [$followupId]);
    }

    /** @return array<int, array<string,mixed>> */
    public function instalments(int $followupId): array
    {
        return $this->all('SELECT * FROM loan_followup_instalments WHERE followup_id = ? ORDER BY instalment_no', [$followupId]);
    }

    public function instalment(int $id): ?array
    {
        return $this->one('SELECT * FROM loan_followup_instalments WHERE id = ?', [$id]);
    }

    /** @return array<int, array<int, array<string,mixed>>> instalments of every follow-up, by follow-up id */
    public function instalmentsByFollowup(): array
    {
        $out = [];
        foreach ($this->all('SELECT i.* FROM loan_followup_instalments i JOIN loan_followups f ON f.id = i.followup_id WHERE f.status = \'Open\' ORDER BY i.followup_id, i.instalment_no') as $i) {
            $out[(int) $i['followup_id']][] = $i;
        }
        return $out;
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('loan_followups', $data, 'id', $id);
    }

    public function updateInstalment(int $id, array $data): bool
    {
        return $this->update('loan_followup_instalments', $data, 'id', $id);
    }

    public function createInstalments(int $followupId, array $schedule): void
    {
        foreach ($schedule as $s) {
            $this->insert('loan_followup_instalments', [
                'followup_id' => $followupId,
                'instalment_no' => $s['instalment_no'],
                'due_date' => $s['due_date'],
                'expected_amount' => $s['expected_amount'],
            ]);
        }
    }

    public function logAnswer(int $followupId, string $question, string $answer, ?int $userId): void
    {
        $this->insert('loan_followup_answers', ['followup_id' => $followupId, 'question' => $question, 'answer' => mb_substr($answer, 0, 255), 'answered_by' => $userId]);
    }

    /** @return array<int, array<string,mixed>> */
    public function answers(int $followupId): array
    {
        return $this->all('SELECT a.*, u.name AS user_name FROM loan_followup_answers a LEFT JOIN users u ON u.id = a.answered_by WHERE a.followup_id = ? ORDER BY a.id DESC', [$followupId]);
    }

    /** Paid-out loans with their instalments, for the Creditinfo file. @return array<int, array<string,mixed>> */
    public function disbursedWithApplications(): array
    {
        return $this->all(
            "SELECT f.*, a.id AS app_id FROM loan_followups f JOIN loan_applications a ON a.id = f.application_id
             WHERE a.purged_at IS NULL AND f.approved = 'Yes' AND f.disbursed = 'Yes' AND f.disbursed_on IS NOT NULL AND f.pay_day IS NOT NULL AND f.approved_amount IS NOT NULL ORDER BY f.disbursed_on, f.id"
        );
    }
}
