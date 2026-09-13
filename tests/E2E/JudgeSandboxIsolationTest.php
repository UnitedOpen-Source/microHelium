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
use Tests\Concerns\RequiresJudgeSandbox;
use Tests\TestCase;

/**
 * Issue #49 -- per-language E2E proof that a submission cannot touch the
 * filesystem outside its own run directory, and that an honest solution in
 * the same language still gets AC through the sandbox.
 *
 * The escape payloads cover the ten active languages whose toolchains come
 * straight from the Alpine package set: C, C++, C++17, Java, Python, Node,
 * Rust, Go, PHP and Ruby. The other four active languages (TypeScript,
 * Kotlin, C# and Pascal) get their sandboxed compile-and-run coverage from
 * MultiLanguageJudgingTest, which judges all fourteen inside the sandbox;
 * there is no reason to expect the kernel to enforce a mount differently
 * per language runtime, so the escape matrix stops at the toolchains that
 * need no out-of-band install.
 *
 * The escape target is deliberately a path under /etc, which the sandbox
 * mounts read-only as part of the system image. Picking a target matters
 * more than it looks: /tmp is a private tmpfs inside the sandbox and writing
 * there is legitimately allowed, and any directory bwrap had to create as a
 * mount point (the application root among them, since the test case input is
 * bound in from under it) exists only inside the sandbox and is writable
 * there without any of it reaching the host. A write into the read-only
 * system image is unambiguous: it is "não alterar imagem/host" failing, and
 * it fails deterministically in every language.
 */
class JudgeSandboxIsolationTest extends TestCase
{
    use RefreshDatabase;
    use RequiresJudgeSandbox;

    protected function setUp(): void
    {
        $this->skipUnlessJudgeSandboxAvailable();

        parent::setUp();

        $this->enableJudgeSandbox();
    }

    public static function escapeAttemptPayloads(): array
    {
        return [
            'c_gcc13' => ['c_gcc13', 'escape.c', "#include <stdio.h>\nint main(){FILE *f = fopen(\"{ESCAPE_TARGET}\", \"w\"); if(f) { fprintf(f, \"hacked\"); fclose(f); } return 0;}\n"],
            'cpp_gpp13' => ['cpp_gpp13', 'escape.cpp', "#include <fstream>\nint main(){std::ofstream f(\"{ESCAPE_TARGET}\"); if(f) f << \"hacked\"; return 0;}\n"],
            'cpp17_gpp' => ['cpp17_gpp', 'escape.cpp', "#include <fstream>\nint main(){std::ofstream f(\"{ESCAPE_TARGET}\"); if(f) f << \"hacked\"; return 0;}\n"],
            'java21' => ['java21', 'Main.java', "import java.io.*;\npublic class Main {\n  public static void main(String[] args) throws Exception {\n    FileWriter f = new FileWriter(\"{ESCAPE_TARGET}\"); f.write(\"hacked\"); f.close();\n  }\n}\n"],
            'py3' => ['py3', 'escape.py', "with open('{ESCAPE_TARGET}', 'w') as f:\n    f.write('hacked')\nprint('8')\n"],
            'js_node24' => ['js_node24', 'escape.js', "require('fs').writeFileSync('{ESCAPE_TARGET}', 'hacked'); console.log('8');\n"],
            'rs' => ['rs', 'escape.rs', "use std::fs::File;\nuse std::io::Write;\nfn main() {\n    let mut file = File::create(\"{ESCAPE_TARGET}\").unwrap(); file.write_all(b\"hacked\").unwrap();\n    println!(\"8\");\n}\n"],
            'go' => ['go', 'escape.go', "package main\nimport (\"fmt\"; \"os\")\nfunc main() {\n  err := os.WriteFile(\"{ESCAPE_TARGET}\", []byte(\"hacked\"), 0644); if err != nil { panic(err) }\n  fmt.Println(\"8\")\n}\n"],
            'php' => ['php', 'escape.php', "<?php\nfile_put_contents('{ESCAPE_TARGET}', 'hacked') or die('failed');\necho '8', PHP_EOL;\n"],
            'rb' => ['rb', 'escape.rb', "File.write('{ESCAPE_TARGET}', 'hacked')\nputs '8'\n"],
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
        $targetFile = '/etc/sandbox_jailbreak_' . $extension . '.txt';
        @unlink($targetFile);

        $escapeSource = str_replace('{ESCAPE_TARGET}', $targetFile, $escapeSource);

        $run = $this->createAndJudgeRun($extension, $filename, $escapeSource);

        // PHP caches stat() results, and the @unlink above primed that cache
        // with "absent" -- without this the assertion below would pass even
        // if the submission had just created the file.
        clearstatcache(true, $targetFile);

        $this->assertFileDoesNotExist($targetFile, "SECURITY ESCAPE FAILURE: Language {$extension} successfully wrote outside sandbox!");
        
        $this->assertNotEquals(
            'AC',
            $run->answer?->short_name,
            "Escape attempt in {$extension} was accepted, so the write it attempted must have succeeded somewhere: "
            . "{$run->auto_judge_result}\nstdout: {$run->auto_judge_stdout}\nstderr: {$run->auto_judge_stderr}"
        );
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

    public function test_custom_compile_script_runs_inside_the_sandbox()
    {
        // AutoJudgeService::compile() returns early into runCustomScript()
        // whenever a problem ships its own compile/<lang> script. That branch
        // hands untrusted submitted source to a real compiler just like the
        // default one does, so docs/specs/49-judge-isolation.md covers it
        // too: "aplicar também à compilação".
        $targetFile = '/etc/sandbox_jailbreak_compile_script.txt';
        @unlink($targetFile);
        $scriptPath = null;

        try {
            $run = $this->createAndJudgeRun(
                'py3',
                'solution.py',
                "a, b = map(int, input().split())\nprint(a + b)\n",
                function (Problem $problem) use ($targetFile, &$scriptPath) {
                    $scriptPath = $problem->getCompileScriptPath('py3');
                    @mkdir(dirname($scriptPath), 0755, true);
                    file_put_contents(
                        $scriptPath,
                        "#!/bin/bash\necho hacked > " . escapeshellarg($targetFile) . "\n"
                    );
                }
            );
        } finally {
            if ($scriptPath !== null) {
                @unlink($scriptPath);
            }
        }

        clearstatcache(true, $targetFile);

        $this->assertFileDoesNotExist(
            $targetFile,
            'SECURITY ESCAPE FAILURE: a custom compile script wrote outside the sandbox.'
        );

        // And the script really did run: writing into the read-only system
        // image failed, so the compile step failed with it. Without this the
        // assertion above would also pass for a script that never executed.
        $this->assertSame('CE', $run->answer?->short_name, $run->auto_judge_stderr);
    }

    private function createAndJudgeRun(string $extension, string $filename, string $source, ?callable $beforeJudge = null): Run
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

        if ($beforeJudge !== null) {
            $beforeJudge($problem);
        }

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
