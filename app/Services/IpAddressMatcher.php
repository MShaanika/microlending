<?php

namespace App\Services;

/**
 * Matches a client IP against a branch's configured allow-list entries.
 * Each entry is either a bare IPv4 address ("41.182.1.5") or CIDR
 * notation ("41.182.1.0/24") -- IPv6 addresses never match (this app's
 * branches are single small-office networks, IPv4-only in practice).
 */
class IpAddressMatcher
{
    public static function matchesAny(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::matches($ip, is_array($range) ? ($range['ip_range'] ?? '') : $range)) {
                return true;
            }
        }
        return false;
    }

    public static function matches(string $ip, string $range): bool
    {
        $range = trim($range);
        if ($range === '' || $ip === '') {
            return false;
        }

        if (!str_contains($range, '/')) {
            return $ip === $range;
        }

        [$subnet, $maskBits] = explode('/', $range, 2);
        $maskBits = (int) $maskBits;

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false || $maskBits < 0 || $maskBits > 32) {
            return false;
        }

        $mask = $maskBits === 0 ? 0 : (-1 << (32 - $maskBits));
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
