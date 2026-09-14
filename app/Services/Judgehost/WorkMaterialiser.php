<?php

namespace App\Services\Judgehost;

use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\TestCase as ProblemTestCase;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use RuntimeException;

/**
 * Issue #116 -- turn a work payload into something AutoJudgeService can
 * judge, without a database.
 *
 * Every model here is built with `new` and never saved. #119 measured that
 * judging issues no queries at all once the relations are set, which is
 * what lets a judgehost run in a partner institution's rack with no
 * credentials to the contest database.
 *
 * The models carry paths rather than bytes -- getSourcePath(),
 * getInputPath(), getOutputPath() and getPackagePath() all resolve under
 * storage_path('app') -- so this writes what it fetched to those paths and
 * lets the judge read them exactly as it would on the server.
 */
class WorkMaterialiser
{
    public function __construct(
        private JudgehostClient $client,
        private ?string $workspace = null,
    ) {
        $this->workspace = trim($this->workspace ?? (string) config('judgehost.agent.workspace', 'judgehost'), '/');
    }

    /**
     * Fetch everything the payload names and build the run.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws RuntimeException when something the payload promised cannot be
     *                          obtained or does not match its digest. The
     *                          agent gives the run back rather than judging
     *                          it half-equipped.
     */
    public function materialise(array $payload): Run
    {
        $runId = (int) $payload['run_id'];
        $contestId = (int) $payload['contest_id'];

        $language = new Language([
            'extension' => $payload['language']['extension'],
            'compile_command' => $payload['language']['compile_command'],
            'run_command' => $payload['language']['run_command'],
            'contest_id' => $contestId,
        ]);
        $language->id = (int) $payload['language']['id'];

        $problem = new Problem([
            'contest_id' => $contestId,
            'short_name' => $payload['problem']['short_name'] ?? null,
            'time_limit' => (int) $payload['problem']['time_limit'],
            'memory_limit' => (int) $payload['problem']['memory_limit'],
            'auto_judge' => true,
            // The agent chooses its own basename, so getPackagePath() lands
            // somewhere it controls. The leading segment cannot collide with
            // a real problem package even when the agent shares a machine
            // with the server.
            'basename' => '_judgehost/p'.(int) $payload['problem']['id'],
        ]);
        $problem->id = (int) $payload['problem']['id'];

        // Both relations pre-set: an empty languageLimits means the payload's
        // limits are used as-is, which is right -- the server already applied
        // any per-language override when it built them.
        $problem->setRelation('languageLimits', new EloquentCollection);

        $this->fetchPackage($runId, $problem, $payload, (string) $language->extension);

        $run = new Run([
            'contest_id' => $contestId,
            'run_number' => $payload['run_number'] ?? null,
            'filename' => $payload['source']['filename'],
            'source_file' => $this->fetchSource($runId, $payload),
            'source_hash' => $payload['source']['sha256'] ?? null,
        ]);
        $run->id = $runId;

        $problem->setRelation('testCases', $this->fetchTestCases($runId, $problem));

        $run->setRelation('problem', $problem);
        $run->setRelation('language', $language);

        return $run;
    }

