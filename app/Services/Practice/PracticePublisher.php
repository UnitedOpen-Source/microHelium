<?php

namespace App\Services\Practice;

use App\Models\PracticePublication;
use App\Models\Problem;
use App\Models\ProblemBank;
use App\Models\TestCase;
use Helium\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Issue #43 -- publishes and withdraws ProblemBank entries in the practice
 * library (docs/specs/43-practice.md).
 *
 * Publication is a snapshot, not a live view. Each publication gets its own
 * `problems` row inside the practice contest, copied from the bank at that
 * moment, and runs point at that row -- so "editar o banco depois não altera
 * desafios ou resultados existentes" holds by construction rather than by
 * anyone remembering to be careful.
 *
 * Republishing a newer bank version closes the current publication and opens
 * a new one against a fresh snapshot. Earlier submissions stay attached to
 * the problem row they were judged against: "republicação usa versão
 * publicada explícita e não reavalia silenciosamente envios anteriores".
 */
class PracticePublisher
{
    public function __construct(private PracticeContest $practiceContest) {}

    /**
     * @throws ValidationException incomplete material (422)
     * @throws ConflictHttpException stale version (409)
     */
    public function publish(ProblemBank $bank, string $version, User $actor): PracticePublication
    {
        $this->assertVersionIsCurrent($bank, $version);
        $this->assertMaterialIsComplete($bank);

        $contest = $this->practiceContest->contest();
        $hash = $this->snapshotHash($bank);

        return DB::transaction(function () use ($bank, $version, $actor, $contest, $hash) {
            $active = PracticePublication::query()
                ->where('problem_bank_id', $bank->id)
                ->whereNull('unpublished_at')
                ->lockForUpdate()
                ->latest('published_at')
                ->first();

            // Already published from exactly this material: publishing again
            // is a no-op rather than a second snapshot, so a double-click or
            // a retried request cannot fork the library.
            if ($active && $active->version === $version && $active->source_snapshot_hash === $hash) {
                return $active;
            }

            if ($active) {
                $active->update(['unpublished_at' => now()]);
            }

            $problem = $this->snapshotProblem($bank, $contest->id, $version);

            return PracticePublication::create([
                'problem_bank_id' => $bank->id,
                'problem_id' => $problem->id,
                'version' => $version,
                'published_at' => now(),
                'unpublished_at' => null,
                'published_by' => $actor->user_id,
                'source_snapshot_hash' => $hash,
            ]);
        });
    }

    /**
     * Withdraws the entry from the library. The snapshot and every run
     * against it stay exactly where they are: "retirar da biblioteca bloqueia
     * novos envios, preservando histórico privado."
     */
    public function unpublish(ProblemBank $bank, string $version, User $actor): ?PracticePublication
    {
        $this->assertVersionIsCurrent($bank, $version);

        return DB::transaction(function () use ($bank) {
            $active = PracticePublication::query()
                ->where('problem_bank_id', $bank->id)
                ->whereNull('unpublished_at')
                ->lockForUpdate()
                ->latest('published_at')
                ->first();

            if (! $active) {
                return null;
            }

            $active->update(['unpublished_at' => now()]);

            return $active->fresh();
        });
    }

    /**
     * The bank's `version` is the optimistic-concurrency token the API
     * contract passes back and forth. Acting on a stale one is a 409, not a
     * silent overwrite of whatever the entry looks like now: "409 para
     * snapshot/versão obsoleta."
     */
    private function assertVersionIsCurrent(ProblemBank $bank, string $version): void
    {
        if ((string) $bank->version !== $version) {
            throw new ConflictHttpException(
                'Este problema mudou desde que a tela foi carregada (versao atual: '
                .$bank->version.'). Recarregue antes de publicar ou retirar.'
            );
        }
    }

    /**
     * "Publicar exige statement/limites/testes/linguagens válidos [...] 422
     * para material incompleto."
     *
     * Languages are not checked per entry because they are not part of the
     * per-problem snapshot -- see PracticeContest::languages().
     */
    private function assertMaterialIsComplete(ProblemBank $bank): void
    {
        $missing = [];

        if (trim((string) $bank->description) === '') {
            $missing[] = 'o enunciado';
        }

        if ((int) $bank->time_limit <= 0) {
            $missing[] = 'o limite de tempo';
        }

        if ((int) $bank->memory_limit <= 0) {
            $missing[] = 'o limite de memoria';
        }

        if (trim((string) $bank->sample_input) === '' || trim((string) $bank->sample_output) === '') {
            $missing[] = 'o caso de teste de exemplo';
        }

        if ($missing === []) {
            return;
        }

        throw ValidationException::withMessages([
            'published' => 'Este problema ainda nao pode ir para o Treino Livre: falta '
                .implode(', ', $missing).'.',
        ]);
    }

