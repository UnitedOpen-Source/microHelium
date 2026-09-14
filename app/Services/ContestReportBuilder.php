<?php

namespace App\Services;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Problem;
use App\Models\Run;
use Helium\User;
use Illuminate\Support\Collection;

/**
 * Issue #144 -- the two report cuts BOCA ships that this application did not
 * have, plus the numbers a chart is drawn from.
 *
 * BOCA has src/staff/report/ (one site's submissions, verdicts and timings)
 * and src/judge/history.php (what each judge judged), and it bundles the
 * `libchart` PHP library to draw the graphs. We deliberately do not bundle a
 * charting library: this class returns the aggregates, and the Vue pages
 * (resources/js/features/SiteReport.vue, JudgeHistory.vue) draw them with
 * chart.js, which was already a declared dependency in package.json and
 * which nothing imported.
 *
 * IcpcReportBuilder is reused rather than duplicated for the one thing it
 * already knows: the standings walk. Everything else here -- per-problem,
 * per-verdict, per-judge and time-bucketed -- is aggregation that builder
 * never did, because the ICPC file has no column for any of it.
 *
 * Every method takes an explicit scope (a site id, a list of site ids) and
 * never falls back to "all" on its own. Deciding the scope is the
 * controller's job, because that decision is an authorization decision --
 * see ContestReportController.
 *
 * WHY one wide query and a pass in PHP rather than five GROUP BYs: the
 * interesting buckets here are derived (contest_time / 900 for the timeline,
 * judged_time - contest_time for the delay histogram), and integer division
 * is not spelled the same way in SQLite (which the test suite runs on) and
 * MySQL (which deployments run on). One lean select of the columns that
 * matter, bucketed in PHP, is portable and is a single round trip. The row
 * count is bounded by one contest's submissions -- a 200-team five-hour
 * contest is a few thousand rows, not a few million.
 */
class ContestReportBuilder
{
    /**
     * Timeline resolution. Fifteen minutes is what makes a five-hour contest
     * readable as ~20 points; per-minute would be noise, per-hour would hide
     * exactly the judging-queue pile-up the issue wants visible.
     */
    private const BUCKET_SECONDS = 900;

    /**
     * Upper bounds of the judging-delay histogram, in seconds. The last
     * bucket is open-ended. These are the thresholds an organiser actually
     * reacts to: under a minute is the auto-judge keeping up, over an hour
     * is a queue nobody is working.
     */
    private const DELAY_BUCKETS = [60, 300, 900, 3600];

    public function __construct(private IcpcReportBuilder $standings) {}

    /**
     * BOCA's src/staff/report/: one site's submissions, verdicts and timings.
     *
     * $siteId === null means "every site in this contest", which is the
     * admin/no-site view. It is never chosen here.
     */
    public function siteReport(Contest $contest, ?int $siteId): array
    {
        $runs = $this->runs($contest, $siteId === null ? null : [$siteId]);
        $answers = $this->answers($contest);
        $problems = $this->problems($contest);

        return [
            'summary' => $this->summary($runs, $answers, $siteId),
            'verdicts' => $this->verdicts($runs, $answers),
            'problems' => $this->problemBreakdown($runs, $problems, $answers),
            'timeline' => $this->timeline($contest, $runs, $answers),
            'judging_delay' => $this->judgingDelay($runs),
            'solved_distribution' => $this->solvedDistribution($contest, $siteId),
        ];
    }

