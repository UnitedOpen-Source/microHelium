<?php

namespace App\Support;

/**
 * Matches an IP address against a rule that's either a plain address (exact
 * match) or CIDR notation (e.g. "192.168.1.0/24") -- used to enforce
 * Site::ip_address (issue #50). Works for both IPv4 and IPv6 since it
 * compares the inet_pton() binary representation rather than parsing
 * dotted-decimal by hand.
 */
class NetworkMatcher
{
    public static function matches(string $ip, string $rule): bool
    {
        if (!str_contains($rule, '/')) {
            return $ip === $rule;
        }

        [$subnet, $bits] = explode('/', $rule, 2);
        $bits = (int) $bits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $totalBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $totalBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        $remainderBits = $bits % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainderBits === 0) {
            return true;
        }

        $mask = (~(0xFF >> $remainderBits)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }
}
