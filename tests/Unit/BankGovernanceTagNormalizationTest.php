<?php

namespace Tests\Unit;

use App\Http\Controllers\FrontendApi\BankGovernanceController;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Issue #46 -- exercises BankGovernanceController::normalizeTags() directly
 * via reflection. A genuinely malformed UTF-8 byte sequence can't reach the
 * controller through the normal HTTP JSON test client (json_encode()/
 * json_decode() already require valid UTF-8, so a real request carrying it
 * would fail to parse as JSON at all rather than deliver one bad tag) --
 * this proves the guard against it directly instead.
 */
class BankGovernanceTagNormalizationTest extends TestCase
{
    private function normalize(mixed $rawTags): array
    {
        $controller = new BankGovernanceController;
        $method = new ReflectionMethod($controller, 'normalizeTags');
        $method->setAccessible(true);

        return $method->invoke($controller, $rawTags);
    }

    public function test_malformed_utf8_tag_is_rejected_under_the_tags_key_instead_of_silently_dropped(): void
    {
        // 0xB1 is a UTF-8 continuation byte with no valid lead byte before
        // it -- not valid UTF-8 under any interpretation.
        $invalid = "grafos \xB1 dp";

        $this->assertFalse(mb_check_encoding($invalid, 'UTF-8'));

        try {
            $this->normalize([$invalid]);
            $this->fail('Expected a ValidationException for malformed UTF-8 input.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('tags', $e->errors());
        }
    }

    public function test_valid_tags_still_normalize_as_before(): void
    {
        $this->assertSame(['Grafos', 'dp'], $this->normalize([' Grafos ', 'grafos', 'dp']));
    }
}
