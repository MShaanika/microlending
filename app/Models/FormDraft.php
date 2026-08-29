<?php

namespace App\Models;

use App\Core\Model;

/**
 * Autosaved partial-form progress. Deliberately separate from the existing
 * live 'Draft' ENUM value on loans.loan_status / accounting_journal_entries
 * .status -- those are real, reportable, already-counted records; this
 * table is scratch state and must never be confused with them.
 */
class FormDraft extends Model
{
    public function findByUuid(string $uuid, int $userId): ?array
    {
        return $this->one('SELECT * FROM form_drafts WHERE draft_uuid = ? AND user_id = ?', [$uuid, $userId]);
    }

    /** @return array{rows: array, total: int} unfinished (not submitted/cancelled/expired), most recent first. */
    public function allForUser(int $userId, ?string $module = null): array
    {
        $sql = "SELECT * FROM form_drafts WHERE user_id = ? AND status NOT IN ('SUBMITTED','COMPLETED','CANCELLED','EXPIRED')";
        $params = [$userId];
        if ($module !== null) {
            $sql .= ' AND module = ?';
            $params[] = $module;
        }
        $sql .= ' ORDER BY last_autosaved_at DESC, created_at DESC';
        return $this->all($sql, $params);
    }

    public function countUnfinishedForUser(int $userId): int
    {
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM form_drafts WHERE user_id = ? AND status NOT IN ('SUBMITTED','COMPLETED','CANCELLED','EXPIRED')",
            [$userId]
        );
    }

    /** The most recent unfinished draft for a given user+workflow, if any -- used to offer Continue/Review/Discard instead of silently starting fresh. */
    public function latestForWorkflow(int $userId, string $workflowKey): ?array
    {
        return $this->one(
            "SELECT * FROM form_drafts WHERE user_id = ? AND workflow_key = ? AND status NOT IN ('SUBMITTED','COMPLETED','CANCELLED','EXPIRED')
             ORDER BY last_autosaved_at DESC, created_at DESC LIMIT 1",
            [$userId, $workflowKey]
        );
    }

    public function create(array $data): int
    {
        return $this->insert('form_drafts', $data);
    }

    public function saveProgress(string $uuid, int $userId, string $formData, ?string $currentStep, int $retentionDays): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE form_drafts SET form_data = ?, current_step = ?, status = 'DRAFT',
                last_autosaved_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL ? DAY)
             WHERE draft_uuid = ? AND user_id = ?"
        );
        $stmt->execute([$formData, $currentStep, $retentionDays, $uuid, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function markStatus(string $uuid, int $userId, string $status, ?int $relatedEntityId = null): bool
    {
        $data = ['status' => $status];
        if ($status === 'SUBMITTED' || $status === 'COMPLETED') {
            $data['submitted_at'] = date('Y-m-d H:i:s');
        }
        if ($relatedEntityId !== null) {
            $data['related_entity_id'] = $relatedEntityId;
        }
        return $this->updateOwned($uuid, $userId, $data);
    }

    public function discard(string $uuid, int $userId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM form_drafts WHERE draft_uuid = ? AND user_id = ?');
        $stmt->execute([$uuid, $userId]);
        return $stmt->rowCount() > 0;
    }

    private function updateOwned(string $uuid, int $userId, array $data): bool
    {
        $columns = array_keys($data);
        $set = implode(', ', array_map(static fn ($c) => "$c = :$c", $columns));
        $stmt = $this->db->prepare("UPDATE form_drafts SET $set WHERE draft_uuid = :__uuid AND user_id = :__user");
        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':__uuid', $uuid);
        $stmt->bindValue(':__user', $userId);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    /** @return array{uuid: string, count: int, cutoff: string} expired drafts -- used by bin/sweep_draft_expiry.php. */
    public function expiredUuids(): array
    {
        return $this->all("SELECT draft_uuid FROM form_drafts WHERE expires_at < NOW()");
    }

    public function deleteByUuid(string $uuid): void
    {
        $this->db->prepare('DELETE FROM form_drafts WHERE draft_uuid = ?')->execute([$uuid]);
    }
}
