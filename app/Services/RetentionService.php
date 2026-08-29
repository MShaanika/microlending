<?php

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\RetentionPolicy;

/**
 * Safe, controlled deletion per an admin-configured retention policy
 * (Part 44-48). Every run -- dry or real -- follows the same sequence:
 * identify eligible rows, exclude anything under legal hold, count,
 * delete in one batch, record the result. Dry-run never deletes
 * anything; it exists specifically so an admin can see what a policy
 * WOULD do before trusting it to run for real.
 *
 * Deliberately narrow about which tables it will touch: only a
 * single-table, no-child-rows, no-filesystem-cleanup delete is safe to
 * run generically. form_drafts needs cascading cleanup of
 * draft_documents rows and uploaded files -- that's what
 * bin/sweep_draft_expiry.php already does correctly, and this service
 * does not attempt to replicate or replace it (see
 * bin/evaluate_retention.php's own exclusion list).
 */
class RetentionService
{
    /** @return array{eligible: int, held: int} counts only -- never deletes, regardless of $execute. */
    public static function preview(array $policy): array
    {
        return self::run($policy, false);
    }

    /** @return array{eligible: int, held: int, deleted: int} */
    public static function execute(array $policy, ?int $ranBy = null): array
    {
        $result = self::run($policy, true);
        (new RetentionPolicy())->recordRun((int) $policy['id'], false, $result['eligible'], $result['held'], $result['deleted'], $ranBy);
        Audit::log('Delete', 'Continuity', sprintf(
            'Retention policy "%s" deleted %d row(s) from %s (%d held by legal hold, skipped).',
            $policy['policy_name'],
            $result['deleted'],
            $policy['resource_table'],
            $result['held']
        ), ['policy_id' => $policy['id']]);
        return $result;
    }

    /** @return array{eligible: int, held: int, deleted: int} */
    private static function run(array $policy, bool $delete): array
    {
        $table = self::assertKnownTable($policy['resource_table']);
        $dateColumn = self::assertSafeIdentifier($policy['date_column']);
        $db = Database::connection();

        $condition = $policy['comparison_mode'] === 'DATE_COLUMN_IS_EXPIRY'
            ? "$dateColumn < NOW()"
            : "$dateColumn < DATE_SUB(NOW(), INTERVAL ? DAY)";
        $params = $policy['comparison_mode'] === 'DATE_COLUMN_IS_EXPIRY' ? [] : [(int) $policy['retention_days']];

        $eligibleIds = $db->prepare("SELECT id FROM `$table` WHERE $condition");
        $eligibleIds->execute($params);
        $ids = $eligibleIds->fetchAll(\PDO::FETCH_COLUMN);

        $heldIds = $policy['legal_hold_supported'] ? (new RetentionPolicy())->activeHoldsFor($table) : [];
        $deletableIds = array_values(array_diff($ids, $heldIds));

        $deleted = 0;
        if ($delete && !empty($deletableIds)) {
            $placeholders = implode(',', array_fill(0, count($deletableIds), '?'));
            $stmt = $db->prepare("DELETE FROM `$table` WHERE id IN ($placeholders)");
            $stmt->execute($deletableIds);
            $deleted = $stmt->rowCount();
        }

        return [
            'eligible' => count($ids),
            'held' => count($ids) - count($deletableIds),
            'deleted' => $deleted,
        ];
    }

    /** Table names only ever come from retention_policies.resource_table, an admin-entered value -- allowlisted here rather than trusted blindly, since it's interpolated into raw SQL. */
    private static function assertKnownTable(string $table): string
    {
        $allowed = ['idempotency_keys', 'form_drafts'];
        if (!in_array($table, $allowed, true)) {
            throw new \RuntimeException("Retention execution is not enabled for table '$table' -- add it to RetentionService's allowlist only after confirming it has no child rows or files that need matching cleanup.");
        }
        return $table;
    }

    private static function assertSafeIdentifier(string $column): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            throw new \RuntimeException("Invalid column name: $column");
        }
        return $column;
    }
}
