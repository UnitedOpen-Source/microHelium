<?php

namespace App\Http\Controllers\FrontendApi;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Services\IcpcReportBuilder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issue #89 -- download the ICPC standings file for a contest.
 *
 * Session-authenticated admin, like every other /api/frontend/* surface;
 * see routes/frontend_api_icpc.php.
 */
class IcpcReportController extends Controller
{
    public function __construct(private IcpcReportBuilder $builder) {}

    public function download(Request $request, int $contest): Response
    {
        $model = Contest::find($contest);

        if (! $model) {
            abort(404, 'Competicao nao encontrada.');
        }

        if ($model->is_practice) {
            // Issue #43: the practice contest is not an event. It has no
            // standings, and it must not be reachable through an
            // event-shaped export either.
            abort(404, 'Competicao nao encontrada.');
        }

        $csv = $this->builder->csv($model);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="icpc-contest-'.$model->id.'.csv"',
            // Final standings, tied to one contest and one moment. Nothing
            // about it should sit in a shared cache.
            'Cache-Control' => 'no-store, private',
            // Surfaced as a header rather than mixed into the CSV, which has
            // a fixed column layout a filer's tooling parses.
            'X-Icpc-Teams-Missing-Id' => (string) count($this->builder->teamsMissingIcpcId($model)),
        ]);
    }
}
