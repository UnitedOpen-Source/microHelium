<?php

namespace Database\Seeders;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Site;
use Helium\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with a demo contest so the app is
     * usable right after a fresh install (admin login, sample languages,
     * BOCA-style judging answers, and one site/team to exercise the flow).
     */
    public function run(): void
    {
        $contest = Contest::firstOrCreate(
            ['name' => 'MicroHelium Demo Contest'],
            [
                'description' => 'Contest de demonstração criado pelo seeder padrão.',
                'start_time' => now(),
                'duration' => 300,
                'freeze_time' => 60,
                'penalty' => 20,
                'max_file_size' => 1024,
                'is_active' => true,
                'is_public' => true,
            ]
        );

        $site = Site::firstOrCreate(
            ['contest_id' => $contest->id, 'name' => 'Sede Principal'],
            [
                'is_active' => true,
                'permit_logins' => true,
                'auto_judge' => true,
                'max_runtime' => 600,
                'chief_judge_name' => 'Admin',
            ]
        );

        $languages = [
            ['name' => 'C', 'extension' => 'c', 'compile_command' => 'gcc -O2 -o {output} {input} -lm', 'run_command' => './{output}'],
            ['name' => 'C++', 'extension' => 'cpp', 'compile_command' => 'g++ -O2 -o {output} {input}', 'run_command' => './{output}'],
            ['name' => 'Java', 'extension' => 'java', 'compile_command' => 'javac {input}', 'run_command' => 'java {class}'],
            ['name' => 'Python 3', 'extension' => 'py', 'compile_command' => null, 'run_command' => 'python3 {input}'],
        ];

        foreach ($languages as $language) {
            Language::firstOrCreate(
                ['contest_id' => $contest->id, 'name' => $language['name']],
                $language + ['contest_id' => $contest->id, 'is_active' => true]
            );
        }

        $answers = [
            ['name' => 'Yes', 'short_name' => 'AC', 'is_accepted' => true, 'is_fake' => false, 'sort_order' => 0],
            ['name' => 'No - Wrong Answer', 'short_name' => 'WA', 'is_accepted' => false, 'is_fake' => false, 'sort_order' => 1],
            ['name' => 'No - Presentation Error', 'short_name' => 'PE', 'is_accepted' => false, 'is_fake' => false, 'sort_order' => 2],
            ['name' => 'No - Compilation Error', 'short_name' => 'CE', 'is_accepted' => false, 'is_fake' => false, 'sort_order' => 3],
            ['name' => 'No - Runtime Error', 'short_name' => 'RE', 'is_accepted' => false, 'is_fake' => false, 'sort_order' => 4],
            ['name' => 'No - Time Limit Exceeded', 'short_name' => 'TLE', 'is_accepted' => false, 'is_fake' => false, 'sort_order' => 5],
            ['name' => 'No - Memory Limit Exceeded', 'short_name' => 'MLE', 'is_accepted' => false, 'is_fake' => false, 'sort_order' => 6],
            ['name' => 'No - Output Limit Exceeded', 'short_name' => 'OLE', 'is_accepted' => false, 'is_fake' => false, 'sort_order' => 7],
            ['name' => 'Judging', 'short_name' => 'JUDGING', 'is_accepted' => false, 'is_fake' => true, 'sort_order' => 8],
        ];

        foreach ($answers as $answer) {
            Answer::firstOrCreate(
                ['contest_id' => $contest->id, 'short_name' => $answer['short_name']],
                $answer + ['contest_id' => $contest->id]
            );
        }

        User::firstOrCreate(
            ['username' => 'admin'],
            [
                'fullname' => 'Administrator',
                'email' => 'admin@microhelium.local',
                'password' => Hash::make('admin123'),
                'user_type' => User::TYPE_ADMIN,
                'contest_id' => $contest->id,
                'is_enabled' => true,
            ]
        );

        User::firstOrCreate(
            ['username' => 'judge1'],
            [
                'fullname' => 'Judge One',
                'email' => 'judge1@microhelium.local',
                'password' => Hash::make('judge123'),
                'user_type' => User::TYPE_JUDGE,
                'contest_id' => $contest->id,
                'site_id' => $site->id,
                'is_enabled' => true,
            ]
        );

        User::firstOrCreate(
            ['username' => 'team1'],
            [
                'fullname' => 'Team One',
                'email' => 'team1@microhelium.local',
                'password' => Hash::make('team123'),
                'user_type' => User::TYPE_TEAM,
                'contest_id' => $contest->id,
                'site_id' => $site->id,
                'is_enabled' => true,
            ]
        );

        $this->call([
            ProblemBankSeeder::class,
            BrazilianProblemsSeeder::class,
        ]);
    }
}
