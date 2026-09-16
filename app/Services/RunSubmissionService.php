<?php

namespace App\Services;

use App\Jobs\JudgeRunJob;
use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Services\Clics\ContestEventRecorder;
use Helium\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Creating a Run and handing it to the judge, shared by the competition
 * submit path and issue #43's practice path.
 *
 * docs/specs/43-practice.md asks for exactly this split: "não reutilizar
 * diretamente SubmitController sem adaptar suas regras de horário e escopo
 * [...] reusar avaliação, validação de linguagem/fonte e isolamento do juiz
 * por serviço compartilhado com contexto explícito". So what differs between
 * the two callers -- whether a contest clock applies, whether resubmitting
 * identical source is refused -- is passed in rather than inferred here, and
 * what must not differ -- run numbering, storage layout, dispatch -- lives
 * here once.
 */
class RunSubmissionService
{
    /**
     * @param  bool  $rejectDuplicateSource  Competition submissions refuse an
     *                                       identical resubmission (it is almost always a double-click and it
     *                                       pollutes the scoreboard). Practice allows it: retrying the same code
     *                                       after an infrastructure failure is legitimate there, and #43's
     *                                       accidental-double-submit protection is the Idempotency-Key instead.
     *
     * @throws DuplicateSubmissionException
     */
    public function submit(
        Contest $contest,
        int $siteId,
        User $user,
        Problem $problem,
        Language $language,
        string $filename,
        string $source,
        bool $rejectDuplicateSource = true,
    ): Run {
        $sourceHash = hash('sha256', $source);

        try {
            $run = DB::transaction(function () use (
                $contest, $siteId, $user, $problem, $language,
                $filename, $source, $sourceHash, $rejectDuplicateSource
            ) {
                if ($rejectDuplicateSource) {
                    // Locked for the duration of the transaction so a
                    // double-submit race cannot have both requests pass the
                    // check before either commits.
                    $duplicate = Run::where('contest_id', $contest->id)
                        ->where('user_id', $user->user_id)
                        ->where('problem_id', $problem->id)
                        ->where('source_hash', $sourceHash)
                        ->lockForUpdate()
                        ->first();

                    if ($duplicate) {
                        throw new RuntimeException("DUPLICATE:{$duplicate->run_number}");
                    }
                }

                // Lock the site's existing runs so two concurrent submissions
                // can't compute the same MAX(run_number)+1 and collide on the
                // unique (contest_id, site_id, run_number) constraint.
                Run::where('contest_id', $contest->id)->where('site_id', $siteId)->lockForUpdate()->get();
                $runNumber = Run::getNextRunNumber($contest->id, $siteId);

                $path = "runs/{$contest->id}/{$user->user_id}/".uniqid('run_', true).'_'.$filename;
                Storage::disk('local')->put($path, $source);

                return Run::create([
                    'contest_id' => $contest->id,
                    'site_id' => $siteId,
                    'user_id' => $user->user_id,
                    'problem_id' => $problem->id,
                    'language_id' => $language->id,
                    'run_number' => $runNumber,
                    'filename' => $filename,
                    'source_file' => $path,
                    'source_hash' => $sourceHash,
                    // 0 for practice: the practice contest has no start_time,
                    // so getContestTime() is 0 by construction rather than by
                    // a special case here.
                    'contest_time' => $contest->getContestTime(),
                    'status' => 'pending',
                ]);
            });
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'DUPLICATE:')) {
                throw new DuplicateSubmissionException(substr($e->getMessage(), strlen('DUPLICATE:')));
            }

            throw $e;
        }

        // Issue #219 -- o event feed.
        //
        // Fora da transacao, de proposito: o evento conta que o envio
        // EXISTE, e um evento gravado dentro de uma transacao que depois
        // desfaz contaria sobre um envio que nao chegou a existir. Aqui a
        // transacao ja fechou.
        app(ContestEventRecorder::class)->submissionCreated($run);

        ContestLog::info($contest->id, "Run #{$run->run_number} submitted", [
            'user_id' => $user->user_id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
        ]);

        if ($problem->auto_judge) {
            JudgeRunJob::dispatch($run);
        }

        return $run;
    }

    /**
     * The stored filename for a submission. The compile/run commands come
     * from the chosen language, not from sniffing the file, so the extension
     * has to be the one that language actually compiles -- see the long note
     * in SubmitController on why $language->extension itself is not it.
     */
    public function filenameFor(Language $language, ?string $preferredBasename = null): string
    {
        $basename = $this->sanitize(pathinfo((string) $preferredBasename, PATHINFO_FILENAME));

        return ($basename !== '' ? $basename : 'main').'.'.$this->sanitize($language->getFileExtension());
    }

    /**
     * Client-supplied names are untrusted input that ends up in a storage
     * path: strip any directory component and keep a conservative charset.
     */
    public function sanitize(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        $name = ltrim($name, '.');

        return $name !== '' ? substr($name, 0, 150) : 'source';
    }
}
