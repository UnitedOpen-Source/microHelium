<?php

/*
 * Issue #394 -- fixtures for tests/Browser/accessibility.spec.js.
 *
 * The screens the axe scan covers (problem, submission, verdict, scoreboard,
 * backend) are empty or unreachable on a freshly migrated database, and an
 * empty page passes axe for the wrong reason. This puts exactly one of each
 * thing on screen: a running public contest, a problem with a statement, a
 * judged run, an answered clarification, a team and an administrator.
 *
 * Refuses any database other than database/browser.sqlite, the throwaway file
 * the CI `browser` job creates. The passwords below exist only there.
 */

use App\Models\Answer;
use App\Models\Clarification;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use Helium\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$expected = realpath(__DIR__.'/../../database/browser.sqlite');
if (! $app->environment(['local', 'testing'])
    || config('database.default') !== 'sqlite'
    || $expected === false
    || realpath(config('database.connections.sqlite.database')) !== $expected) {
    throw new RuntimeException('Accessibility fixtures require the throwaway database/browser.sqlite database.');
}

$contest = Contest::firstOrCreate(['name' => 'Seletiva de acessibilidade'], [
    'description' => 'Competição de exemplo para a verificação automática de acessibilidade.',
    'start_time' => now()->subHour(), 'duration' => 300, 'freeze_time' => 60,
    'penalty' => 20, 'max_file_size' => 100, 'is_active' => true, 'is_public' => true,
]);

$site = Site::firstOrCreate(['contest_id' => $contest->id, 'name' => 'Sede principal'], [
    'is_active' => true, 'permit_logins' => true, 'max_judge_wait_time' => 900,
]);

$language = Language::firstOrCreate(['contest_id' => $contest->id, 'extension' => 'py'], [
    'name' => 'Python 3', 'compile_command' => 'true', 'run_command' => 'python3 %s', 'is_active' => true,
]);

$problem = Problem::firstOrCreate(['contest_id' => $contest->id, 'short_name' => 'A'], [
    'name' => 'Soma de dois números',
    'basename' => 'soma',
    'description' => "Leia dois inteiros A e B e imprima A + B.\n\nEntrada: uma linha com A e B.\nSaída: uma linha com a soma.",
    'color_name' => 'azul',
    'color_hex' => '#1d4ed8',
    'time_limit' => 1,
    'memory_limit' => 256,
]);

$accepted = Answer::firstOrCreate(['contest_id' => $contest->id, 'short_name' => 'AC'], [
    'name' => 'Aceito', 'is_accepted' => true, 'counts_as_attempt' => true, 'is_fake' => false, 'sort_order' => 1,
]);

$team = User::updateOrCreate(['email' => 'a11y-team@example.test'], [
    'username' => 'a11y-team', 'fullname' => 'Equipe Acessível', 'password' => bcrypt('a11y-local-only'),
    'user_type' => 'team', 'is_enabled' => true, 'contest_id' => $contest->id, 'site_id' => $site->id,
]);

User::updateOrCreate(['email' => 'a11y-admin@example.test'], [
    'username' => 'a11y-admin', 'fullname' => 'Administração de exemplo', 'password' => bcrypt('a11y-local-only'),
    'user_type' => 'admin', 'is_enabled' => true, 'contest_id' => $contest->id, 'site_id' => $site->id,
]);

Run::firstOrCreate(['contest_id' => $contest->id, 'run_number' => 1], [
    'site_id' => $site->id, 'user_id' => $team->getKey(), 'problem_id' => $problem->id,
    'language_id' => $language->id, 'answer_id' => $accepted->id,
    'filename' => 'soma.py', 'source_file' => 'runs/a11y-soma.py',
    'source_hash' => hash('sha256', 'print(sum(map(int, input().split())))'),
    'contest_time' => 12 * 60, 'judged_time' => 13 * 60, 'status' => 'judged',
]);

Clarification::firstOrCreate(['contest_id' => $contest->id, 'clarification_number' => 1], [
    'site_id' => $site->id, 'user_id' => $team->getKey(), 'problem_id' => $problem->id,
    'category' => 'problem', 'question' => 'A e B podem ser negativos?',
    'answer' => 'Sim, ambos cabem num inteiro de 32 bits.',
    'contest_time' => 20 * 60, 'answered_time' => 22 * 60, 'status' => 'answered',
]);

echo "Accessibility fixtures ready.\n";
