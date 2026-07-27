<?php

namespace App\Models;

use App\Core\Model;

class SocialAnalyticsMetric extends Model
{
    public function forSetting(int $settingId, int $limit = 24): array
    {
        return $this->all(
            "SELECT * FROM social_analytics_metrics WHERE setting_id = ?
             ORDER BY entry_date DESC LIMIT " . (int) $limit,
            [$settingId]
        );
    }

    /**
     * Oldest-first, capped to the most recent $points entries -- the shape
     * ApexCharts wants (categories/series read left-to-right as time moves
     * forward), unlike forSetting()'s newest-first table listing.
     */
    public function trend(int $settingId, int $points = 12): array
    {
        $rows = $this->all(
            "SELECT entry_date, metric_1, metric_2, metric_3 FROM social_analytics_metrics
             WHERE setting_id = ? ORDER BY entry_date DESC LIMIT " . (int) $points,
            [$settingId]
        );
        return array_reverse($rows);
    }

    public function latestForSetting(int $settingId): ?array
    {
        return $this->one(
            "SELECT * FROM social_analytics_metrics WHERE setting_id = ? ORDER BY entry_date DESC LIMIT 1",
            [$settingId]
        );
    }

    public function findByPlatformAndDate(int $settingId, string $entryDate): ?array
    {
        return $this->one(
            "SELECT * FROM social_analytics_metrics WHERE setting_id = ? AND entry_date = ?",
            [$settingId, $entryDate]
        );
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM social_analytics_metrics WHERE id = ?", [$id]);
    }

    public function create(array $data): int
    {
        return $this->insert('social_analytics_metrics', $data);
    }

    public function updateRecord(int $id, array $data): bool
    {
        return $this->update('social_analytics_metrics', $data, 'id', $id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->query("DELETE FROM social_analytics_metrics WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
}
