<?php

namespace App\Http\Controllers;

use App\Models\Contest;
use App\Models\Leaderboard;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScoreboardController extends Controller
{
    /**
     * Display the scoreboard.
     *
     * Rebuilt on top of the real BOCA-schema Leaderboard/Score models
     * instead of the legacy `teams` table, which had no relation to actual
     * judged runs.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $contest = $this->resolveContest();
        $problems = $contest ? $contest->problems()->orderBy('short_name')->get() : collect();
        $entries = $this->buildScoreboard($contest);

        return view('scoreboard', [
            'contest' => $contest,
            'problems' => $problems,
            'entries' => $entries,
        ]);
    }

    /**
     * Export the scoreboard as a CSV file.
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function export(): StreamedResponse
    {
        $contest = $this->resolveContest();
        $entries = $this->buildScoreboard($contest);

        $filename = 'placar_' . date('Y-m-d_H-i-s') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($entries) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($file, ['Posicao', 'Time', 'Problemas Resolvidos', 'Penalidade']);
            foreach ($entries as $entry) {
                $name = $entry['user']->fullname ?? ('Usuario #' . $entry['user_id']);

                fputcsv($file, [
                    $entry['rank'],
                    $this->csvSafe($name),
                    $entry['problems_solved'],
                    $entry['total_time'],
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * `fullname` is a user-controlled registration field. A value like
     * `=HYPERLINK("http://evil")` opened in Excel/Sheets is interpreted as a
     * formula (CSV/formula injection) rather than plain text -- prefix a
     * leading =, +, -, or @ with a single quote so spreadsheet apps treat
     * the cell as text.
     */
    private function csvSafe(string $value): string
    {
        if (preg_match('/^[=+\-@]/', $value)) {
            return "'" . $value;
        }

        return $value;
    }

    private function buildScoreboard(?Contest $contest): array
    {
        if (!$contest) {
            return [];
        }

        $entries = Leaderboard::getScoreboard($contest->id);

        foreach ($entries as &$entry) {
            $entry['user_id'] = $entry['user']->user_id ?? null;
            $entry['problems'] = collect($entry['problems'])->keyBy('problem_id');
        }
        unset($entry);

        return $this->applySiteVisibility($entries);
    }

    /**
     * A team logged in at a site configured with score_visibility =
     * 'own_site' only sees their own site's rows -- admins/judges/staff/
     * spectators always get the unrestricted view, matching BOCA's
     * admin/score bypass on sitescorelevel.
     */
    private function applySiteVisibility(array $entries): array
    {
        $viewer = auth()->user();

        if (!$viewer || $viewer->isAdmin() || $viewer->isJudge() || $viewer->isStaff() || $viewer->isSpectator()) {
            return $entries;
        }

        $site = $viewer->site;
        if (!$site || $site->score_visibility !== 'own_site') {
            return $entries;
        }

        return array_values(array_filter(
            $entries,
            fn ($entry) => ($entry['user']->site_id ?? null) === $viewer->site_id
        ));
    }

    private function resolveContest(): ?Contest
    {
        $user = auth()->user();

        if ($user?->contest_id) {
            return Contest::find($user->contest_id);
        }

        return Contest::where('is_active', true)->first();
    }
}
