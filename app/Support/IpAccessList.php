<?php

namespace App\Support;

/**
 * The comma-separated list of IPs and CIDR networks stored in
 * sites.ip_address, and the one place that decides whether such a list is
 * well formed.
 *
 * Extracted from Backend\SiteController (issue #50) when the event importer
 * (#147) needed the same check: ip_address is load-bearing for login access,
 * and a typo'd octet or an invalid prefix ("/33") saves silently and then
 * never matches any real client, locking out every user of that site with no
 * warning at save time. Two copies of that rule would mean the form and the
 * importer disagreeing about which files are safe to apply -- and the
 * importer is the path that writes forty sites at once.
 *
 * The messages are the ones the site form has always shown; they are
 * asserted verbatim by the controller's tests.
 */
final class IpAccessList
{
    /**
     * The first reason this value cannot be used as a site access list, or
     * null when it is fine (including when it is empty -- an empty list
     * means "no IP restriction").
     */
    public static function firstError(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        foreach (explode(',', (string) $value) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (str_contains($part, '/')) {
                [$subnet, $bits] = array_pad(explode('/', $part, 2), 2, null);
                $isIpv4 = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
                $isIpv6 = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
                $maxBits = $isIpv4 ? 32 : ($isIpv6 ? 128 : null);

                if ($maxBits === null || ! ctype_digit((string) $bits) || (int) $bits > $maxBits) {
                    return "\"{$part}\" nao e uma rede valida (formato esperado: IP/prefixo, ex: 192.168.1.0/24).";
                }
            } elseif (filter_var($part, FILTER_VALIDATE_IP) === false) {
                return "\"{$part}\" nao e um endereco IP valido.";
            }
        }

        return null;
    }
}
