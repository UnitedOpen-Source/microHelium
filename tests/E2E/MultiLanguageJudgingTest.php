<?php

namespace Tests\E2E;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use App\Models\TestCase as ProblemTestCase;
use App\Services\AutoJudgeService;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every language Language::getDefaultLanguages() marks is_active is offered
 * to admins (Contest Wizard) and participants (submission form), which is a
 * promise that AutoJudgeService can actually compile and run it. This was
 * false for most of them until the toolchains were added to Dockerfile.dev
 * (only gcc/g++/python3 existed before) -- this test is the regression net
 * for that: it submits a real, correct "A + B" solution in every active
 * language and checks it actually gets judged AC, with nothing mocked.
 */
class MultiLanguageJudgingTest extends TestCase
{
    use RefreshDatabase;

    public static function activeLanguages(): array
    {
        $solutions = [
            'c_gcc13' => ['file' => 'solution.c', 'source' => "#include <stdio.h>\nint main(){int a,b;scanf(\"%d %d\",&a,&b);printf(\"%d\\n\",a+b);return 0;}\n"],
            'cpp_gpp13' => ['file' => 'solution.cpp', 'source' => "#include <iostream>\nint main(){int a,b;std::cin>>a>>b;std::cout<<a+b<<std::endl;return 0;}\n"],
            'cpp17_gpp' => ['file' => 'solution.cpp', 'source' => "#include <iostream>\nint main(){int a,b;std::cin>>a>>b;std::cout<<a+b<<std::endl;return 0;}\n"],
            'java21' => ['file' => 'Main.java', 'source' => "import java.util.Scanner;\npublic class Main {\n  public static void main(String[] args) {\n    Scanner sc = new Scanner(System.in);\n    System.out.println(sc.nextInt() + sc.nextInt());\n  }\n}\n"],
            'py3' => ['file' => 'solution.py', 'source' => "a, b = map(int, input().split())\nprint(a + b)\n"],
            'js_node24' => ['file' => 'solution.js', 'source' => "const data = require('fs').readFileSync(0, 'utf8').trim().split(/\\s+/).map(Number);\nconsole.log(data[0] + data[1]);\n"],
            // `declare function require` avoids needing @types/node (not
            // installed globally) just to read stdin in a strict-mode file.
            'ts' => ['file' => 'solution.ts', 'source' => "declare function require(name: string): any;\nconst data: number[] = require('fs').readFileSync(0, 'utf8').trim().split(/\\s+/).map(Number);\nconsole.log(data[0] + data[1]);\n"],
            'kt' => ['file' => 'solution.kt', 'source' => "fun main() {\n    val (a, b) = readLine()!!.trim().split(\" \").map { it.toInt() }\n    println(a + b)\n}\n"],
            'cs_dotnet' => ['file' => 'solution.cs', 'source' => "using System;\nclass Program {\n  static void Main() {\n    var p = Console.ReadLine().Split(' ');\n    Console.WriteLine(int.Parse(p[0]) + int.Parse(p[1]));\n  }\n}\n"],
            'rs' => ['file' => 'solution.rs', 'source' => "use std::io::*;\nfn main() {\n    let mut s = String::new();\n    stdin().read_line(&mut s).unwrap();\n    let v: Vec<i64> = s.trim().split_whitespace().map(|x| x.parse().unwrap()).collect();\n    println!(\"{}\", v[0] + v[1]);\n}\n"],
            'go' => ['file' => 'solution.go', 'source' => "package main\nimport \"fmt\"\nfunc main() {\n  var a, b int\n  fmt.Scan(&a, &b)\n  fmt.Println(a + b)\n}\n"],
            'php' => ['file' => 'solution.php', 'source' => "<?php\nfscanf(STDIN, \"%d %d\", \$a, \$b);\necho \$a + \$b, PHP_EOL;\n"],
            'rb' => ['file' => 'solution.rb', 'source' => "a, b = gets.split.map(&:to_i)\nputs a + b\n"],
            'pas_fpc' => ['file' => 'solution.pas', 'source' => "program Solution;\nvar a, b: integer;\nbegin\n  readln(a, b);\n  writeln(a + b);\nend.\n"],
        ];

        $active = collect(Language::getDefaultLanguages())->where('is_active', true)->pluck('extension');

        $cases = [];
        foreach ($active as $extension) {
            if (isset($solutions[$extension])) {
                $cases[$extension] = [$extension, $solutions[$extension]['file'], $solutions[$extension]['source']];
            }
        }

        return $cases;
    }

