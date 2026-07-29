<?php

namespace App\Models;

use App\Core\Model;

class HrmAnnouncementCategory extends Model
{
    public function allCategories(): array
    {
        return $this->query("SELECT * FROM hrm_announcement_categories ORDER BY name")->fetchAll();
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM hrm_announcement_categories WHERE id = ?", [$id]);
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return (bool) $this->scalar("SELECT 1 FROM hrm_announcement_categories WHERE name = ? AND id != ?", [$name, $excludeId]);
        }
        return (bool) $this->scalar("SELECT 1 FROM hrm_announcement_categories WHERE name = ?", [$name]);
    }

    public function create(array $data): int
    {
        return $this->insert('hrm_announcement_categories', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('hrm_announcement_categories', $data, 'id', $id);
    }

    public function inUseCount(int $id): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM hrm_announcements WHERE announcement_category_id = ?", [$id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM hrm_announcement_categories WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
