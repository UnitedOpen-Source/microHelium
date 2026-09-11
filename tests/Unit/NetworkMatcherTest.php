<?php

namespace Tests\Unit;

use App\Support\NetworkMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NetworkMatcherTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_matches(string $ip, string $rule, bool $expected)
    {
        $this->assertSame($expected, NetworkMatcher::matches($ip, $rule));
    }

    public static function cases(): array
    {
        return [
            'exact IPv4 match' => ['192.168.1.10', '192.168.1.10', true],
            'exact IPv4 mismatch' => ['192.168.1.10', '192.168.1.11', false],
            'IPv4 CIDR match' => ['192.168.1.42', '192.168.1.0/24', true],
            'IPv4 CIDR mismatch (different subnet)' => ['192.168.2.42', '192.168.1.0/24', false],
            'IPv4 CIDR /32 exact' => ['10.0.0.5', '10.0.0.5/32', true],
            'IPv4 CIDR /32 mismatch' => ['10.0.0.6', '10.0.0.5/32', false],
            'IPv4 CIDR /0 matches anything' => ['8.8.8.8', '0.0.0.0/0', true],
            // 192.168.1.128/26 covers .128-.191 (64 addresses)
            'IPv4 CIDR non-byte-aligned bits' => ['192.168.1.130', '192.168.1.128/26', true],
            'IPv4 CIDR non-byte-aligned bits, still in range' => ['192.168.1.190', '192.168.1.128/26', true],
            'IPv4 CIDR non-byte-aligned bits mismatch' => ['192.168.1.200', '192.168.1.128/26', false],
            'exact IPv6 match' => ['::1', '::1', true],
            'IPv6 CIDR match' => ['2001:db8::1', '2001:db8::/32', true],
            'IPv6 CIDR mismatch' => ['2001:db9::1', '2001:db8::/32', false],
            'mismatched address families never match' => ['192.168.1.1', '::1/128', false],
            'invalid rule never matches' => ['192.168.1.1', 'not-an-ip', false],
            // Dual-stack listeners can surface a client IP as an
            // IPv4-mapped IPv6 address -- must still match a plain IPv4 rule.
            'IPv4-mapped IPv6 client matches a plain IPv4 rule' => ['::ffff:203.0.113.5', '203.0.113.5', true],
            'IPv4-mapped IPv6 client matches an IPv4 CIDR rule' => ['::ffff:192.168.1.42', '192.168.1.0/24', true],
            'plain IPv4 client matches an IPv4-mapped IPv6 rule' => ['203.0.113.5', '::ffff:203.0.113.5', true],
            'IPv4-mapped IPv6 mismatch still rejected' => ['::ffff:203.0.113.5', '203.0.113.6', false],
        ];
    }
}