    /**
     * activeLanguages() silently skips any is_active language missing from
     * its $solutions map (a data provider can't fail loudly per-case), so
     * this is the actual regression net: if someone flips another language
     * to is_active without adding a solution, this fails instead of the
     * suite quietly going green while that language ships untested.
     */
    public function test_every_active_language_has_a_solution_fixture_covering_it()
    {
        $active = collect(Language::getDefaultLanguages())->where('is_active', true)->pluck('extension');
        $covered = array_keys(self::activeLanguages());

        $missing = $active->diff($covered)->values()->all();

        $this->assertEmpty($missing, 'is_active languages with no MultiLanguageJudgingTest fixture: ' . implode(', ', $missing));
    }

    #[DataProvider('activeLanguages')]
    public function test_active_language_compiles_and_judges_a_correct_solution_as_accepted(string $extension, string $filename, string $source)
    {
        $contest = Contest::factory()->create(['is_active' => true, 'start_time' => now()->subMinutes(5), 'duration' => 300]);
        $site = Site::factory()->create(['contest_id' => $contest->id]);

        foreach (Language::getDefaultLanguages() as $lang) {
            Language::create(array_merge($lang, ['contest_id' => $contest->id]));
        }
        $language = Language::where('contest_id', $contest->id)->where('extension', $extension)->firstOrFail();

        foreach ([
            ['name' => 'Accepted', 'short_name' => 'AC', 'is_accepted' => true],
            ['name' => 'Wrong Answer', 'short_name' => 'WA', 'is_accepted' => false],
            ['name' => 'Compilation Error', 'short_name' => 'CE', 'is_accepted' => false],
            ['name' => 'Runtime Error', 'short_name' => 'RE', 'is_accepted' => false],
            ['name' => 'Contest Stopped', 'short_name' => 'CS', 'is_accepted' => false],
        ] as $answer) {
            Answer::create(array_merge($answer, ['contest_id' => $contest->id]));
        }

        $problem = Problem::factory()->create(['contest_id' => $contest->id]);

        $inputRelative = "problems/{$contest->id}/{$problem->id}/input/1";
        $outputRelative = "problems/{$contest->id}/{$problem->id}/output/1";
        \Illuminate\Support\Facades\Storage::disk('local')->put($inputRelative, "3 5\n");
        \Illuminate\Support\Facades\Storage::disk('local')->put($outputRelative, "8\n");

        ProblemTestCase::create([
            'problem_id' => $problem->id,
            'number' => 1,
            'input_file' => $inputRelative,
            'output_file' => $outputRelative,
            'input_hash' => hash('sha256', "3 5\n"),
            'output_hash' => hash('sha256', "8\n"),
            'is_sample' => true,
        ]);

        $team = User::create([
            'fullname' => 'Lang Test Team',
            'username' => 'lang_test_' . $extension,
            'email' => "lang-test-{$extension}@example.com",
            'password' => bcrypt('password'),
            'user_type' => 'team',
            'is_enabled' => true,
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ]);

        \Illuminate\Support\Facades\Bus::fake();

        $file = UploadedFile::fake()->createWithContent($filename, $source);

        $submitResponse = $this->actingAs($team)->post("/submit/{$problem->id}", [
            'language_id' => $language->id,
            'source_file' => $file,
        ]);

        $submitResponse->assertRedirect();

        $run = Run::where('contest_id', $contest->id)->where('user_id', $team->user_id)->firstOrFail();

        app(AutoJudgeService::class)->judge($run->fresh());

        $run->refresh();
        $this->assertSame('judged', $run->status);
        $this->assertNotNull($run->answer_id, "no verdict produced for {$extension} -- stderr: {$run->auto_judge_stderr}");
        $this->assertTrue(
            $run->answer->is_accepted,
            "expected AC for {$extension}, got '{$run->answer?->short_name}': {$run->auto_judge_result}\nstdout: {$run->auto_judge_stdout}\nstderr: {$run->auto_judge_stderr}"
        );
    }
}
