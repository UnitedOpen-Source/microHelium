<?php

namespace Database\Seeders;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Site;
use App\Models\TestCase;
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
        if (app()->environment('production')) {
            $this->command?->warn('Skipping demo data: DatabaseSeeder creates accounts with well-known default passwords and must not run in production.');

            return;
        }

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

        // Placeholders here ({source}, {output}, {basename}, {executable}, {classname},
        // {memory}) must match what App\Services\AutoJudgeService actually substitutes --
        // see buildCompileCommand()/executeProgram(). Python has no real compile step, so
        // "compile_command" runs a syntax check instead (matching Language::getDefaultLanguages()).
        $languages = [
            ['name' => 'C', 'extension' => 'c', 'compile_command' => 'gcc -O2 -o {output} {source} -lm', 'run_command' => './{executable}'],
            ['name' => 'C++', 'extension' => 'cpp', 'compile_command' => 'g++ -O2 -o {output} {source}', 'run_command' => './{executable}'],
            ['name' => 'Java', 'extension' => 'java', 'compile_command' => 'javac {source}', 'run_command' => 'java {classname}'],
            ['name' => 'Python 3', 'extension' => 'py', 'compile_command' => 'python3 -m py_compile {source}', 'run_command' => 'python3 {source}'],
        ];

        foreach ($languages as $language) {
            Language::updateOrCreate(
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

        $this->seedDemoProblems($contest);

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

    /**
     * Seed two real Problem rows (with test case files on disk) for the demo
     * contest, so the actual submit -> AutoJudgeService -> verdict pipeline
     * (see App\Http\Controllers\SubmitController) can be exercised end to
     * end on a fresh install, not just the problem bank library.
     */
    private function seedDemoProblems(Contest $contest): void
    {
        $problems = [
            [
                'short_name' => 'A',
                'name' => 'A+B',
                'basename' => 'aplusb',
                'description' => "Leia dois inteiros A e B e imprima a soma A + B.\n\nEntrada: uma linha com dois inteiros separados por espaco.\nSaida: um inteiro, a soma dos dois valores.",
                'color_name' => 'Vermelho',
                'color_hex' => '#EF4444',
                'time_limit' => 1,
                'memory_limit' => 256,
                'sort_order' => 1,
                'cases' => [
                    ['input' => "3 5\n", 'output' => "8\n", 'is_sample' => true],
                    ['input' => "10 20\n", 'output' => "30\n", 'is_sample' => true],
                    ['input' => "-7 7\n", 'output' => "0\n", 'is_sample' => false],
                ],
            ],
            [
                'short_name' => 'B',
                'name' => 'Fatorial',
                'basename' => 'fatorial',
                'description' => "Leia um inteiro N (0 <= N <= 12) e imprima N!.\n\nEntrada: uma linha com um inteiro N.\nSaida: um inteiro, o valor de N fatorial.",
                'color_name' => 'Azul',
                'color_hex' => '#3B82F6',
                'time_limit' => 1,
                'memory_limit' => 256,
                'sort_order' => 2,
                'cases' => [
                    ['input' => "5\n", 'output' => "120\n", 'is_sample' => true],
                    ['input' => "0\n", 'output' => "1\n", 'is_sample' => true],
                    ['input' => "10\n", 'output' => "3628800\n", 'is_sample' => false],
                ],
            ],
        ];

        foreach ($problems as $data) {
            $cases = $data['cases'];
            unset($data['cases']);

            $problem = Problem::updateOrCreate(
                ['contest_id' => $contest->id, 'short_name' => $data['short_name']],
                $data + ['contest_id' => $contest->id, 'auto_judge' => true, 'is_fake' => false]
            );

            foreach ($cases as $number => $case) {
                $number++;
                $inputRelative = "problems/{$contest->id}/{$problem->basename}/input/{$number}";
                $outputRelative = "problems/{$contest->id}/{$problem->basename}/output/{$number}";

                $inputPath = storage_path("app/{$inputRelative}");
                $outputPath = storage_path("app/{$outputRelative}");

                foreach ([$inputPath, $outputPath] as $path) {
                    if (!is_dir(dirname($path))) {
                        mkdir(dirname($path), 0755, true);
                    }
                }

                file_put_contents($inputPath, $case['input']);
                file_put_contents($outputPath, $case['output']);

                TestCase::updateOrCreate(
                    ['problem_id' => $problem->id, 'number' => $number],
                    [
                        'input_file' => $inputRelative,
                        'output_file' => $outputRelative,
                        'input_hash' => hash('sha256', $case['input']),
                        'output_hash' => hash('sha256', $case['output']),
                        'is_sample' => $case['is_sample'],
                    ]
                );
            }
        }
    }
}
