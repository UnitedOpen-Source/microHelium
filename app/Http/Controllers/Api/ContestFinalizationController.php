<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Services\ContestAwards;
use App\Services\ContestFinalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issue #202 -- finalizar a prova e derivar a premiacao.
 */
class ContestFinalizationController extends Controller
{
    public function __construct(
        private ContestFinalizer $finalizer,
        private ContestAwards $awards,
    ) {}

    /**
     * O que impede finalizar, um a um.
     *
     * Existe como leitura propria porque a tela precisa DIZER o que esta
     * impedindo. Sem isso o botao de finalizar e um botao que nao funciona
     * e ninguem sabe por que -- e alguem vai clicar dez vezes antes de ir
     * procurar no log.
     */
    public function preflight(Contest $contest): JsonResponse
    {
        $blockers = $this->finalizer->blockers($contest);

        return response()->json([
            'can_finalize' => $blockers === [],
            'blockers' => $blockers,
            'finalized' => $contest->isFinalized(),
            'finalized_at' => $contest->finalized_at?->toISOString(),
        ]);
    }

    public function finalize(Request $request, Contest $contest): JsonResponse
    {
        $blockers = $this->finalizer->blockers($contest);

        if ($blockers !== []) {
            return response()->json([
                'error' => 'A prova nao pode ser finalizada ainda.',
                'blockers' => $blockers,
            ], 422);
        }

        $this->finalizer->finalize($contest, $request->user());

        return response()->json([
            'finalized' => true,
            'finalized_at' => $contest->fresh()->finalized_at?->toISOString(),
            'awards' => $this->awards->forContest($contest->fresh()),
        ]);
    }

    /**
     * A premiacao.
     *
     * Legivel antes de finalizar, de proposito: a organizacao precisa
     * conferir quem receberia o que ANTES de assinar embaixo -- e a rota e
     * de staff, entao conferir nao vaza nada. Quem le sabe se e final pelo
     * campo `finalized`.
     */
    public function awards(Contest $contest): JsonResponse
    {
        return response()->json($this->awards->forContest($contest));
    }
}