    /**
     * Covers exactly the material that gets snapshotted, so an edit that
     * changes nothing relevant is not mistaken for a new version's worth of
     * content.
     */
    private function snapshotHash(ProblemBank $bank): string
    {
        return hash('sha256', json_encode([
            'code' => $bank->code,
            'name' => $bank->name,
            'description' => $bank->description,
            'input_description' => $bank->input_description,
            'output_description' => $bank->output_description,
            'notes' => $bank->notes,
            'sample_input' => $bank->sample_input,
            'sample_output' => $bank->sample_output,
            'time_limit' => (int) $bank->time_limit,
            'memory_limit' => (int) $bank->memory_limit,
            'version' => (string) $bank->version,
        ], JSON_UNESCAPED_UNICODE));
    }

    private function snapshotProblem(ProblemBank $bank, int $contestId, string $version): Problem
    {
        // Unique per publication, so two snapshots of the same bank entry
        // never share a package directory or a test-case path.
        $basename = 'practice-'.$bank->id.'-v'.Str::slug($version).'-'.Str::lower(Str::random(6));

        $problem = Problem::create([
            'contest_id' => $contestId,
            'short_name' => $this->internalShortName($contestId),
            'name' => $bank->name,
            'basename' => $basename,
            'description' => $this->statement($bank),
            'time_limit' => (int) $bank->time_limit,
            'memory_limit' => (int) $bank->memory_limit,
            'auto_judge' => true,
            'is_fake' => false,
            'sort_order' => $bank->id,
        ]);

        $this->snapshotSampleTestCase($problem, $bank);

        return $problem;
    }

    /**
     * `problems` is unique on (contest_id, short_name), and every snapshot of
     * every bank entry ever published shares one practice contest -- so the
     * bank's own code cannot be used here: republishing an entry would
     * collide with its own previous snapshot, which still exists on purpose.
     *
     * This token is internal. What a reader sees as the problem's short name
     * is the bank code, which PracticeController takes straight from the bank
     * and is free to repeat across snapshots.
     */
    private function internalShortName(int $contestId): string
    {
        $sequence = Problem::query()->where('contest_id', $contestId)->count() + 1;

        while (Problem::query()->where('contest_id', $contestId)->where('short_name', 'p'.$sequence)->exists()) {
            $sequence++;
        }

        return 'p'.$sequence;
    }

    /**
     * "Enunciado é texto simples com quebras de linha e exemplos
     * estruturados" -- the examples travel separately, in their own field,
     * so nothing here has to be parsed back out by the client, and no raw
     * HTML is ever produced.
     */
    public function statement(ProblemBank $bank): string
    {
        $sections = [
            trim((string) $bank->description),
            $this->section('Entrada', $bank->input_description),
            $this->section('Saida', $bank->output_description),
            $this->section('Observacoes', $bank->notes),
        ];

        return implode("\n\n", array_filter($sections, fn ($section) => $section !== ''));
    }

    private function section(string $title, ?string $body): string
    {
        $body = trim((string) $body);

        return $body === '' ? '' : $title."\n".$body;
    }

    private function snapshotSampleTestCase(Problem $problem, ProblemBank $bank): void
    {
        $input = $this->normalizeCase($bank->sample_input);
        $output = $this->normalizeCase($bank->sample_output);

        $inputPath = "problems/{$problem->contest_id}/{$problem->id}/input/1";
        $outputPath = "problems/{$problem->contest_id}/{$problem->id}/output/1";

        Storage::disk('local')->put($inputPath, $input);
        Storage::disk('local')->put($outputPath, $output);

        TestCase::create([
            'problem_id' => $problem->id,
            'number' => 1,
            'input_file' => $inputPath,
            'output_file' => $outputPath,
            'input_hash' => hash('sha256', $input),
            'output_hash' => hash('sha256', $output),
            'is_sample' => true,
        ]);
    }

    private function normalizeCase(?string $value): string
    {
        $value = str_replace("\r\n", "\n", (string) $value);
        $value = rtrim($value, "\n");

        return $value === '' ? "\n" : $value."\n";
    }
}
