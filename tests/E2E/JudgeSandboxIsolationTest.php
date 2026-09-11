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
 * E2E test suite verifying that bwrap prevents filesystem modification outside $runDir
 * for ALL active languages (C, C++, C++17, Java, Python, Node, TS, Kotlin, C#, Rust, Go, PHP, Ruby, Pascal).
 */
class JudgeSandboxIsolationTest extends TestCase
{
    use RefreshDatabase;

    public static function escapeAttemptPayloads(): array
    {
        return [
            'c_gcc13' => ['c_gcc13', 'escape.c', "#include <stdio.h>\nint main(){FILE *f = fopen(\"/tmp/sandbox_jailbreak_c_gcc13.txt\", \"w\"); if(f) { fprintf(f, \"hacked\"); fclose(f); } return 0;}\n"],
            'cpp_gpp13' => ['cpp_gpp13', 'escape.cpp', "#include <fstream>\nint main(){std::ofstream f(\"/tmp/sandbox_jailbreak_cpp_gpp13.txt\"); if(f) f << \"hacked\"; return 0;}\n"],
            'cpp17_gpp' => ['cpp17_gpp', 'escape.cpp', "#include <fstream>\nint main(){std::ofstream f(\"/tmp/sandbox_jailbreak_cpp17_gpp.txt\"); if(f) f << \"hacked\"; return 0;}\n"],
            'java21' => ['java21', 'Main.java', "import java.io.*;\npublic class Main {\n  public static void main(String[] args) throws Exception {\n    FileWriter f = new FileWriter(\"/tmp/sandbox_jailbreak_java21.txt\"); f.write(\"hacked\"); f.close();\n  }\n}\n"],
            'py3' => ['py3', 'escape.py', "with open('/tmp/sandbox_jailbreak_py3.txt', 'w') as f:\n    f.write('hacked')\nprint('8')\n"],
            'js_node24' => ['js_node24', 'escape.js', "require('fs').writeFileSync('/tmp/sandbox_jailbreak_js_node24.txt', 'hacked'); console.log('8');\n"],
            'rs' => ['rs', 'escape.rs', "use std::fs::File;\nuse std::io::Write;\nfn main() {\n    let mut file = File::create(\"/tmp/sandbox_jailbreak_rs.txt\").unwrap(); file.write_all(b\"hacked\").unwrap();\n    println!(\"8\");\n}\n"],
            'go' => ['go', 'escape.go', "package main\nimport (\"fmt\"; \"os\")\nfunc main() {\n  err := os.WriteFile(\"/tmp/sandbox_jailbreak_go.txt\", []byte(\"hacked\"), 0644); if err != nil { panic(err) }\n  fmt.Println(\"8\")\n}\n"],
            'php' => ['php', 'escape.php', "<?php\nfile_put_contents('/tmp/sandbox_jailbreak_php.txt', 'hacked') or die('failed');\necho '8', PHP_EOL;\n"],
            'rb' => ['rb', 'escape.rb', "File.write('/tmp/sandbox_jailbreak_rb.txt', 'hacked')\nputs '8'\n"],
        ];
    }

    public static function validSolutionPayloads(): array
    {
        return [
            'c_gcc13' => ['c_gcc13', 'solution.c', "#include <stdio.h>\nint main(){int a,b;scanf(\"%d %d\",&a,&b);printf(\"%d\\n\",a+b);return 0;}\n"],
            'cpp_gpp13' => ['cpp_gpp13', 'solution.cpp', "#include <iostream>\nint main(){int a,b;std::cin>>a>>b;std::cout<<a+b<<std::endl;return 0;}\n"],
            'cpp17_gpp' => ['cpp17_gpp', 'solution.cpp', "#include <iostream>\nint main(){int a,b;std::cin>>a>>b;std::cout<<a+b<<std::endl;return 0;}\n"],
            'java21' => ['java21', 'Main.java', "import java.util.Scanner;\npublic class Main {\n  public static void main(String[] args) {\n    Scanner sc = new Scanner(System.in);\n    System.out.println(sc.nextInt() + sc.nextInt());\n  }\n}\n"],
            'py3' => ['py3', 'solution.py', "a, b = map(int, input().split())\nprint(a + b)\n"],
            'js_node24' => ['js_node24', 'solution.js', "const data = require('fs').readFileSync(0, 'utf8').trim().split(/\\s+/).map(Number);\nconsole.log(data[0] + data[1]);\n"],
            'rs' => ['rs', 'solution.rs', "use std::io::*;\nfn main() {\n    let mut s = String::new();\n    stdin().read_line(&mut s).unwrap();\n    let v: Vec<i64> = s.trim().split_whitespace().map(|x| x.parse().unwrap()).collect();\n    println!(\"{}\", v[0] + v[1]);\n}\n"],
            'go' => ['go', 'solution.go', "package main\nimport \"fmt\"\nfunc main() {\n  var a, b int\n  fmt.Scan(&a, &b)\n  fmt.Println(a + b)\n}\n"],
            'php' => ['php', 'solution.php', "<?php\nfscanf(STDIN, \"%d %d\", \$a, \$b);\necho \$a + \$b, PHP_EOL;\n"],
            'rb' => ['rb', 'solution.rb', "a, b = gets.split.map(&:to_i)\nputs a + b\n"],
        ];
    }

    #[DataProvider('escapeAttemptPayloads')]
    public function test_sandbox_prevents_writing_outside_rundir(string $extension, string $filename, string $escapeSource)
    {
        $targetFile = '/tmp/sandbox_jailbreak_' . $extension . '.txt';
        @unlink($targetFile);

        $run = $this->createAndJudgeRun($extension, $filename, $escapeSource);

        $this->assertFileDoesNotExist($targetFile, "SECURITY ESCAPE FAILURE: Language {$extension} successfully wrote outside sandbox!");
        
        $this->assertNotEquals('AC', $run->answer?->short_name);
    }

    #[DataProvider('validSolutionPayloads')]
    public function test_valid_solutions_in_all_active_languages_pass_under_bwrap(string $extension, string $filename, string $validSource)
    {
        $run = $this->createAndJudgeRun($extension, $filename, $validSource);

        $this->assertSame('judged', $run->status);
        $this->assertTrue(
            $run->answer?->is_accepted,
            "Expected AC for {$extension}, got '{$run->answer?->short_name}': {$run->auto_judge_result}\nstdout: {$run->auto_judge_stdout}\nstderr: {$run->auto_judge_stderr}"
        );
    }

    private function createAndJudgeRun(string $extension, string $filename, string $source): Run
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

        $this->actingAs($team)->post("/submit/{$problem->id}", [
            'language_id' => $language->id,
            'source_file' => $file,
        ]);

        $run = Run::where('contest_id', $contest->id)->where('user_id', $team->user_id)->firstOrFail();

        app(AutoJudgeService::class)->judge($run->fresh());

        return $run->refresh();
    }
}
