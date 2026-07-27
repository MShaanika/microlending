<?php

namespace App\Models;

use App\Core\Model;

class SocialAnalyticsSetting extends Model
{
    public function allPlatforms(): array
    {
        return $this->all(
            "SELECT * FROM social_analytics_settings ORDER BY FIELD(platform,'google_analytics','facebook','instagram','linkedin')"
        );
    }

    public function enabledPlatforms(): array
    {
        return $this->all(
            "SELECT * FROM social_analytics_settings WHERE is_enabled = 1
             ORDER BY FIELD(platform,'google_analytics','facebook','instagram','linkedin')"
        );
    }

    public function find(int $id): ?array
    {
        return $this->one("SELECT * FROM social_analytics_settings WHERE id = ?", [$id]);
    }

    public function findByPlatform(string $platform): ?array
    {
        return $this->one("SELECT * FROM social_analytics_settings WHERE platform = ?", [$platform]);
    }

    public function updateSettings(int $id, array $data): bool
    {
        return $this->update('social_analytics_settings', $data, 'id', $id);
    }
}