    /**
     * BOCA's src/judge/history.php: what each judge judged.
     *
     * $siteIds === null means "every site"; anything else is the list the
     * caller is allowed to see (a judge stationed at a site sees their own
     * plus whatever Backend\SiteController routed to them -- the same list
     * JudgeController::index() scopes the judging queue by).
     *
     * Only manual judgments have a judge. A run the auto-judge decided has
     * judge_id NULL, and lumping those under some pseudo-judge would make
     * the per-judge totals lie. They are counted separately and reported as
     * their own number instead, because "how much did the machine do" is
     * half of the answer when a verdict is disputed.
     */
    public function judgeHistory(Contest $contest, ?array $siteIds, ?int $judgeId, int $page, int $perPage = 20): array
    {
        $runs = $this->runs($contest, $siteIds)->filter(fn (Run $run) => $run->status === 'judged');
        $answers = $this->answers($contest);
        $problems = $this->problems($contest);

        $manual = $runs->filter(fn (Run $run) => $run->judge_id !== null);
        $judges = $this->judgeNames($manual->pluck('judge_id')->unique()->all());

        $perJudge = $manual
            ->groupBy('judge_id')
            ->map(function (Collection $group, $id) use ($judges, $answers) {
                $judge = $judges->get((int) $id);
                $delays = $group->map(fn (Run $run) => $this->delaySeconds($run))->filter(fn (?int $d) => $d !== null);

                return [
                    'judge_id' => (int) $id,
                    'name' => (string) ($judge?->fullname ?: $judge?->username ?: 'Juiz #'.$id),
                    'judged' => $group->count(),
                    'accepted' => $group->filter(fn (Run $run) => $this->isAccepted($run, $answers))->count(),
                    'rejected' => $group->filter(fn (Run $run) => ! $this->isAccepted($run, $answers))->count(),
                    // The number a disputed verdict is actually argued
                    // about: how long this judge sat on the run.
                    'median_delay_seconds' => $this->median($delays->values()->all()),
                ];
            })
            ->sortByDesc('judged')
            ->values()
            ->all();

        $filtered = $judgeId === null
            ? $manual
            : $manual->filter(fn (Run $run) => (int) $run->judge_id === $judgeId);

        // Newest first: a dispute is about something that just happened.
        // Falling back to the run id keeps the order stable when several
        // runs carry the same judged_time, which happens whenever a judge
        // clears a backlog inside the same second.
        $ordered = $filtered
            ->sortByDesc(fn (Run $run) => [(int) ($run->judged_time ?? 0), (int) $run->id])
            ->values();

        $total = $ordered->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);

