<?php

namespace App\Http\Controllers\FrontendApi;

use App\Http\Controllers\Controller;
use App\Models\CurriculumFramework;
use App\Models\CurriculumOutcome;
use Illuminate\Http\JsonResponse;

/**
 * Issue #396 -- GET /api/frontend/curricula: os currículos importados e as
 * habilidades deles, na ordem do documento, para o seletor da tela de
 * governança do banco (docs/specs/396-curriculos-oficiais.md).
 *
 * Um currículo inteiro cabe numa resposta (a BNCC Computação tem 141
 * habilidades), então não há paginação: o seletor filtra no cliente.
 */
class CurriculumController extends Controller
{
    public function index(): JsonResponse
    {
        $frameworks = CurriculumFramework::query()
            ->with('outcomes')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => [
                'frameworks' => $frameworks->map(fn (CurriculumFramework $framework) => [
                    'id' => $framework->id,
                    'slug' => $framework->slug,
                    'name' => $framework->name,
                    'version' => $framework->version,
                    'jurisdiction' => $framework->jurisdiction,
                    'locale' => $framework->locale,
                    'source_url' => $framework->source_url,
                    'source_consulted_at' => $framework->source_consulted_at->toDateString(),
                    'outcomes' => $framework->outcomes->map(fn (CurriculumOutcome $outcome) => [
                        'id' => $outcome->id,
                        'code' => $outcome->code,
                        'stage' => $outcome->stage,
                        'axis' => $outcome->axis,
                        'text' => $outcome->text,
                    ])->values(),
                ])->values(),
            ],
        ]);
    }
}
