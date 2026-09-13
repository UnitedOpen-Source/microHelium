<?php

namespace App\Http\Controllers\Judgehost;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateJudgehost;
use App\Models\Answer;
use App\Models\Run;
use App\Services\AutoJudgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Issue #53 -- a judge machine reporting what it found.
 *
 * This is the one endpoint that changes the standings, so it is the one
 * worth being strict about.
 */
class ResultController extends Controller
{
    use HoldsRun;

    public function __construct(private AutoJudgeService $judge) {}

    /**
     * POST /api/judgehost/runs/{run}/result
     *
     * Three guards, each for a different failure a distributed judge
     * actually produces:
     *
     * - Scoped to the holder, like every other run-addressed route here. A
     *   judgehost cannot post a verdict for work it was not given.
     * - The verdict must be an answer defined for *this run's contest*.
     *   Answers are per contest; accepting a free-form string would write
     *   answer_id = null and silently produce a run that is `judged` with
     *   no verdict, which the scoreboard reads as "not solved" and nobody
     *   sees as an error.
     * - Rejected unless the run is still `judging`. A host whose lease
     *   expired mid-judging has already had its work handed to someone
     *   else; letting its late answer land would overwrite a verdict
     *   produced by the machine that actually holds the run, and with
     *   rejudging in the mix could resurrect a verdict from a previous
     *   version of the test data. The lease check and the status check are
     *   not the same check: the first says "you hold it", the second says
     *   "it is still open".
     *
     * Taken under a row lock so two of those cannot interleave between the
     * status read and the write.
     */
    public function store(Request $request, Run $run): JsonResponse
    {
        // Issue #123 -- a report that already landed is replayed, not
        // refused. The network can drop after the server has written the
        // verdict and before the agent hears so, and an agent that retries
        // then is not doing anything wrong. Checked before assertHolds()
        // because a judged run no longer has a judgehost_id to match, and
        // the claim token is what identifies the reporter at that point.
        if ($replay = $this->alreadyReported($request, $run)) {
            return $replay;
        }

        $judgehost = $this->assertHolds($request, $run);

        $data = $request->validate([
            'verdict' => [
                'required',
                'string',
                'max:10',
                Rule::exists('answers', 'short_name')->where('contest_id', $run->contest_id),
            ],
            'message' => ['nullable', 'string', 'max:65535'],
            'stdout' => ['nullable', 'string', 'max:65535'],
            'stderr' => ['nullable', 'string', 'max:65535'],
        ]);

        $verdict = DB::transaction(function () use ($run, $judgehost, $data) {
            $fresh = Run::query()->lockForUpdate()->find($run->id);

            abort_if($fresh === null, 404);

            abort_unless(
                (int) $fresh->judgehost_id === (int) $judgehost->id && $fresh->status === 'judging',
                409,
                'Esta submissao nao esta mais atribuida a este judgehost.'
            );

            // Cleared before the verdict is written, so a run that is
            // `judged` never also looks leased. Written quietly: the
            // verdict itself is the event worth logging, and
            // recordVerdict() logs it.
            //
            // The token MOVES rather than being dropped: the claim is over,
            // so it stops being current, but it stays recorded as the one
            // whose verdict landed. That is what lets a retried report be
            // recognised as the same one (#123) without also making a run
            // judged by hand look like it was reported by whoever holds it.
            $fresh->forceFill([
                'judgehost_id' => null,
                'claimed_at' => null,
                'reported_claim_token' => $fresh->claim_token,
                'claim_token' => null,
            ])->saveQuietly();

            $this->judge->recordVerdict($fresh, [
                'verdict' => $data['verdict'],
                'message' => $data['message'] ?? null,
                'stdout' => $data['stdout'] ?? null,
                'stderr' => $data['stderr'] ?? null,
            ]);

            return $fresh->fresh();
        });

        return response()->json([
            'data' => [
                'run_id' => $verdict->id,
                'status' => $verdict->status,
                'verdict' => $data['verdict'],
                'answer_id' => $verdict->answer_id,
            ],
        ]);
    }

    /**
     * Issue #123 -- the same claim reporting the same run twice.
     *
     * Only the claim that actually produced the verdict can replay it. A
     * run judged by a jury member while a host held it recorded no claim at
     * all, so the host's late answer still gets the ordinary refusal -- the
     * case that separates "you already told me this" from "someone else
     * decided while you were working".
     */
    private function alreadyReported(Request $request, Run $run): ?JsonResponse
    {
        $reported = (string) ($run->reported_claim_token ?? '');
        $presented = (string) ($request->header(AuthenticateJudgehost::CLAIM_TOKEN_HEADER) ?? '');

        if ($run->status !== 'judged' || $reported === '' || $presented === '' || ! hash_equals($reported, $presented)) {
            return null;
        }

        return response()->json([
            'data' => [
                'run_id' => $run->id,
                'status' => $run->status,
                'verdict' => $run->answer?->short_name,
                'answer_id' => $run->answer_id,
                'replayed' => true,
            ],
        ]);
    }

    /**
     * The verdicts this contest accepts, so an agent does not have to guess
     * at short names. BOCA's set is per contest and installations rename
     * them.
     *
     * Not scoped to a held run: it is the vocabulary, not anyone's work,
     * and an agent needs it before it has claimed anything.
     */
    public function vocabulary(Request $request, int $contest): JsonResponse
    {
        $this->judgehost($request);

        return response()->json([
            'data' => Answer::where('contest_id', $contest)
                ->orderBy('short_name')
                ->get(['short_name', 'name', 'is_accepted'])
                ->map(fn (Answer $answer) => [
                    'short_name' => $answer->short_name,
                    'name' => $answer->name,
                    'is_accepted' => (bool) $answer->is_accepted,
                ]),
        ]);
    }
}