        return [
            'judges' => $perJudge,
            'summary' => [
                'judged' => $runs->count(),
                'manual' => $manual->count(),
                // Everything the auto-judge closed on its own. Surfaced so
                // "this judge only judged four runs" is read next to "the
                // machine judged nine hundred", not instead of it.
                'automatic' => $runs->count() - $manual->count(),
                'judges' => count($perJudge),
            ],
            'items' => $ordered
                ->forPage($page, $perPage)
                ->map(fn (Run $run) => $this->historyRow($run, $answers, $problems, $judges))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'total' => $total,
            ],
        ];
    }

    // --- the one query --------------------------------------------------

    /**
     * @param  list<int>|null  $siteIds
     * @return Collection<int, Run>
     */
    private function runs(Contest $contest, ?array $siteIds): Collection
    {
        return Run::query()
            ->where('contest_id', $contest->id)
            ->when($siteIds !== null, fn ($query) => $query->whereIn('site_id', $siteIds))
            ->select([
                'id', 'run_number', 'site_id', 'user_id', 'problem_id',
                'answer_id', 'contest_time', 'judged_time', 'status',
                'judge_id', 'judge_site_id', 'created_at',
            ])
            ->with(['user:user_id,fullname,username', 'site:id,name'])
            ->get();
    }

    /** @return Collection<int, Answer> */
    private function answers(Contest $contest): Collection
    {
        return Answer::query()->where('contest_id', $contest->id)->get()->keyBy('id');
    }

    /** @return Collection<int, Problem> */
    private function problems(Contest $contest): Collection
    {
        return Problem::query()
            ->where('contest_id', $contest->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->keyBy('id');
    }

    /** @return Collection<int, User> */
    private function judgeNames(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return User::query()
            ->whereIn('user_id', $ids)
            ->get(['user_id', 'fullname', 'username'])
            ->keyBy('user_id');
    }

    // --- aggregates ------------------------------------------------------

    private function summary(Collection $runs, Collection $answers, ?int $siteId): array
    {
        $judged = $runs->filter(fn (Run $run) => $run->status === 'judged');
        $accepted = $judged->filter(fn (Run $run) => $this->isAccepted($run, $answers));
        $delays = $judged->map(fn (Run $run) => $this->delaySeconds($run))->filter(fn (?int $d) => $d !== null)->values();

        return [
            'submissions' => $runs->count(),
            'judged' => $judged->count(),
            // "Still in the queue" is the number that matters DURING the
            // contest, which is half of why the issue wants this screen.
            'pending' => $runs->filter(fn (Run $run) => in_array($run->status, ['pending', 'judging'], true))->count(),
            'accepted' => $accepted->count(),
            'rejected' => $judged->count() - $accepted->count(),
            // null, not 0, when nothing has been judged: the specs are
            // explicit that zero must never stand in for "not known yet".
            'acceptance_rate' => $judged->count() === 0
                ? null
                : round($accepted->count() * 100 / $judged->count(), 1),
            'teams' => $runs->pluck('user_id')->unique()->count(),
            'median_delay_seconds' => $this->median($delays->all()),
            'max_delay_seconds' => $delays->isEmpty() ? null : (int) $delays->max(),
            'site_id' => $siteId,
        ];
    }

    /**
     * Verdict distribution -- the pie BOCA draws with libchart.
     *
     * Every verdict defined for the contest appears, including the ones with
     * a zero count: "nobody got a Time Limit Exceeded" is information, and
     * a chart that silently omits the category cannot say it.
     */
    private function verdicts(Collection $runs, Collection $answers): array
    {
        $counts = $runs
            ->filter(fn (Run $run) => $run->status === 'judged' && $run->answer_id !== null)
            ->countBy('answer_id');

        $rows = $answers
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->map(fn (Answer $answer) => [
                'answer_id' => $answer->id,
                'short_name' => (string) $answer->short_name,
                'name' => (string) $answer->name,
                'is_accepted' => (bool) $answer->is_accepted,
                'total' => (int) ($counts[$answer->id] ?? 0),
            ])
            ->values()
            ->all();

        $pending = $runs->filter(fn (Run $run) => $run->status !== 'judged')->count();

        if ($pending > 0) {
            // Not an Answer row, so it cannot come from the loop above --
            // but leaving the queue out of the verdict chart makes the
            // slices add up to less than the submissions counter right next
            // to it, which reads as a bug.
            $rows[] = [
                'answer_id' => null,
                'short_name' => 'FILA',
                'name' => 'Aguardando julgamento',
                'is_accepted' => false,
                'total' => $pending,
            ];
        }

        return $rows;
    }

    /**
     * Per-problem: attempts, accepted, how many distinct teams solved it and
     * when the first solve landed. "Teve problema que ninguem resolveu?" is
     * the question the issue names, and it is answered by a zero in
     * solved_teams -- so problems with no submissions at all are still
     * listed.
     */
    private function problemBreakdown(Collection $runs, Collection $problems, Collection $answers): array
    {
        $byProblem = $runs->groupBy('problem_id');

        return $problems
            ->map(function (Problem $problem) use ($byProblem, $answers) {
                $group = $byProblem->get($problem->id) ?? collect();
                $accepted = $group->filter(fn (Run $run) => $this->isAccepted($run, $answers));

                return [
                    'problem_id' => $problem->id,
                    'short_name' => (string) $problem->short_name,
                    'name' => (string) $problem->name,
                    'submissions' => $group->count(),
                    'accepted' => $accepted->count(),
                    // Distinct teams, not accepted runs: a rejudge or a
                    // duplicate accepted submission must not inflate "how
                    // many teams solved this".
                    'solved_teams' => $accepted->pluck('user_id')->unique()->count(),
                    'first_solve_minute' => $accepted->isEmpty()
                        ? null
                        : intdiv((int) $accepted->min('contest_time'), 60),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Submissions over contest time, in 15-minute buckets -- "a curva de
     * submissoes mostra fila no julgamento?".
     *
     * The buckets run from 0 to the contest duration even where no run
     * lands, so the curve has a flat stretch instead of a gap the eye reads
     * as a shorter contest. A run whose contest_time overshoots the duration
     * (a submission accepted by the clock at the very end, or a contest
     * whose duration was shortened afterwards) still gets a bucket.
     */
    private function timeline(Contest $contest, Collection $runs, Collection $answers): array
    {
        $durationSeconds = max(0, (int) $contest->duration * 60);
        $lastBucket = intdiv(max($durationSeconds - 1, 0), self::BUCKET_SECONDS);

        foreach ($runs as $run) {
            $lastBucket = max($lastBucket, intdiv(max(0, (int) $run->contest_time), self::BUCKET_SECONDS));
        }

        $buckets = [];
        for ($i = 0; $i <= $lastBucket; $i++) {
            $buckets[$i] = ['minute' => intdiv($i * self::BUCKET_SECONDS, 60), 'submissions' => 0, 'accepted' => 0];
        }

        foreach ($runs as $run) {
            $index = intdiv(max(0, (int) $run->contest_time), self::BUCKET_SECONDS);
            $buckets[$index]['submissions']++;

            if ($this->isAccepted($run, $answers)) {
                $buckets[$index]['accepted']++;
            }
        }

        return array_values($buckets);
    }

    /**
     * How long judged runs waited, as a histogram. This is the "fila de
     * julgamento crescendo e sinal de judge sobrecarregado" signal from the
     * issue, after the fact.
     *
     * Only runs with both ends of the interval recorded are counted; see
     * delaySeconds().
     */
    private function judgingDelay(Collection $runs): array
    {
        $labels = ['Ate 1 min', '1 a 5 min', '5 a 15 min', '15 a 60 min', 'Mais de 60 min'];
        $totals = array_fill(0, count($labels), 0);

        foreach ($runs as $run) {
            $delay = $this->delaySeconds($run);

            if ($delay === null) {
                continue;
            }

            $index = count(self::DELAY_BUCKETS);

            foreach (self::DELAY_BUCKETS as $position => $limit) {
                if ($delay <= $limit) {
                    $index = $position;
                    break;
                }
            }

            $totals[$index]++;
        }

        return array_map(
            fn (string $label, int $total) => ['label' => $label, 'total' => $total],
            $labels,
            $totals
        );
    }

    /**
     * How many teams solved 0, 1, 2 ... problems -- "a distribuicao de
     * acertos por problema fez sentido?" read from the other end.
     *
     * This is the reuse of IcpcReportBuilder: the standings walk already
     * produces one row per team with its solved count, ordered, and the site
     * id that row belongs to (added for this in #144).
     */
    private function solvedDistribution(Contest $contest, ?int $siteId): array
    {
        $rows = $this->standings->rows($contest);

        if ($siteId !== null) {
            $rows = array_values(array_filter($rows, fn (array $row) => $row['site_id'] === $siteId));
        }

        $maxSolved = 0;

        foreach ($rows as $row) {
            $maxSolved = max($maxSolved, (int) $row['solved']);
        }

        $distribution = [];

        for ($solved = 0; $solved <= $maxSolved; $solved++) {
            $distribution[] = [
                'solved' => $solved,
                'teams' => count(array_filter($rows, fn (array $row) => (int) $row['solved'] === $solved)),
            ];
        }

        return $distribution;
    }

    // --- helpers ---------------------------------------------------------

    private function historyRow(Run $run, Collection $answers, Collection $problems, Collection $judges): array
    {
        $answer = $run->answer_id === null ? null : $answers->get($run->answer_id);
        $problem = $problems->get($run->problem_id);
        $judge = $run->judge_id === null ? null : $judges->get((int) $run->judge_id);

        return [
            'run_id' => (int) $run->id,
            'run_number' => (int) $run->run_number,
            'problem' => $problem ? $problem->short_name.' - '.$problem->name : 'Problema removido',
            'team' => (string) ($run->user?->fullname ?: $run->user?->username ?: 'Equipe removida'),
            'site' => (string) ($run->site?->name ?? '-'),
            'verdict' => (string) ($answer?->name ?? 'Sem veredito'),
            'verdict_short' => (string) ($answer?->short_name ?? '-'),
            'is_accepted' => (bool) ($answer?->is_accepted ?? false),
            'judge' => (string) ($judge?->fullname ?: $judge?->username ?: 'Julgamento automatico'),
            'submitted_minute' => intdiv(max(0, (int) $run->contest_time), 60),
            'judged_minute' => $run->judged_time === null ? null : intdiv(max(0, (int) $run->judged_time), 60),
            'delay_seconds' => $this->delaySeconds($run),
            // A relative path, per the frontend contract: the client refuses
            // to follow anything else.
            'detail_url' => '/submission/'.$run->id,
        ];
    }

    /**
     * Seconds between submission and verdict, both measured on the contest
     * clock.
     *
     * null rather than 0 when the run is not judged, when judged_time was
     * never written (a rejudge clears it), or when the difference comes out
     * negative. Negative is not hypothetical: a run submitted before the
     * contest clock started has contest_time 0 while judged_time is the real
     * elapsed time, and issue #76 is the reminder that a sign error in this
     * exact arithmetic already shipped once. A bogus interval must not be
     * averaged into a "tempo de julgamento" an organiser reads as fact.
     */
    private function delaySeconds(Run $run): ?int
    {
        if ($run->status !== 'judged' || $run->judged_time === null) {
            return null;
        }

        $delay = (int) $run->judged_time - (int) $run->contest_time;

        return $delay < 0 ? null : $delay;
    }

    private function isAccepted(Run $run, Collection $answers): bool
    {
        if ($run->status !== 'judged' || $run->answer_id === null) {
            return false;
        }

        return (bool) $answers->get($run->answer_id)?->is_accepted;
    }

    /**
     * @param  list<int>  $values
     */
    private function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? (int) $values[$middle]
            : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }
}
