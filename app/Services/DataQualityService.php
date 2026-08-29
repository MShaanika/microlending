<?php

namespace App\Services;

use App\Core\Correlation;
use App\Core\Database;
use App\Models\DataQualityIssue;

/**
 * Proactive data-quality detection (Part 28-33). Rule TYPES are code-
 * defined, real, reviewed SQL against this app's actual schema -- not a
 * generic rule interpreter -- same pattern as SecurityRuleEngine. Each
 * rule is individually toggleable via its data_quality_rules row.
 *
 * NEVER writes to the record being checked. A rule only ever produces a
 * data_quality_issues row describing what it found; correction happens
 * through the normal, already-authorized workflow for that data (Part
 * 33, Part 99's worked example).
 */
class DataQualityService
{
    /** Runs every active rule, upserts issues for still-failing rows, auto-resolves ones that no longer fail. Safe to call repeatedly (idempotent). */
    public static function scan(): array
    {
        $ruleModel = new DataQualityIssue();
        $summary = [];

        foreach ($ruleModel->activeRules() as $rule) {
            $failing = self::run($rule['rule_key']);
            $stillFailingIds = [];

            foreach ($failing as $row) {
                $result = $ruleModel->upsert((int) $rule['id'], $row['resource_type'], (int) $row['resource_id'], $row['description'], Correlation::id());
                $stillFailingIds[] = (int) $row['resource_id'];

                if ($result['is_new'] && $rule['auto_create_exception']) {
                    $exceptionId = ExceptionService::create(
                        $rule['rule_key'],
                        'Data Quality',
                        $rule['module'],
                        $rule['severity'],
                        $row['description'],
                        $row['resource_type'],
                        (int) $row['resource_id']
                    );
                    (new DataQualityIssue())->linkException($result['id'], $exceptionId);
                }
            }

            $resolvedCount = $ruleModel->autoResolveNoLongerFailing((int) $rule['id'], $stillFailingIds);
            $ruleModel->markRuleRun((int) $rule['id']);

            $summary[$rule['rule_key']] = ['failing' => count($failing), 'auto_resolved' => $resolvedCount];
        }

        return $summary;
    }

    /** @return list<array{resource_type: string, resource_id: int, description: string}> */
    private static function run(string $ruleKey): array
    {
        return match ($ruleKey) {
            'unbalanced_journal' => self::unbalancedJournals(),
            'completed_loan_with_balance' => self::completedLoansWithBalance(),
            'borrower_missing_national_id' => self::borrowersMissingNationalId(),
            'negative_loan_principal' => self::negativeLoanPrincipal(),
            'duplicate_borrower_phone' => self::duplicateBorrowerPhones(),
            default => [],
        };
    }

    private static function unbalancedJournals(): array
    {
        $db = Database::connection();
        $rows = $db->query(
            "SELECT j.id, j.journal_no, SUM(l.debit) AS total_debit, SUM(l.credit) AS total_credit
             FROM accounting_journal_entries j
             JOIN accounting_journal_lines l ON l.journal_id = j.id
             WHERE j.status = 'Posted'
             GROUP BY j.id, j.journal_no
             HAVING ROUND(SUM(l.debit), 2) != ROUND(SUM(l.credit), 2)"
        )->fetchAll();

        return array_map(static fn ($r) => [
            'resource_type' => 'accounting_journal_entry',
            'resource_id' => (int) $r['id'],
            'description' => sprintf('Journal %s is unbalanced: debit %s vs credit %s.', $r['journal_no'], number_format((float) $r['total_debit'], 2), number_format((float) $r['total_credit'], 2)),
        ], $rows);
    }

    private static function completedLoansWithBalance(): array
    {
        $db = Database::connection();
        $rows = $db->query(
            "SELECT l.id, l.loan_no, COUNT(s.id) AS unpaid_count
             FROM loans l
             JOIN loan_schedules s ON s.loan_id = l.id
             WHERE l.loan_status = 'Completed' AND s.status != 'Paid'
             GROUP BY l.id, l.loan_no"
        )->fetchAll();

        return array_map(static fn ($r) => [
            'resource_type' => 'loan',
            'resource_id' => (int) $r['id'],
            'description' => sprintf('Loan %s is marked Completed but has %d unpaid schedule row(s).', $r['loan_no'], (int) $r['unpaid_count']),
        ], $rows);
    }

    private static function borrowersMissingNationalId(): array
    {
        $db = Database::connection();
        $rows = $db->query(
            "SELECT id, CONCAT(first_name, ' ', last_name) AS full_name FROM borrowers
             WHERE status = 'Approved' AND (id_number IS NULL OR TRIM(id_number) = '')"
        )->fetchAll();

        return array_map(static fn ($r) => [
            'resource_type' => 'borrower',
            'resource_id' => (int) $r['id'],
            'description' => sprintf('Approved borrower %s has no ID/passport number on file.', $r['full_name']),
        ], $rows);
    }

    private static function negativeLoanPrincipal(): array
    {
        $db = Database::connection();
        $rows = $db->query(
            "SELECT id, loan_no, principal_amount FROM loans WHERE principal_amount <= 0"
        )->fetchAll();

        return array_map(static fn ($r) => [
            'resource_type' => 'loan',
            'resource_id' => (int) $r['id'],
            'description' => sprintf('Loan %s has a principal amount of %s.', $r['loan_no'], number_format((float) $r['principal_amount'], 2)),
        ], $rows);
    }

    /**
     * Deterministic (exact-match) duplicate detection, per Part 32 --
     * fuzzy name/phone matching is explicitly allowed to ASSIST, but
     * financial client records are never auto-merged on a fuzzy match
     * alone. This rule stays exact-match; a fuzzy-assisted layer is a
     * real, separate feature, not attempted here.
     *
     * One issue per duplicate group, anchored to the group's lowest
     * borrower ID so re-scanning the same group doesn't create a new
     * issue every time (see the UNIQUE KEY on data_quality_issues).
     */
    private static function duplicateBorrowerPhones(): array
    {
        $db = Database::connection();
        $groups = $db->query(
            "SELECT phone, MIN(id) AS anchor_id, COUNT(*) AS total,
                    GROUP_CONCAT(borrower_no ORDER BY id SEPARATOR ', ') AS borrower_nos
             FROM borrowers
             WHERE phone IS NOT NULL AND TRIM(phone) != '' AND status != 'Rejected'
             GROUP BY phone
             HAVING COUNT(*) > 1"
        )->fetchAll();

        return array_map(static fn ($r) => [
            'resource_type' => 'borrower_phone_group',
            'resource_id' => (int) $r['anchor_id'],
            'description' => sprintf('%d borrowers share phone number %s: %s.', (int) $r['total'], $r['phone'], $r['borrower_nos']),
        ], $groups);
    }
}
