<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class ConfigurationController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return View
     */
    public function index()
    {
        // Issue #190 -- reads `contests`, which is where competitions
        // actually live. This listed `hackathons`, a 2017 table that only
        // the wizard and this screen ever wrote, so a contest created by
        // the API, by the event importer (#147), by a seeder or by the
        // practice contest (#43) did not appear here at all.
        $contests = Contest::competition()->orderByDesc('id')->get();

        return view('backend.configurations', compact('contests'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return RedirectResponse
     */
    public function store(Request $request)
    {
        // Parse dates - use now() if empty/null
        $startsAt = $request->input('starts_at');
        $endsAt = $request->input('ends_at');

        // Default to now if not provided
        if (empty($startsAt)) {
            $startsAt = now()->format('Y-m-d H:i:s');
        }
        if (empty($endsAt)) {
            $endsAt = now()->addHours(5)->format('Y-m-d H:i:s');
        }

        // Issue #190 -- no `hackathons` row any more. Writing both kept
        // the two tables in step only by auto-increment coincidence, and
        // every other creation path (API, importer, seeders) wrote just
        // `contests` anyway.
        $selectedLanguages = $request->input('languages', []);

        if (Schema::hasTable('contests')) {
            $contestId = DB::table('contests')->insertGetId([
                'name' => $request->input('eventName'),
                'description' => $request->input('description'),
                'start_time' => $startsAt,
                'duration' => 300, // 5 hours default
                'freeze_time' => 60,
                'penalty' => 20,
                'max_file_size' => 100,
                'is_active' => true,
                'is_public' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Create default site
            if (Schema::hasTable('sites')) {
                DB::table('sites')->insert([
                    'contest_id' => $contestId,
                    'name' => 'Main Site',
                    'is_active' => true,
                    'permit_logins' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Create languages for this contest
            if (Schema::hasTable('languages')) {
                $allLanguages = Language::getDefaultLanguages();

                foreach ($allLanguages as $lang) {
                    $isActive = in_array($lang['extension'], $selectedLanguages);
                    DB::table('languages')->insert([
                        'contest_id' => $contestId,
                        'name' => $lang['name'],
                        'extension' => $lang['extension'],
                        'compile_command' => $lang['compile_command'],
                        'run_command' => $lang['run_command'],
                        'is_active' => $isActive,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Create default answers
            if (Schema::hasTable('answers')) {
                // One list, in App\Models\Answer. A hand-written copy here
                // drifting from it means a contest missing a verdict row, and a
                // run that earns that verdict gets a null answer_id and looks
                // like nothing happened -- see the test in
                // tests/Feature/DefaultAnswersTest.php.
                $defaultAnswers = Answer::getDefaultAnswers();

                foreach ($defaultAnswers as $answer) {
                    DB::table('answers')->insert(array_merge($answer, [
                        'contest_id' => $contestId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));
                }
            }
        }

        return redirect()->route('backend.configurations')->with('success', 'Maratona criada com sucesso!');
    }
}
