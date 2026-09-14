<?php

/**
 * Seeds the scenario tests/E2E/JudgehostOverRealHttpTest.php drives.
 *
 * A script rather than test code because it has to run in the SERVER's
 * process, against the file database that process uses -- the suite's own
 * connection is :memory:, which no other process can see.
 *
 * Writes the issued token and run id where the test can read them.
 */
use App\Models\Answer;
use App\Models\Contest;
use App\Models\Judgehost;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use App\Models\TestCase as ProblemTestCase;
use Helium\User;
use Illuminate\Contracts\Console\Kernel;

$base = dirname(__DIR__, 3);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$out = $argv[1] ?? throw new RuntimeException('need an output directory');

$contest = Contest::factory()->create(['is_active' => true, 'start_time' => now()->subMinutes(5)]);
$site = Site::factory()->create(['contest_id' => $contest->id]);
$problem = Problem::factory()->create([
    'contest_id' => $contest->id,
    'auto_judge' => true,
    'time_limit' => 5,
    'memory_limit' => 256,
    'basename' => 'e2e',
]);
$language = Language::factory()->create([
    'contest_id' => $contest->id,
    'extension' => 'sh',
    'name' => 'Shell',
    'compile_command' => 'true',
    'run_command' => 'bash {source}',
]);
// The whole verdict vocabulary, not just the two this scenario expects.
// With only AC and WA, a judging that goes wrong is refused with a 422 for
// an unknown verdict, and the test reports "could not report" instead of
// the verdict it actually produced -- which is the thing worth knowing.
foreach ([['AC', 'Yes', true], ['WA', 'No', false], ['TLE', 'Time limit exceeded', false],
    ['RE', 'Runtime error', false], ['CE', 'Compilation error', false],
    ['MLE', 'Memory limit exceeded', false], ['PE', 'Presentation error', false],
    ['CS', 'Contest system error', false]] as [$short, $name, $accepted]) {
    Answer::factory()->create([
        'contest_id' => $contest->id,
        'short_name' => $short,
        'name' => $name,
        'is_accepted' => $accepted,
    ]);
}

$dir = storage_path('app/e2e_judgehost');
@mkdir($dir, 0755, true);
file_put_contents($dir.'/sol.sh', "read a b\necho \$((a + b))\n");
file_put_contents($dir.'/1.in', "3 4\n");
file_put_contents($dir.'/1.out', "7\n");

// A custom checker, so the run also exercises the package fetch from #120:
// the agent has to notice the problem declares one, fetch it, and judge
// with it. Judging without it would still say AC here, so the test asserts
// the fetch happened rather than trusting the verdict alone.
$compare = storage_path("app/problems/{$contest->id}/e2e/compare");
@mkdir($compare, 0755, true);
file_put_contents($compare.'/sh', "#!/bin/sh\ndiff -wB \"\$2\" \"\$3\" > /dev/null\n");
@chmod($compare.'/sh', 0755);

ProblemTestCase::create([
    'problem_id' => $problem->id,
    'number' => 1,
    'input_file' => 'e2e_judgehost/1.in',
    'output_file' => 'e2e_judgehost/1.out',
    'input_hash' => hash_file('sha256', $dir.'/1.in'),
    'output_hash' => hash_file('sha256', $dir.'/1.out'),
    'is_sample' => false,
]);

$team = User::factory()->create([
    'contest_id' => $contest->id,
    'site_id' => $site->id,
    'user_type' => 'team',
]);

$run = Run::factory()->create([
    'contest_id' => $contest->id,
    'site_id' => $site->id,
    'user_id' => $team->user_id,
    'problem_id' => $problem->id,
    'language_id' => $language->id,
    'status' => 'pending',
    'answer_id' => null,
    'filename' => 'sol.sh',
    'source_file' => 'e2e_judgehost/sol.sh',
    'source_hash' => hash_file('sha256', $dir.'/sol.sh'),
]);

[$judgehost, $token] = Judgehost::issue('judge-e2e');

file_put_contents($out.'/token', $token);
file_put_contents($out.'/run_id', (string) $run->id);

echo "seeded\n";
