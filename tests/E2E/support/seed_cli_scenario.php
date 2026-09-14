<?php

/**
 * Seeds the scenario tests/E2E/CliClientOverRealHttpTest.php drives (#145).
 *
 * A script rather than test code for the same reason as
 * seed_judgehost_scenario.php: it has to run in the SERVER's process,
 * against the file database that process uses. The suite's own connection
 * is :memory:, which no other process can see -- and a CLI client that
 * cannot see the database is exactly the thing under test.
 *
 * Deliberately NOT auto-judged: this test is about the client and the HTTP
 * contract, and wiring in the judge would make it need bubblewrap and GNU
 * time and skip on every developer machine. The verdict is written straight
 * into the database by the test, standing in for the judge.
 */

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Site;
use Helium\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

$base = dirname(__DIR__, 3);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$out = $argv[1] ?? throw new RuntimeException('need an output directory');

$contest = Contest::factory()->create([
    'is_active' => true,
    'is_public' => false,
    'start_time' => now()->subMinutes(5),
    'duration' => 300,
]);

$site = Site::factory()->create(['contest_id' => $contest->id]);

$problem = Problem::factory()->create([
    'contest_id' => $contest->id,
    'short_name' => 'A',
    'name' => 'Soma',
    // The client must not need the judge to exist. See the header.
    'auto_judge' => false,
    'basename' => 'cli',
]);

$language = Language::factory()->create([
    'contest_id' => $contest->id,
    'extension' => 'sh',
    'name' => 'Shell',
    'compile_command' => 'true',
    'run_command' => 'bash {source}',
    'is_active' => true,
]);

// An inactive language with a DIFFERENT extension: if the client ever
// resolves `.py` to a language the contest does not offer, the submission
// would be refused by the server with a 422 the team cannot act on. The
// client is supposed to say so itself, before sending anything.
Language::factory()->create([
    'contest_id' => $contest->id,
    'extension' => 'py',
    'name' => 'Python (desativado)',
    'compile_command' => 'true',
    'run_command' => 'python3 {source}',
    'is_active' => false,
]);

foreach ([['AC', 'Yes', true], ['WA', 'No', false]] as [$short, $name, $accepted]) {
    Answer::factory()->create([
        'contest_id' => $contest->id,
        'short_name' => $short,
        'name' => $name,
        'is_accepted' => $accepted,
    ]);
}

$team = User::factory()->create([
    'contest_id' => $contest->id,
    'site_id' => $site->id,
    'user_type' => 'team',
    'username' => 'equipe01',
    'password' => Hash::make('senha-da-equipe'),
    'is_enabled' => true,
]);

file_put_contents($out.'/contest_id', (string) $contest->id);
file_put_contents($out.'/problem_id', (string) $problem->id);
file_put_contents($out.'/language_id', (string) $language->id);
file_put_contents($out.'/user_id', (string) $team->user_id);

echo "seeded\n";
