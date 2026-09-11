<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\WebcastCredential;
use App\Services\BocaWebcastZipBuilder;
use App\Services\IdempotencyGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
 */
class WebcastController extends Controller
{
    public function __construct(private readonly IdempotencyGuard $idempotency) {}

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
                    'contests' => $contests->map(fn (Contest $c) => ['id' => $c->id, 'name' => $c->name])->values(),
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
                'contests' => $contests->map(fn (Contest $c) => ['id' => $c->id, 'name' => $c->name])->values(),
                'contest' => ['id' => $contest->id, 'name' => $contest->name],
                'capabilities' => ['can_export' => $canExport, 'can_manage_credentials' => true],
                'export_url' => '/api/frontend/webcast/export?contest_id='.$contest->id,
                'items' => $items,
                'meta' => ['current_page' => $page, 'last_page' => $lastPage, 'total' => $total],
            ],
        ]);
    }

    public function storeCredential(Request $request): JsonResponse
    {
        return $this->idempotency->handle($request, function () use ($request) {
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
                trim((string) $request->input('label')),
                new \DateTimeImmutable($request->input('expires_at')),
                auth()->id()
            );

            $publicBody = ['data' => ['id' => $credential->id, 'secret' => $secret]];

            // Reconciliation policy for a same-key/same-payload replay
            // (spec: "reconciliar metadados sem reexibir segredo ja
            // entregue"): the secret is never persisted anywhere, even
            // encrypted -- the record kept for idempotent replay carries
            // only metadata, with secret explicitly null. A client that
            // truly never received the secret (e.g. the response was lost
            // in flight) cannot recover it via retry; it must revoke and
            // reissue, which is the safer of the two policies the spec
            // explicitly allows here.
            $storedBody = ['data' => [
                'id' => $credential->id,
                'secret' => null,
                'label' => $credential->label,
                'status' => $credential->status(),
                'expires_at' => $credential->expires_at?->toISOString(),
            ]];

            return [201, $publicBody, $storedBody];
        });
    }

    public function revokeCredential(Request $request, int $id): JsonResponse
    {
        return $this->idempotency->handle($request, function () use ($id) {
            $credential = WebcastCredential::find($id);

            if (! $credential) {
                // Idempotent per spec ("Idempotente") -- revoking a
                // credential that no longer exists (already revoked and
                // since deleted, or never existed) still reports success
                // rather than exposing whether an id ever existed.
                return [200, ['data' => ['id' => $id, 'revoked' => true]]];
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
            return [200, ['data' => ['id' => $credential->id, 'revoked' => true]]];
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
            readfile($zipPath);
            @unlink($zipPath);
        }, $filename, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
