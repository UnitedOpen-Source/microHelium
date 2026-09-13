<?php

namespace App\Services;

use App\Models\Problem;
use App\Models\Run;
use App\Models\Task;
use Helium\User;
use Illuminate\Support\Facades\DB;

/**
 * Issue #87 -- turns a team's first accepted run into a balloon delivery
 * task for the staff at their site.
 *
 * The staff screen, the Task model's color_name/color_hex and the Problem
 * model's matching pair all existed already; nothing ever connected them,
 * so the screen was structurally empty in any real contest and the columns
 * were never written. This is the connection.
 *
 * BOCA renders the same thing from `tasktable` with balloonurl($task.color)
 * in src/staff/task.php; the CD-MOJ equivalent is lib/print.sh.
 */
class BalloonService
{
    /**
     * Called from Score::updateScore() at the moment a score flips to
     * solved -- the one place every judging path converges on (auto judge,
     * manual verdict, the API's judge/rejudge).
     *
     * Safe to call more than once for the same team and problem: a rejudge
     * that re-confirms an AC must not hand out a second balloon.
     */
    public function awardFor(Run $run): ?Task
    {
        $contest = $run->contest;
        $problem = $run->problem;

        if (! $contest || ! $problem) {
            return null;
        }

        // Issue #43: practice has no ceremony, no staff and no balloons.
        if ($contest->is_practice) {
            return null;
        }

        // routes/api.php lets any authenticated account create a Run, so an
        // admin debugging through POST /api/runs would otherwise send
        // someone walking to a desk that does not exist.
        if (! $this->isTeam($run->user_id)) {
            return null;
        }

        $siteId = $run->site_id;

        if (! $siteId) {
            return null;
        }

        return DB::transaction(function () use ($run, $contest, $problem, $siteId) {
            $existing = Task::query()
                ->where('contest_id', $contest->id)
                ->where('user_id', $run->user_id)
                ->where('problem_id', $problem->id)
                ->where('is_system', true)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            // Same pattern as run numbering: lock the site's rows so two
            // concurrent solves cannot compute the same MAX(task_number)+1
            // and collide on the unique (contest_id, site_id, task_number).
            Task::where('contest_id', $contest->id)->where('site_id', $siteId)->lockForUpdate()->get();

            return Task::create([
                'contest_id' => $contest->id,
                'site_id' => $siteId,
                'user_id' => $run->user_id,
                'problem_id' => $problem->id,
                'task_number' => Task::getNextTaskNumber($contest->id, $siteId),
                'description' => $this->description($problem),
                'contest_time' => (int) $run->contest_time,
                'status' => 'pending',
                // Distinguishes a balloon the system raised from a print
                // request a team sent.
                'is_system' => true,
                'color_name' => $problem->color_name,
                'color_hex' => $problem->color_hex,
            ]);
        });
    }

    private function description(Problem $problem): string
    {
        $label = trim((string) $problem->short_name);
        $name = trim((string) $problem->name);

        $description = 'Balao do problema '.($label !== '' ? $label : '#'.$problem->id);

        if ($name !== '') {
            $description .= ' - '.$name;
        }

        // The column is varchar(200).
        return mb_substr($description, 0, 200);
    }

    private function isTeam(int $userId): bool
    {
        return User::where('user_id', $userId)->value('user_type') === User::TYPE_TEAM;
    }
}
