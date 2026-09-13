<?php

namespace App\Services;

use App\Models\Contest;
use App\Models\Leaderboard;

/**
 * Issue #89 -- the ICPC standings file, the one an organiser sends in after
 * the contest is over.
 *
 * BOCA produces it in src/admin/report/icpc.php: one CSV line per team, in
 * final standings order,
 *
 *     usericpcid, placement, problems solved, total time, first solve time
 *
 * This is not the webcast export (#44). That one streams a live scoreboard
 * to the ceremony display; this one is the record of what happened, and its
 * consumer is a person filing results.
 *
 * Ordering and times come from Leaderboard::getScoreboard(), the same source
 * the public scoreboard uses -- including the getContestTime() sign fix from
 * #76, without which every time in here would have been negative.
 */
class IcpcReportBuilder
{
    /**
     * @return list<array{icpc_id:string,placement:int,solved:int,total_time:int,first_solve:int,team:string,has_icpc_id:bool}>
     */
    public function rows(Contest $contest): array
    {
        $rows = [];
        $placement = 0;

        foreach (Leaderboard::getScoreboard($contest->id) as $entry) {
            $user = $entry['user'] ?? null;

            if (! $user) {
                // A leaderboard row whose user is gone has nothing to file.
                continue;
            }

            $placement++;

            $rows[] = [
                'icpc_id' => (string) ($user->icpc_id ?? ''),
                'has_icpc_id' => trim((string) ($user->icpc_id ?? '')) !== '',
                'placement' => $placement,
                'solved' => (int) $entry['problems_solved'],
                'total_time' => (int) $entry['total_time'],
                'first_solve' => $this->firstSolveMinute($entry['problems']),
                'team' => (string) ($user->fullname ?? ''),
            ];
        }

        return $rows;
    }

    public function csv(Contest $contest): string
    {
        $lines = [];

        foreach ($this->rows($contest) as $row) {
            $lines[] = implode(',', [
                // Sanitised for the same reason the webcast builder
                // sanitises: a comma or a newline inside a field silently
                // shifts every column after it.
                $this->field($row['icpc_id']),
                $row['placement'],
                $row['solved'],
                $row['total_time'],
                $row['first_solve'],
            ]);
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    /**
     * Teams still missing an icpc_id. The report is filed as-is by BOCA, so
     * this is surfaced rather than enforced: an organiser needs to know the
     * column is blank BEFORE sending the file, not after.
     *
     * @return list<string>
     */
    public function teamsMissingIcpcId(Contest $contest): array
    {
        return array_values(array_map(
            fn (array $row) => $row['team'],
            array_filter($this->rows($contest), fn (array $row) => ! $row['has_icpc_id'])
        ));
    }

    /**
     * The minute of this team's earliest accepted problem, or 0 when they
     * solved nothing -- BOCA writes 0 in that case rather than omitting the
     * field.
     *
     * @param  iterable<array{is_solved:bool,solved_time:int|null}>  $problems
     */
    private function firstSolveMinute(iterable $problems): int
    {
        $times = [];

        foreach ($problems as $problem) {
            if (! empty($problem['is_solved'])) {
                $times[] = (int) ($problem['solved_time'] ?? 0);
            }
        }

        return $times === [] ? 0 : min($times);
    }

    private function field(string $value): string
    {
        return str_replace([',', "\r", "\n"], ' ', $value);
    }
}
