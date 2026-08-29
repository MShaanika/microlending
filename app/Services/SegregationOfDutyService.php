<?php

namespace App\Services;

use App\Core\Database;

/**
 * Detects conflicting-permission pairs (Part 79) -- flags, never blocks.
 * No conflict pairs are seeded (segregation_of_duty_rules starts empty):
 * what actually conflicts is an organizational policy decision this app
 * cannot guess (Part 92), so an administrator defines pairs explicitly
 * before this service reports anything.
 */
class SegregationOfDutyService
{
    /** @return list<array{rule_name:string, permission_key_a:string, permission_key_b:string}> */
    public static function conflictsFor(int $userId): array
    {
        $db = Database::connection();
        $held = $db->prepare(
            "SELECT DISTINCT p.permission_key FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = ?"
        );
        $held->execute([$userId]);
        $heldKeys = $held->fetchAll(\PDO::FETCH_COLUMN);

        return self::conflictsAmong($heldKeys);
    }

    /** Used when granting a NEW permission set (e.g. a delegation) that hasn't been persisted yet -- checks the user's existing permissions plus the proposed additions together. */
    public static function conflictsIntroducedByDelegation(int $delegateUserId, array $newPermissionKeys): array
    {
        $existing = self::conflictsFor($delegateUserId); // baseline, for reference only
        $combined = array_unique(array_merge(self::heldPermissionKeys($delegateUserId), $newPermissionKeys));
        $withDelegation = self::conflictsAmong($combined);

        // Only the conflicts genuinely introduced by this delegation, not ones that already existed.
        return array_udiff($withDelegation, $existing, static fn ($a, $b) => $a['rule_name'] <=> $b['rule_name']);
    }

    private static function heldPermissionKeys(int $userId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT DISTINCT p.permission_key FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = ?"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private static function conflictsAmong(array $permissionKeys): array
    {
        if (empty($permissionKeys)) {
            return [];
        }
        $db = Database::connection();
        $rules = $db->query("SELECT * FROM segregation_of_duty_rules WHERE is_active = 1")->fetchAll();

        $conflicts = [];
        foreach ($rules as $rule) {
            if (in_array($rule['permission_key_a'], $permissionKeys, true) && in_array($rule['permission_key_b'], $permissionKeys, true)) {
                $conflicts[] = [
                    'rule_name' => $rule['rule_name'],
                    'permission_key_a' => $rule['permission_key_a'],
                    'permission_key_b' => $rule['permission_key_b'],
                ];
            }
        }
        return $conflicts;
    }
}
