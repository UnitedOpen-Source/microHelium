<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Clarification;
use App\Models\Contest;
use App\Models\ContestLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClarificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $contestId = $request->get('contest_id');

        $clarifications = Clarification::query()
            ->when($contestId, fn ($q) => $q->where('contest_id', $contestId))
            // Issue #197 -- a banca filtra por mesa. Uma pergunta de
            // teclado quebrado e uma sobre o enunciado do C vao para
            // pessoas diferentes, e ate agora as duas caiam na mesma fila.
            ->when(
                in_array($request->get('category'), Clarification::CATEGORIES, true),
                fn ($q) => $q->where('category', $request->get('category'))
            )
            ->when($request->filled('problem_id'), fn ($q) => $q->where('problem_id', $request->get('problem_id')))
            // Issue #134: the broadcast branch below had no contest
            // boundary of its own, so with no contest_id filter a team was
            // handed every broadcast_all clarification in the installation
            // -- answers written for another contest, which is how a
            // clarification gives away what is in a problem.
            ->when(! $user->isAdmin() && ! $user->isJudge(), function ($q) use ($user) {
                $q->whereHas('contest', fn ($q) => $q->visibleTo($user));

                $q->where(function ($q) use ($user) {
                    $q->where('user_id', $user->user_id)
                        ->orWhere('status', 'broadcast_all')
                        // broadcast_site is scoped to the clarification's
                        // own site (where the judge answered), not the
                        // viewer's -- a team at another site must not see it.
                        ->orWhere(function ($q) use ($user) {
                            $q->where('status', 'broadcast_site')
                                ->where('site_id', $user->site_id);
                        });
                });
            })
            ->with(['problem:id,short_name,name', 'user:user_id,fullname', 'judge:user_id,fullname'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($clarifications);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contest_id' => 'required|exists:contests,id',
            'problem_id' => 'nullable|exists:problems,id',
            // Issue #197 -- para onde vai a pergunta. Um problema ja e a
            // sua propria categoria (`problem_id`); estas tres sao o resto
            // do conjunto que os requisitos de CCS pedem.
            'category' => 'nullable|in:'.implode(',', Clarification::CATEGORIES),
            'question' => 'required|string|max:2000',
        ]);

        $user = auth()->user();
        $contest = Contest::findOrFail($validated['contest_id']);

        $this->authorizeContestMembership(
            $contest,
            'Voce nao pode enviar clarificacoes para um contest do qual nao participa.'
        );

        // Validate contest is running
        if (! $contest->isRunning()) {
            return response()->json(['error' => 'Contest is not running'], 422);
        }

        $clarification = Clarification::create([
            'contest_id' => $contest->id,
            'site_id' => $user->site_id ?? $contest->sites()->first()->id,
            'user_id' => $user->user_id,
            // `?? null` e obrigatorio, nao defensivo: uma regra `nullable`
            // cujo campo nao foi enviado NAO aparece em $validated, entao
            // ler a chave direto e um ErrorException -- HTTP 500. Ou seja,
            // qualquer pergunta que nao fosse sobre um problema derrubava o
            // endpoint, e e exatamente a pergunta que esta issue categoriza.
            // Nenhum teste pegava: o unico teste de sucesso sempre mandava
            // problem_id, e o que o omite espera 422 e para antes daqui.
            'problem_id' => $validated['problem_id'] ?? null,
            // Uma pergunta sobre problema vale pela categoria do proprio
            // problema; sem problema, o padrao e `general`.
            'category' => $validated['category'] ?? Clarification::CATEGORY_GENERAL,
            'clarification_number' => Clarification::getNextClarificationNumber(
                $contest->id,
                $user->site_id ?? 1
            ),
            'question' => $validated['question'],
            'contest_time' => $contest->getContestTime(),
            'status' => 'pending',
        ]);

        ContestLog::info($contest->id, "Clarification #{$clarification->clarification_number} submitted", [
            'user_id' => $user->user_id,
            'problem_id' => $clarification->problem_id,
            'category' => $clarification->category,
        ]);

        return response()->json($clarification->load('problem'), 201);
    }

    public function show(Clarification $clarification): JsonResponse
    {
        $user = auth()->user();

        // Check permissions
        if (! $user->isAdmin() && ! $user->isJudge()) {
            // The broadcast half of this is what index() filters too: a
            // broadcast is public to the contest it was answered in, not to
            // every account on the installation (issue #134).
            $isReadableBroadcast = $clarification->isBroadcast()
                && $clarification->contest?->isVisibleTo($user);

            if ($clarification->user_id !== $user->user_id && ! $isReadableBroadcast) {
                abort(403, 'Unauthorized');
            }
        }

        $clarification->load(['problem', 'user', 'judge']);

        return response()->json($clarification);
    }

    public function answer(Request $request, Clarification $clarification): JsonResponse
    {
        $this->authorizeScopedAccess(
            auth()->user()->contest_id,
            $clarification->contest_id,
            'Voce nao pode responder clarificacoes de outro contest.'
        );

        $validated = $request->validate([
            'answer' => 'required|string|max:2000',
            'broadcast' => 'nullable|in:none,site,all',
        ]);

        $broadcast = $validated['broadcast'] ?? 'none';

        $status = match ($broadcast) {
            'site' => 'broadcast_site',
            'all' => 'broadcast_all',
            default => 'answered',
        };

        $clarification->update([
            'answer' => $validated['answer'],
            'status' => $status,
            // Contest uses SoftDeletes -- a Clarification can outlive its
            // contest being soft-deleted, so ->contest can resolve to null.
            'answered_time' => $clarification->contest?->getContestTime() ?? 0,
            'judge_id' => auth()->id(),
            'judge_site_id' => auth()->user()->site_id,
        ]);

        ContestLog::info($clarification->contest_id, "Clarification #{$clarification->clarification_number} answered", [
            'judge_id' => auth()->id(),
            'broadcast' => $broadcast,
        ]);

        return response()->json($clarification);
    }

    public function destroy(Clarification $clarification): JsonResponse
    {
        $clarification->update(['status' => 'deleted']);
        $clarification->delete();

        return response()->json(null, 204);
    }

    public function pending(Request $request): JsonResponse
    {
        $contestId = $request->get('contest_id');

        $clarifications = Clarification::query()
            ->when($contestId, fn ($q) => $q->where('contest_id', $contestId))
            ->where('status', 'pending')
            // Issue #197 -- a fila da banca filtra por mesa.
            ->when(
                in_array($request->get('category'), Clarification::CATEGORIES, true),
                fn ($q) => $q->where('category', $request->get('category'))
            )
            ->when($request->filled('problem_id'), fn ($q) => $q->where('problem_id', $request->get('problem_id')))
            ->with(['problem:id,short_name,name', 'user:user_id,fullname'])
            ->orderBy('created_at')
            ->get();

        // Issue #197 -- as respostas prontas viajam com a fila, e nao numa
        // rota propria: quem abre a fila e exatamente quem vai usa-las, e
        // uma segunda requisicao para buscar cinco frases fixas seria
        // trabalho sem retorno.
        return response()->json([
            'data' => $clarifications,
            'predefined_answers' => config('clarifications.predefined_answers', []),
            'categories' => Clarification::CATEGORIES,
        ]);
    }
}
