<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScoreboardController extends Controller
{
    /**
     * Display the scoreboard.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $teams = DB::table('teams')->orderBy('score', 'desc')->get();
        return view('scoreboard', compact('teams'));
    }

    /**
     * Export the scoreboard as a CSV file.
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function export(): StreamedResponse
    {
        $teams = DB::table('teams')->orderBy('score', 'desc')->get();

        $filename = 'placar_' . date('Y-m-d_H-i-s') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($teams) {
            $file = fopen('php://output', 'w');
            // BOM for Excel UTF-8 compatibility
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            // Header row
            fputcsv($file, ['Posicao', 'Time', 'Problemas Resolvidos', 'Penalidade', 'Pontuacao']);
            // Data rows
            $position = 1;
            foreach ($teams as $team) {
                fputcsv($file, [
                    $position++,
                    $team->teamName ?? 'Time #' . $team->team_id,
                    $team->problems_solved ?? 0,
                    $team->penalty ?? 0,
                    $team->score ?? 0,
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}