    /**
     * Issue #120 -- every custom script the payload declares, or the run is
     * not judgeable here.
     *
     * The hooks are applied by AutoJudgeService behind a file_exists() that
     * falls through to the default, so a missing compare script does not
     * fail: it silently judges by different rules and marks a correct
     * submission WA. Refusing the run is the only safe response to not
     * having one.
     *
     * @param  array<string, mixed>  $payload
     */
    private function fetchPackage(int $runId, Problem $problem, array $payload, string $extension): void
    {
        $manifest = $payload['problem']['package'] ?? null;

        if ($manifest === null) {
            // A server older than #120 does not say, and "no answer" is not
            // "no scripts" -- it is exactly the case that produced a wrong
            // verdict, so it is refused rather than assumed.
            throw new UnjudgeableRun(
                'pacote_indisponivel',
                'O servidor nao informa quais scripts customizados o problema usa; '
                .'julgar sem essa informacao pode produzir veredito errado (#120).'
            );
        }

        foreach (['compile', 'run', 'compare'] as $hook) {
            $declared = $manifest[$hook] ?? null;

            if ($declared === null) {
                continue;
            }

            $path = match ($hook) {
                'compile' => $problem->getCompileScriptPath($extension),
                'run' => $problem->getRunScriptPath($extension),
                'compare' => $problem->getCompareScriptPath($extension),
            };

            // Kept by digest across runs: scripts are per problem and per
            // language, so a host judging many runs of one problem fetches
            // each once, and a re-imported package invalidates it by itself.
            if (is_file($path) && hash_file('sha256', $path) === ($declared['sha256'] ?? null)) {
                continue;
            }

            try {
                $bytes = $this->client->packageScript($runId, $hook);
            } catch (\Throwable $e) {
                throw new UnjudgeableRun('pacote_indisponivel', $e->getMessage(), previous: $e);
            }

            $this->assertDigest($bytes, $declared['sha256'] ?? null, "script {$hook}");

            $this->put($path, $bytes);
            @chmod($path, 0755);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return string the path, relative to storage/app, that the Run names
     */
    private function fetchSource(int $runId, array $payload): string
    {
        $relative = $this->workspace.'/run_'.$runId.'/'.basename((string) $payload['source']['filename']);

        $bytes = $this->client->sourceBytes($runId);
        $this->assertDigest($bytes, $payload['source']['sha256'] ?? null, 'fonte');

        $this->put(storage_path('app/'.$relative), $bytes);

        return $relative;
    }

    /**
     * @return EloquentCollection<int, ProblemTestCase>
     */
    private function fetchTestCases(int $runId, Problem $problem): EloquentCollection
    {
        $cases = new EloquentCollection;

        foreach ($this->client->testCases($runId) as $case) {
            $testCase = new ProblemTestCase([
                'problem_id' => $problem->id,
                'number' => (int) $case['number'],
                'is_sample' => (bool) ($case['is_sample'] ?? false),
                'input_file' => $this->cached($runId, (int) $case['id'], 'input', $case['input']['sha256'] ?? null),
                'output_file' => $this->cached($runId, (int) $case['id'], 'output', $case['output']['sha256'] ?? null),
                'input_hash' => $case['input']['sha256'] ?? null,
                'output_hash' => $case['output']['sha256'] ?? null,
            ]);
            $testCase->id = (int) $case['id'];

            $cases->push($testCase);
        }

        if ($cases->isEmpty()) {
            throw new UnjudgeableRun('pacote_indisponivel', 'O problema nao tem casos de teste disponiveis para este judgehost.');
        }

        return $cases;
    }

    /**
     * A test-case file, kept under its own digest.
     *
     * This is the reason a judgehost is worth keeping warm: a host judging
     * 200 submissions of one problem downloads its test data once, and the
     * digest is the cache key so re-uploaded test data invalidates itself
     * without anyone having to remember to clear anything.
     */
    private function cached(int $runId, int $testCaseId, string $kind, ?string $sha256): string
    {
        if ($sha256 === null || ! preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new UnjudgeableRun('transferencia_corrompida', "O servidor nao informou o digest do caso de teste {$testCaseId} ({$kind}).");
        }

        $relative = $this->workspace.'/cache/'.substr($sha256, 0, 2).'/'.$sha256;
        $absolute = storage_path('app/'.$relative);

        if (is_file($absolute) && hash_file('sha256', $absolute) === $sha256) {
            return $relative;
        }

        $bytes = $this->client->testCaseBytes($runId, $testCaseId, $kind);
        $this->assertDigest($bytes, $sha256, "caso de teste {$testCaseId} ({$kind})");

        $this->put($absolute, $bytes);

        return $relative;
    }

    /**
     * The digests the server sends are not decoration: they are how the
     * agent knows a truncated transfer from a complete one. Judging half a
     * test-case file produces a confident, wrong verdict.
     */
    private function assertDigest(string $bytes, ?string $expected, string $what): void
    {
        if ($expected === null || $expected === '') {
            return;
        }

        $actual = hash('sha256', $bytes);

        if (! hash_equals($expected, $actual)) {
            throw new UnjudgeableRun('transferencia_corrompida', "O {$what} recebido nao confere com o digest informado pelo servidor.");
        }
    }

    private function put(string $absolute, string $bytes): void
    {
        $dir = dirname($absolute);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Nao foi possivel criar {$dir}.");
        }

        if (@file_put_contents($absolute, $bytes) === false) {
            throw new RuntimeException("Nao foi possivel escrever {$absolute}.");
        }
    }

    /**
     * Removes what was written for one run. The digest cache is deliberately
     * left alone -- it is the thing worth keeping between runs.
     */
    public function cleanup(int $runId): void
    {
        $dir = storage_path('app/'.$this->workspace.'/run_'.$runId);

        foreach (glob($dir.'/*') ?: [] as $file) {
            is_dir($file) ? null : @unlink($file);
        }

        @rmdir($dir);
    }
}
