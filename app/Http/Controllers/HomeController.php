<?php

namespace App\Http\Controllers;

use App\Models\Contest;
use App\Models\Problem;
use App\Models\Run;
use Helium\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HomeController extends Controller
{
    /**
     * Show the application dashboard with statistics.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        // Issue #106: these two used to count `exercises` and `teams`, the
        // 2017 Helium tables, while the submission counts right below came
        // from `runs`. A contest created through the wizard writes to
        // `problems` and `users`, so the dashboard showed "0 problems, 0
        // teams, N submissions" -- four numbers side by side, two of them
        // contradicting the other two.
        $contest = $this->resolveContest();

        $totalProblems = $contest ? Problem::where('contest_id', $contest->id)->count() : 0;
        $totalTeams = $contest
            ? User::where('contest_id', $contest->id)->where('user_type', User::TYPE_TEAM)->count()
            : 0;

        // Use runs table (BOCA schema) for submissions
        $totalSubmissions = 0;
        $acceptedSubmissions = 0;
        $recentSubmissions = collect([]);

        if ($contest && Schema::hasTable('runs')) {
            // Scoped to the same contest as the two counts above, so all
            // four numbers describe one thing.
            $totalSubmissions = DB::table('runs')->where('contest_id', $contest->id)->count();
            // Accepted submissions have answer_id = 1 (typically "Yes/Accepted")
            $acceptedSubmissions = DB::table('runs')
                ->leftJoin('answers', 'runs.answer_id', '=', 'answers.id')
                ->where('runs.contest_id', $contest->id)
                ->where('answers.is_accepted', true)
                // Issue #138: this counter is public (routes/web.php:39 has
                // no auth) and it is per contest, so an unreleased AC would
                // tick it up in front of the whole hall -- "somebody just
                // solved something" is most of the verdict. Staff are not
                // exempted here because the number is one figure shared by
                // every viewer of one page; the judge screen is where staff
                // see withheld work.
                ->when($contest->verification_required, fn ($q) => $q->whereNotNull('runs.verified_at'))
                ->count();
            $recentSubmissions = DB::table('runs')
                ->leftJoin('users', 'runs.user_id', '=', 'users.user_id')
                ->leftJoin('problems', 'runs.problem_id', '=', 'problems.id')
                ->leftJoin('answers', 'runs.answer_id', '=', 'answers.id')
                ->leftJoin('languages', 'runs.language_id', '=', 'languages.id')
                ->where('runs.contest_id', $contest->id)
                ->select('runs.*', 'users.fullname as team_name', 'problems.name as problem_name',
                         'answers.short_name as result', 'languages.name as language')
                ->orderBy('runs.created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($submission) use ($contest) {
                    // Issue #138. This list is OTHER teams' runs, on a page
                    // anonymous visitors can open, so it is the widest
                    // verdict disclosure on the site: a withheld verdict
                    // shown here reaches the team it belongs to by way of
                    // the projector. Masked for every viewer who is not
                    // running the event (Run::viewerSeesWithheldVerdicts()).
                    $withheld = $contest->verification_required
                        && $submission->verified_at === null
                        && ! Run::viewerSeesWithheldVerdicts(auth()->user());

                    if ($withheld) {
                        $submission->result = null;
                        $submission->status = 'judging';
                        $submission->answer_id = null;
                        $submission->judged_time = null;
                        $submission->auto_judge_result = null;
                    }

                    // home.blade.php renders `$submission->time . 's'` in the
                    // "Tempo" column, and nothing ever selected a `time` --
                    // the dashboard threw "Undefined property: stdClass::
                    // $time" the moment a contest had its first submission.
                    // The judged duration is what that column means.
                    $submission->time = $submission->auto_judge_start && $submission->auto_judge_end
                        ? max(0, strtotime($submission->auto_judge_end) - strtotime($submission->auto_judge_start))
                        : null;

                    return $submission;
                });
        }

        return view('home', compact('totalProblems', 'totalTeams', 'totalSubmissions', 'acceptedSubmissions', 'recentSubmissions'));
    }

    /**
     * The contest these numbers describe: the viewer's own when they have
     * one, otherwise the running competition. Never the practice contest
     * (#43) -- it is not an event and its problems and runs are not a
     * competition's.
     */
    private function resolveContest(): ?Contest
    {
        $user = auth()->user();

        if ($user && $user->contest_id) {
            return Contest::query()->competition()->find($user->contest_id);
        }

        return Contest::query()->competition()->where('is_active', true)->first();
    }
}