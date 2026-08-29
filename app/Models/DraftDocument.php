<?php

namespace App\Models;

use App\Core\Model;

/** Files uploaded while a draft is still in progress -- staged under storage/uploads/_drafts/{uuid}/ until the draft is finalized or expires. */
class DraftDocument extends Model
{
    public function forDraft(string $uuid): array
    {
        return $this->all('SELECT * FROM draft_documents WHERE draft_uuid = ? ORDER BY id ASC', [$uuid]);
    }

    public function create(array $data): int
    {
        return $this->insert('draft_documents', $data);
    }

    public function find(int $id, string $uuid): ?array
    {
        return $this->one('SELECT * FROM draft_documents WHERE id = ? AND draft_uuid = ?', [$id, $uuid]);
    }

    public function delete(int $id, string $uuid): bool
    {
        $stmt = $this->db->prepare('DELETE FROM draft_documents WHERE id = ? AND draft_uuid = ?');
        $stmt->execute([$id, $uuid]);
        return $stmt->rowCount() > 0;
    }

    public function deleteForDraft(string $uuid): void
    {
        $this->db->prepare('DELETE FROM draft_documents WHERE draft_uuid = ?')->execute([$uuid]);
    }
}
