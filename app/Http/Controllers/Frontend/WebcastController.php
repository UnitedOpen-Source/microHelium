<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\WebcastCredential;
use App\Services\BocaWebcastZipBuilder;
use App\Support\IdempotencyStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Backend for issue #44 (`/backend/webcast`, see resources/js/features/
 * Webcast.vue). Routed exclusively through routes/frontend_api_webcast.php
 * under the web session guard + CSRF + ['auth','admin'] -- see that file
 * and routes/web.php's single `require` line for how it's wired in.
 *
 * Every route here is gated to admin already (route middleware), so
 * capabilities.can_manage_credentials is unconditionally true once a
 * contest is selected: there is currently no scoped-admin concept in this
 * app (Controller::authorizeScopedAccess() always bypasses for isAdmin()),
 * so "only concursos administraveis" reduces to "every contest" for the
 * only role that can reach this controller. The authorizeScopedAccess()
 * check on revoke is kept anyway as the same defense-in-depth convention
 * used elsewhere in this codebase, in case a scoped role is ever added
 * here later.
 *
 * Idempotency-Key handling goes through App\Support\IdempotencyStore --
 * the shared mechanism introduced by issue #47's managed-accounts create
 * endpoint (App\Http\Controllers\Api\Frontend\ManagedAccountsController).
 * This controller previously had its own, near-identical App\Services\
 * IdempotencyGuard; that was deleted in favor of this one shared
 * implementation when both landed on master around the same time -- see
 * storeCredential() for how the one place this endpoint's contract
 * differs from IdempotencyStore's generic "persist and replay whatever
 * the callback returns" behavior (never persisting the one-time secret)
 * is reconciled without forking the shared class.
 */
class WebcastController extends Controller
{
    private const ROUTE_CREDENTIALS = 'POST /api/frontend/webcast/credentials';

