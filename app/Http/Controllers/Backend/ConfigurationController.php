<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ConfigurationController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $hackathons = DB::table('hackathons')->orderBy('hackathon_id', 'desc')->get();
        return view('backend.configurations', compact('hackathons'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
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

        // Create hackathon
        $hackathonId = DB::table('hackathons')->insertGetId([
            'eventName' => $request->input('eventName'),
            'description' => $request->input('description'),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Store selected languages as JSON in hackathon or create contest with languages
        $selectedLanguages = $request->input('languages', []);

        // If contests table exists, create a contest with languages
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
                $allLanguages = \App\Models\Language::getDefaultLanguages();

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
                $defaultAnswers = [
                    ['short_name' => 'AC', 'name' => 'Accepted', 'is_accepted' => true, 'sort_order' => 1],
                    ['short_name' => 'CE', 'name' => 'Compilation Error', 'is_accepted' => false, 'sort_order' => 2],
                    ['short_name' => 'RE', 'name' => 'Runtime Error', 'is_accepted' => false, 'sort_order' => 3],
                    ['short_name' => 'TLE', 'name' => 'Time Limit Exceeded', 'is_accepted' => false, 'sort_order' => 4],
                    ['short_name' => 'MLE', 'name' => 'Memory Limit Exceeded', 'is_accepted' => false, 'sort_order' => 5],
                    ['short_name' => 'WA', 'name' => 'Wrong Answer', 'is_accepted' => false, 'sort_order' => 6],
                    ['short_name' => 'PE', 'name' => 'Presentation Error', 'is_accepted' => false, 'sort_order' => 7],
                    ['short_name' => 'CS', 'name' => 'Contact Staff', 'is_accepted' => false, 'sort_order' => 8],
                ];

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