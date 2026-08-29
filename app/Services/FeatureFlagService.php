<?php

namespace App\Services;

use App\Core\Auth;
use App\Models\FeatureFlag;

/**
 * Staged-rollout evaluation for feature_flags (Part 39-42).
 *
 * Part 42, enforced by convention here and documented for every future
 * caller: isEnabled() answers "should this user see the new behavior,"
 * never "is this user allowed to." A call site must still run
 * Auth::authorize()/Auth::can() for the permission check -- a flag
 * gates rollout, not access.
 *
 * Fails closed throughout: an unknown flag, a malformed metadata blob,
 * or any lookup error all resolve to false (old behavior), never true.
 */
class FeatureFlagService
{
    public static function isEnabled(string $flagKey, ?array $user = null): bool
    {
        try {
            $flag = (new FeatureFlag())->findByKey($flagKey);
            if (!$flag || !(int) $flag['enabled']) {
                return false;
            }

            $now = date('Y-m-d H:i:s');
            if (!empty($flag['starts_at']) && $flag['starts_at'] > $now) {
                return false;
            }
            if (!empty($flag['ends_at']) && $flag['ends_at'] < $now) {
                return false;
            }

            $user = $user ?? Auth::user();
            $metadata = $flag['metadata'] ? json_decode($flag['metadata'], true) : [];

            return match ($flag['rollout_type']) {
                'OFF' => false,
                'ALL_USERS' => true,
                'SPECIFIC_USERS' => self::matchesUserId($metadata, $user),
                'SPECIFIC_ROLES' => self::matchesRole($metadata, $user),
                'SPECIFIC_BRANCHES' => self::matchesBranch($metadata, $user),
                'PERCENTAGE' => self::matchesPercentage($flagKey, (int) ($flag['rollout_percentage'] ?? 0), $user),
                'INTERNAL_ONLY' => self::isInternalUser($user),
                default => false,
            };
        } catch (\Throwable $e) {
            error_log("FeatureFlagService::isEnabled('$flagKey') failed: " . $e->getMessage());
            return false;
        }
    }

    private static function matchesUserId(array $metadata, ?array $user): bool
    {
        if (!$user) {
            return false;
        }
        return in_array((int) $user['id'], array_map('intval', $metadata['user_ids'] ?? []), true);
    }

    private static function matchesRole(array $metadata, ?array $user): bool
    {
        if (!$user) {
            return false;
        }
        return in_array($user['user_type'] ?? '', $metadata['role_names'] ?? [], true);
    }

    private static function matchesBranch(array $metadata, ?array $user): bool
    {
        if (!$user || $user['branch_id'] === null) {
            return false;
        }
        return in_array((int) $user['branch_id'], array_map('intval', $metadata['branch_ids'] ?? []), true);
    }

    /** Deterministic sticky assignment -- the same user always lands on the same side of the rollout for this flag, instead of flapping between requests. */
    private static function matchesPercentage(string $flagKey, int $percentage, ?array $user): bool
    {
        if (!$user || $percentage <= 0) {
            return false;
        }
        if ($percentage >= 100) {
            return true;
        }
        $bucket = hexdec(substr(md5($flagKey . '|' . $user['id']), 0, 8)) % 100;
        return $bucket < $percentage;
    }

    private static function isInternalUser(?array $user): bool
    {
        if (!$user) {
            return false;
        }
        return in_array($user['user_type'] ?? '', ['Super Admin', 'Admin', 'Developer'], true);
    }
}
