<?php

namespace App\Http\Controllers\Judgehost;

use App\Http\Controllers\Controller;
use App\Models\Run;
use App\Models\TestCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Issue #53 -- the bytes a judge machine needs to judge the run it holds.
 *
 * Every route here is scoped twice: to the run the caller is currently
 * leasing, and -- for test cases -- to that run's problem. That is the
 * whole point of the scoping.
 *
 * DOMjudge's equivalents (`get_files/source`, `get_files/testcase`) filter
 * on the submission or testcase id alone, with no check that the asking
 * judgehost was ever given that work. One judgehost credential there reads
 * every contestant's source and every hidden test case in the system --
 * jury-equivalent disclosure handed to every judge machine, including one
 * sitting in a partner institution's rack.
 */
class PayloadController extends Controller
{
    use HoldsRun;

    /**
     * GET /api/judgehost/runs/{run}/source
     *
     * Streamed rather than base64 in JSON: a source file is already at the
     * submission size limit and encoding it inflates it by a third for no
     * gain. The sha256 travels in a header so the agent can verify what it
     * got against what fetch-work told it to expect.
     */
    public function source(Request $request, Run $run): StreamedResponse
    {
        $this->assertHolds($request, $run);

        abort_unless(
            $run->source_file && Storage::disk('local')->exists($run->source_file),
            404,
            'O arquivo fonte desta submissao nao esta disponivel.'
        );

        return Storage::disk('local')->download(
            $run->source_file,
            $run->filename,
            [
                'Content-Type' => 'application/octet-stream',
                'X-Source-Sha256' => (string) $run->source_hash,
            ]
        );
    }

    /**
     * GET /api/judgehost/runs/{run}/testcases
     *
     * The index, not the data. Sizes and digests let the agent cache test
     * cases across runs of the same problem -- the reason a judgehost is
     * worth keeping warm -- and fetch only what it does not already hold.
     */
    public function testCases(Request $request, Run $run): JsonResponse
    {
        $this->assertHolds($request, $run);

        $cases = TestCase::where('problem_id', $run->problem_id)
            ->orderBy('number')
            ->get()
            ->map(fn (TestCase $case) => [
                'id' => $case->id,
                'number' => $case->number,
                'is_sample' => (bool) $case->is_sample,
                'input' => [
                    'sha256' => $case->input_hash,
                    'bytes' => $this->sizeOf($case->input_file),
                ],
                'output' => [
                    'sha256' => $case->output_hash,
                    'bytes' => $this->sizeOf($case->output_file),
                ],
            ])
            ->values();

        return response()->json(['data' => $cases]);
    }

    /**
     * GET /api/judgehost/runs/{run}/testcases/{testCase}/{kind}
     *
     * `{testCase}` is checked against the held run's problem, not merely
     * looked up. Without that, holding one trivial run in a practice
     * contest would be enough to read the hidden test data of every problem
     * in the real one.
     */
    public function testCaseFile(Request $request, Run $run, TestCase $testCase, string $kind): StreamedResponse
    {
        $this->assertHolds($request, $run);

        abort_unless(in_array($kind, ['input', 'output'], true), 404);

        abort_unless(
            (int) $testCase->problem_id === (int) $run->problem_id,
            403,
            'Este caso de teste nao pertence ao problema da submissao atribuida.'
        );

        $path = $kind === 'input' ? $testCase->input_file : $testCase->output_file;

        abort_unless(
            $path && Storage::disk('local')->exists($path),
            404,
            'Este caso de teste nao esta disponivel.'
        );

        return Storage::disk('local')->download(
            $path,
            sprintf('%d.%s', $testCase->number, $kind === 'input' ? 'in' : 'out'),
            ['Content-Type' => 'application/octet-stream']
        );
    }

    /**
     * GET /api/judgehost/runs/{run}/package/{kind}
     *
     * Issue #120 -- one of the problem's custom scripts for the held run's
     * language: compile, run or compare.
     *
     * Without these a judgehost judges by the default rules a problem
     * explicitly overrode. The compare script is the one that matters
     * most: a problem with a tolerance checker, judged by exact string
     * comparison, marks correct submissions WA -- silently, with no error
     * anywhere, and in favour of the wrong answer.
     *
     * Scoped exactly like the test data, and read through the same
     * manifest the payload was built from, so a path can never be composed
     * from anything the caller sent.
     */
    public function packageScript(Request $request, Run $run, string $kind): BinaryFileResponse
    {
        $this->assertHolds($request, $run);

        abort_unless(in_array($kind, ['compile', 'run', 'compare'], true), 404);

        $problem = $run->problem;
        $language = $run->language;

        abort_if($problem === null || $language === null, 404);

        $path = match ($kind) {
            'compile' => $problem->getCompileScriptPath((string) $language->extension),
            'run' => $problem->getRunScriptPath((string) $language->extension),
            'compare' => $problem->getCompareScriptPath((string) $language->extension),
        };

        abort_unless(is_file($path), 404, 'Este problema nao define um script deste tipo para esta linguagem.');

        return response()->file($path, [
            'Content-Type' => 'application/octet-stream',
            'X-Script-Sha256' => (string) hash_file('sha256', $path),
        ]);
    }

    private function sizeOf(?string $path): ?int
    {
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return Storage::disk('local')->size($path);
    }
}
