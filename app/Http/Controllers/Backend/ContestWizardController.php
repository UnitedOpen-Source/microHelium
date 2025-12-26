<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContestWizardController extends Controller
{
    /**
     * Display the contest wizard form.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $problemBank = \App\Models\ProblemBank::where('is_active', true)
            ->orderBy('difficulty')
            ->orderBy('name')
            ->get();
        return view('backend.contest-wizard', compact('problemBank'));
    }

    /**
     * Store a newly created contest from the wizard.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:hackathons,eventName',
            'description' => 'nullable|string',
            'start_time' => 'required|date',
            'duration' => 'required|integer|min:1',
            'freeze_time' => 'required|integer|min:0',
            'penalty' => 'required|integer|min:0',
            'max_file_size' => 'required|integer|min:1',
        ]);

        $startTime = new \DateTime($validated['start_time']);
        $endTime = (clone $startTime)->modify('+' . $validated['duration'] . ' minutes');

        $hackathonId = DB::table('hackathons')->insertGetId([
            'eventName' => $validated['name'],
            'description' => $validated['description'],
            'starts_at' => $startTime->format('Y-m-d H:i:s'),
            'ends_at' => $endTime->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contestId = DB::table('contests')->insertGetId([
            'name' => $validated['name'],
            'description' => $validated['description'],
            'start_time' => $startTime->format('Y-m-d H:i:s'),
            'duration' => $validated['duration'],
            'freeze_time' => $validated['freeze_time'],
            'penalty' => $validated['penalty'],
            'max_file_size' => $validated['max_file_size'],
            'is_active' => (bool)$request->input('is_active', false),
            'is_public' => (bool)$request->input('is_public', false),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sites')->insert(['contest_id' => $contestId, 'name' => 'Main Site', 'is_active' => true, 'permit_logins' => true, 'created_at' => now(), 'updated_at' => now()]);

        $selectedLanguages = $request->input('languages', []);
        $allLanguages = \App\Models\Language::getDefaultLanguages();
        foreach ($allLanguages as $lang) {
            DB::table('languages')->insert([
                'contest_id' => $contestId,
                'name' => $lang['name'],
                'extension' => $lang['extension'],
                'compile_command' => $lang['compile_command'],
                'run_command' => $lang['run_command'],
                'is_active' => in_array($lang['extension'], $selectedLanguages),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

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
            DB::table('answers')->insert(array_merge($answer, ['contest_id' => $contestId, 'created_at' => now(), 'updated_at' => now()]));
        }

        $selectedProblems = $request->input('problems', []);
        if (!empty($selectedProblems)) {
            $problemBank = DB::table('problem_bank')->whereIn('id', $selectedProblems)->where('is_active', true)->get();
            $letters = range('A', 'Z');
            $colors = [['name' => 'Vermelho', 'hex' => '#EF4444'], ['name' => 'Azul', 'hex' => '#3B82F6'], ['name' => 'Verde', 'hex' => '#22C55E'], ['name' => 'Amarelo', 'hex' => '#EAB308'], ['name' => 'Roxo', 'hex' => '#A855F7'], ['name' => 'Rosa', 'hex' => '#EC4899'], ['name' => 'Laranja', 'hex' => '#F97316'], ['name' => 'Ciano', 'hex' => '#06B6D4'], ['name' => 'Indigo', 'hex' => '#6366F1'], ['name' => 'Teal', 'hex' => '#14B8A6']];
            foreach ($problemBank as $sortOrder => $problem) {
                DB::table('problems')->insert([
                    'contest_id' => $contestId,
                    'short_name' => $letters[$sortOrder] ?? chr(65 + $sortOrder),
                    'name' => $problem->name,
                    'basename' => Str::slug($problem->code),
                    'description' => $problem->description . "\n\n## Entrada\n" . $problem->input_description . "\n\n## Saida\n" . $problem->output_description,
                    'time_limit' => $problem->time_limit,
                    'memory_limit' => $problem->memory_limit,
                    'color_name' => $colors[$sortOrder % count($colors)]['name'],
                    'color_hex' => $colors[$sortOrder % count($colors)]['hex'],
                    'auto_judge' => true,
                    'is_fake' => false,
                    'sort_order' => $sortOrder,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return redirect()->route('backend.configurations')->with('success', 'Maratona "' . $validated['name'] . '" criada com ' . count($selectedProblems) . ' problemas!');
    }
}