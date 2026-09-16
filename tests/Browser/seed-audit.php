<?php

use App\Models\Contest;
use App\Models\Language;
use App\Models\Site;
use Helium\User;
use Illuminate\Contracts\Console\Kernel;

// Explicit local fixture. Refuses other environments and database paths.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment(['local', 'testing']) || config('database.default') !== 'sqlite' || realpath(config('database.connections.sqlite.database')) !== realpath(__DIR__.'/../../database/audit.sqlite')) {
    throw new RuntimeException('Browser audit fixtures require the isolated database/audit.sqlite database.');
}
$contest = Contest::firstOrCreate(['name' => 'Auditoria de interface'], [
    'start_time' => now()->subHours(7), 'duration' => 300, 'freeze_time' => 60,
    'penalty' => 20, 'max_file_size' => 100, 'is_active' => true, 'is_public' => true,
]);
User::updateOrCreate(['email' => 'ui-audit@example.test'], [
    'username' => 'ui-audit', 'fullname' => 'Admin de revisão', 'password' => bcrypt('audit-local-only'),
    'user_type' => 'admin', 'is_enabled' => true, 'contest_id' => $contest->id,
]);
foreach (['Sede Norte', 'Sede Sul'] as $name) {
    Site::firstOrCreate(['contest_id' => $contest->id, 'name' => $name], ['is_active' => true, 'permit_logins' => true, 'max_judge_wait_time' => 900]);
}
foreach (['C' => 'c', 'Python' => 'py'] as $name => $extension) {
    Language::firstOrCreate(['contest_id' => $contest->id, 'extension' => $extension], ['name' => $name, 'compile_command' => 'true', 'run_command' => 'true', 'is_active' => true]);
}
echo "Local browser audit fixtures ready.\n";
