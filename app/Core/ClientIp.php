<?php

namespace App\Core;

/**
 * Resolves the real client IP for a request that may be proxied through
 * Cloudflare (confirmed: production traffic is). Never trusts
 * CF-Connecting-IP blindly -- an attacker connecting directly to the
 * origin (bypassing Cloudflare) could set that header to anything. It is
 * only trusted when the immediate TCP connection (REMOTE_ADDR) itself
 * falls inside Cloudflare's own published IP ranges, i.e. the request
 * genuinely passed through Cloudflare's edge.
 *
 * Used by every new security-monitoring call site, plus Audit::log()
 * (switched over as a low-risk, high-value single-call-site improvement).
 * Not a retrofit of the other 370+ raw $_SERVER['REMOTE_ADDR'] reads
 * elsewhere in the app -- that's a much larger, separate change.
 *
 * Ranges from https://www.cloudflare.com/ips/ -- stable, rarely changes.
 */
class ClientIp
{
    private const CLOUDFLARE_IPV4_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    ];

    private const CLOUDFLARE_IPV6_RANGES = [
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public static function resolve(): string
    {
        $remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $forwarded = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null;

        if ($forwarded && $remoteAddr !== '' && self::isCloudflareIp($remoteAddr)) {
            $forwarded = trim((string) $forwarded);
            if (filter_var($forwarded, FILTER_VALIDATE_IP)) {
                return $forwarded;
            }
        }

        return $remoteAddr;
    }

    private static function isCloudflareIp(string $ip): bool
    {
        if (str_contains($ip, ':')) {
            foreach (self::CLOUDFLARE_IPV6_RANGES as $range) {
                if (self::ipInRange($ip, $range)) {
                    return true;
                }
            }
            return false;
        }
        foreach (self::CLOUDFLARE_IPV4_RANGES as $range) {
            if (self::ipInRange($ip, $range)) {
                return true;
            }
        }
        return false;
    }

    private static function ipInRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $bits = (int) $bits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $byteLen = intdiv($bits, 8);
        $remainderBits = $bits % 8;

        if ($byteLen > 0 && substr($ipBin, 0, $byteLen) !== substr($subnetBin, 0, $byteLen)) {
            return false;
        }
        if ($remainderBits === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainderBits)) & 0xFF);
        return (substr($ipBin, $byteLen, 1) & $mask) === (substr($subnetBin, $byteLen, 1) & $mask);
    }
}
