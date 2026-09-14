<?php

namespace Tests\Unit\Services;

use App\Services\Judgehost\MachineCapabilities;
use Tests\TestCase;

/**
 * Issue #117 -- what a machine can run is found out, not configured.
 *
 * A configured list is a list someone has to remember to update, and the
 * day it is wrong a host repeatedly takes work it cannot do and hands it
 * straight back. These probe against the real shell, because the thing
 * being tested is precisely whether the probe agrees with reality.
 */
class MachineCapabilitiesTest extends TestCase
{
    private function language(string $extension, ?string $compile, ?string $run): object
    {
        return (object) [
            'extension' => $extension,
            'compile_command' => $compile,
            'run_command' => $run,
        ];
    }

    /**
     * The shape the agent actually hands it.
     *
     * JudgehostClient::languages() returns decoded JSON -- associative
     * arrays -- and the first version of this class read properties off
     * them. Every language was skipped, the agent declared nothing, and
     * because "declared nothing" legitimately means "can judge anything",
     * capability routing silently did nothing at all. The unit tests
     * passed, because they all used (object) casts. An end-to-end run
     * against a real server is what caught it.
     */
    public function test_it_reads_the_decoded_json_the_client_actually_returns(): void
    {
        $fromApi = json_decode('[{"extension":"sh","compile_command":"true","run_command":"sh {source}"}]', true);

        $this->assertSame(['sh'], (new MachineCapabilities)->detect($fromApi));
    }

    public function test_a_language_whose_toolchain_is_present_is_reported(): void
    {
        $detected = (new MachineCapabilities)->detect([
            // sh exists everywhere this runs, including CI.
            $this->language('sh', null, 'sh {source}'),
        ]);

        $this->assertSame(['sh'], $detected);
    }

    public function test_a_language_whose_toolchain_is_missing_is_not_reported(): void
    {
        $detected = (new MachineCapabilities)->detect([
            $this->language('zz', null, 'definitely-not-a-real-compiler-zz {source}'),
        ]);

        $this->assertSame([], $detected);
    }

    public function test_a_language_needs_every_command_it_uses(): void
    {
        // Half a toolchain is not a toolchain: claiming this would make the
        // host take the work and fail at compile time.
        $detected = (new MachineCapabilities)->detect([
            $this->language('half', 'definitely-not-a-real-compiler-zz {source}', 'sh {source}'),
        ]);

        $this->assertSame([], $detected);
    }

    public function test_environment_prefixes_and_placeholders_are_not_mistaken_for_the_binary(): void
    {
        // Command templates start with things that are not the program:
        // GOCACHE=... go build ..., and {judge_runtime} placeholders the
        // judge substitutes later.
        $detected = (new MachineCapabilities)->detect([
            $this->language('env', null, 'HOME={judge_runtime} TMPDIR=/tmp sh {source}'),
        ]);

        $this->assertSame(['env'], $detected);
    }

    public function test_a_language_with_no_commands_at_all_is_left_undecided(): void
    {
        // Nothing was checked, so claiming the ability would be a guess.
        // The give-back path (#125) covers it instead.
        $detected = (new MachineCapabilities)->detect([
            $this->language('none', null, null),
        ]);

        $this->assertSame([], $detected);
    }

    public function test_the_same_extension_is_reported_once(): void
    {
        $detected = (new MachineCapabilities)->detect([
            $this->language('sh', null, 'sh {source}'),
            $this->language('sh', null, 'sh {source}'),
        ]);

        $this->assertSame(['sh'], $detected);
    }
}
