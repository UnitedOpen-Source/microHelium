<?php

namespace App\Services\Similarity;

use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use Helium\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves "a última AC de cada equipe" for a problem+language, per
 * docs/specs/42-similarity.md:
 *  - Only `Run`s with an accepted `Answer` count.
 *  - One run per team (`user_id`), the most recent AC; ties break toward
 *    the higher run id.
 *  - Teams whose source file is missing/unreadable on disk are excluded
 *    with a safe reason code (never the actual path).
 *
 * Practice-run exclusion (spec: "excluindo envios de prática") is
 * currently a no-op: issue #43 (practice mode) has not landed a
 * runs-table column distinguishing a practice submission from a contest
 * one, so there is nothing to filter on yet. Once #43 adds that column,
 * this is the one place to wire the exclusion in.
 */
class EligibleRunFinder
{
    /**
     * @return array{eligible: Collection<int, Run>, excluded: array<int, array{user_id:int, reason:string}>}
     */
    public function find(Problem $problem, Language $language): array
    {
        $acceptedRuns = Run::query()
            ->where('problem_id', $problem->id)
            ->where('language_id', $language->id)
            ->whereHas('answer', fn ($q) => $q->where('is_accepted', true))
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        // Collection::unique() keeps the FIRST occurrence per key; the
        // query above is already ordered newest-first (created_at desc,
        // id desc as the tiebreak), so the first run kept per user_id is
        // that team's most recent AC -- pending/WA runs submitted after it
        // never enter this set at all, since only accepted runs were
        // fetched to begin with.
        $latestPerUser = $acceptedRuns->unique('user_id');

        $eligible = collect();
        $excluded = [];

        foreach ($latestPerUser as $run) {
            // Only `team` accounts represent a contestant "equipe" in this
            // model; an admin/judge/staff test submission on the same
            // problem/language isn't a team to compare.
            if (!$run->user || $run->user->user_type !== User::TYPE_TEAM) {
                continue;
            }

            if (!$run->source_file || !Storage::disk('local')->exists($run->source_file)) {
                $excluded[] = ['user_id' => $run->user_id, 'reason' => 'source_missing'];
                continue;
            }

            $eligible->push($run);
        }

        return ['eligible' => $eligible, 'excluded' => $excluded];
    }
}
