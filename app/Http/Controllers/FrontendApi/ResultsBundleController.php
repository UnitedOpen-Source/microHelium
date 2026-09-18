<?php

namespace App\Http\Controllers\FrontendApi;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Services\Results\ResultsBundleBuilder;
use App\Services\Results\ResultsBundleNotReadyException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issue #271 -- baixar o pacote de resultados de uma prova.
 *
 * Admin com sessão, como toda superfície /api/frontend/*, e pela mesma razão
 * do relatório ICPC (#89): isto é o campo de jogo inteiro num arquivo,
 * equipes e colocações incluídas, produzido uma vez, depois da prova, por
 * quem envia os resultados.
 */
class ResultsBundleController extends Controller
{
    public function __construct(private ResultsBundleBuilder $builder) {}

    public function download(Request $request, int $contest): Response
    {
        $model = Contest::find($contest);

        if (! $model) {
            abort(404, 'Competicao nao encontrada.');
        }

        try {
            $this->builder->assertExportable($model);
        } catch (ResultsBundleNotReadyException $e) {
            // 409 e nao 422: nada no PEDIDO esta errado -- o estado da prova
            // e que ainda nao permite. Um 422 mandaria o cliente procurar o
            // campo invalido que ele mandou, e nao ha nenhum.
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $path = tempnam(sys_get_temp_dir(), 'mh-results-');

        if ($path === false) {
            abort(500, 'Nao foi possivel preparar o pacote.');
        }

        try {
            $this->builder->zip($model, $path);
        } catch (ResultsBundleNotReadyException $e) {
            @unlink($path);

            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()
            ->download($path, $this->builder->filename($model), [
                'Content-Type' => 'application/zip',
                // Resultado final de uma prova, e contem icpc_id, que e
                // identificador pessoal. Nada disto deve sentar em cache
                // compartilhado.
                'Cache-Control' => 'no-store, private',
            ])
            // O arquivo e temporario: sem isto ele fica em /tmp ate o
            // sistema decidir limpar, e sao pacotes com dado pessoal.
            ->deleteFileAfterSend(true);
    }
}