    public function index(Request $request): JsonResponse
    {
        $contests = Contest::orderBy('name')->get(['id', 'name']);

        $contestId = $request->query('contest_id');
        $contest = $contestId !== null && $contestId !== ''
            ? $contests->firstWhere('id', (int) $contestId)
            : null;

        if (! $contest) {
            return response()->json([
                'data' => [
                    'contests' => $this->contestOptions($contests),
                    'contest' => null,
                    'capabilities' => ['can_export' => false, 'can_manage_credentials' => false],
                    'export_url' => null,
                    'items' => [],
                    'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0],
                ],
            ]);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 20;

        $query = WebcastCredential::where('contest_id', $contest->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $total = $query->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $items = $query->forPage($page, $perPage)->get()->map(fn (WebcastCredential $c) => [
            'id' => $c->id,
            'label' => $c->label,
            'status' => $c->status(),
            'expires_at' => $c->expires_at?->toISOString(),
            'last_used_at' => $c->last_used_at?->toISOString(),
        ])->values();

        $canExport = (bool) config('webcast.export_enabled');

        return response()->json([
            'data' => [
                'contests' => $this->contestOptions($contests),
                'contest' => ['id' => $contest->id, 'name' => $contest->name],
                'capabilities' => ['can_export' => $canExport, 'can_manage_credentials' => true],
                'export_url' => '/api/frontend/webcast/export?contest_id='.$contest->id,
                'items' => $items,
                'meta' => ['current_page' => $page, 'last_page' => $lastPage, 'total' => $total],
            ],
        ]);
    }

    /**
     * @param  Collection<int, Contest>  $contests
     */
    private function contestOptions($contests): array
    {
        return $contests->map(fn (Contest $c) => ['id' => $c->id, 'name' => $c->name])->values()->all();
    }

    public function storeCredential(Request $request): JsonResponse
    {
        // Trim before validating (not after) -- a whitespace-only label
        // like "   " previously passed `min:1` on the raw, untrimmed
        // length and only got trimmed to "" afterwards, letting an
        // effectively empty label slip past `required`.
        $request->merge(['label' => trim((string) $request->input('label', ''))]);

        // IdempotencyStore::handle() persists AND replays exactly
        // $callback's returned JsonResponse body -- by design, so a retry
        // gets the exact same response as the original call. That's
        // wrong for this one endpoint: the raw secret must never be
        // persisted anywhere (spec: "nunca armazenar token em claro
        // indefinidamente"), even though it must still reach the caller
        // that actually triggered creation.
        //
        // Resolved by keeping the secret out of the response IdempotencyStore
        // ever sees entirely: the callback below always returns secret:null,
        // which is what gets both persisted and replayed. $issuedSecret is a
        // side channel, set only when THIS process is the one that actually
        // ran WebcastCredential::issue() (i.e. a fresh claim, not a replay
        // of someone else's completed or in-flight row) -- IdempotencyStore
        // has already persisted the redacted response by the time control
        // returns here, so overlaying the real secret afterwards, only for
        // the winning caller, never touches what's stored.
        $issuedSecret = null;

        $response = IdempotencyStore::handle($request, self::ROUTE_CREDENTIALS, function () use ($request, &$issuedSecret) {
            $maxDays = (int) config('webcast.max_credential_lifetime_days', 30);

            $validator = Validator::make($request->all(), [
                'contest_id' => [
                    'required',
                    'integer',
                    Rule::exists('contests', 'id')->where(fn ($q) => $q->whereNull('deleted_at')),
                ],
                'label' => ['required', 'string', 'min:1', 'max:80'],
                'expires_at' => ['required', 'date', 'after:now', 'before_or_equal:'.now()->addDays($maxDays)->toISOString()],
            ]);
            $validator->validate();

            $contest = Contest::find((int) $request->input('contest_id'));

            if (! $contest) {
                // Passed the Rule::exists() check above but is gone by
                // now (e.g. concurrently soft-deleted between validation
                // and this line) -- report it the same way as any other
                // invalid contest_id instead of letting a null Contest
                // reach WebcastCredential::issue()'s typed parameter as a
                // TypeError/500.
                throw ValidationException::withMessages([
                    'contest_id' => ['Competicao invalida.'],
                ]);
            }

            [$credential, $secret] = WebcastCredential::issue(
                $contest,
                (string) $request->input('label'), // already trimmed above, before validation
                new \DateTimeImmutable($request->input('expires_at')),
                auth()->id()
            );

            $issuedSecret = $secret;

            return response()->json(['data' => ['id' => $credential->id, 'secret' => null]], 201);
        });

        if ($issuedSecret !== null) {
            $body = $response->getData(true);
            $body['data']['secret'] = $issuedSecret;
            $response->setData($body);
        }

        return $response;
    }

    public function revokeCredential(Request $request, int $id): JsonResponse
    {
        return IdempotencyStore::handle($request, 'DELETE /api/frontend/webcast/credentials/'.$id, function () use ($id) {
            $credential = WebcastCredential::find($id);

            if (! $credential) {
                // Idempotent per spec ("Idempotente") -- revoking a
                // credential that no longer exists (already revoked and
                // since deleted, or never existed) still reports success
                // rather than exposing whether an id ever existed.
                return response()->json(['data' => ['id' => $id, 'revoked' => true]]);
            }

            $this->authorizeScopedAccess(
                auth()->user()->contest_id,
                $credential->contest_id,
                'Voce nao pode revogar credenciais de outra competicao.'
            );

            $credential->revoke();

            // No cache sits in front of WebcastCredential lookups (the
            // AuthenticateWebcastCredential middleware always reads the
            // database directly), so revocation is effective for the very
            // next request with no separate invalidation step needed.
            return response()->json(['data' => ['id' => $credential->id, 'revoked' => true]]);
        });
    }

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        if (! config('webcast.export_enabled')) {
            abort(503, 'A exportacao do webcast ainda nao esta disponivel.');
        }

        $contestId = $request->query('contest_id');
        $contest = $contestId !== null ? Contest::find((int) $contestId) : null;

        if (! $contest) {
            abort(404, 'Competicao nao encontrada.');
        }

        $builder = app(BocaWebcastZipBuilder::class);

        try {
            $zipPath = $builder->build($contest);
        } catch (\RuntimeException $e) {
            report($e);
            abort(500, 'Nao foi possivel gerar o arquivo do webcast.');
        }

        $filename = 'webcast-contest-'.$contest->id.'.zip';

        return response()->streamDownload(function () use ($zipPath) {
            // A plain try/finally here would NOT run its finally block
            // when PHP terminates mid-readfile() because the client
            // aborted/cancelled the download -- that termination happens
            // via connection-abort detection during output, not a normal
            // exception unwind. register_shutdown_function() DOES still
            // run in that case, so it's the only reliable way to guarantee
            // the temp ZIP is removed instead of accumulating forever in
            // sys_get_temp_dir() every time a download is cancelled.
            register_shutdown_function(static function () use ($zipPath) {
                if (is_file($zipPath)) {
                    @unlink($zipPath);
                }
            });

            readfile($zipPath);
        }, $filename, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
