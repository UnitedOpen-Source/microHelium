<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
            'description' => $validated['description'] ?? null,
            'starts_at' => $startTime->format('Y-m-d H:i:s'),
            'ends_at' => $endTime->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contestId = DB::table('contests')->insertGetId([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
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

        $addedCount = self::addProblemsFromBank($contestId, $request->input('problems', []));

        return redirect()->route('backend.configurations')->with('success', 'Maratona "' . $validated['name'] . '" criada com ' . $addedCount . ' problemas!');
    }

    /**
     * Copy the selected Problem Bank entries into a contest as real Problem
     * rows (with a starter TestCase from the bank's sample input/output).
     * Shared between the wizard (new contest) and
     * Backend\ProblemManagementController (adding to an existing contest,
     * see issue #34 -- previously there was no way to add a problem to a
     * contest after the wizard's initial creation).
     *
     * @return int number of problems actually added
     */
    public static function addProblemsFromBank(int $contestId, array $problemBankIds, int $startingSortOrder = 0): int
    {
        if (empty($problemBankIds)) {
            return 0;
        }

        $problemBank = DB::table('problem_bank')->whereIn('id', $problemBankIds)->where('is_active', true)->get();
        $letters = range('A', 'Z');
        $colors = [['name' => 'Vermelho', 'hex' => '#EF4444'], ['name' => 'Azul', 'hex' => '#3B82F6'], ['name' => 'Verde', 'hex' => '#22C55E'], ['name' => 'Amarelo', 'hex' => '#EAB308'], ['name' => 'Roxo', 'hex' => '#A855F7'], ['name' => 'Rosa', 'hex' => '#EC4899'], ['name' => 'Laranja', 'hex' => '#F97316'], ['name' => 'Ciano', 'hex' => '#06B6D4'], ['name' => 'Indigo', 'hex' => '#6366F1'], ['name' => 'Teal', 'hex' => '#14B8A6']];

        $added = 0;
        foreach ($problemBank as $i => $problem) {
            $sortOrder = $startingSortOrder + $i;
            $basename = Str::slug($problem->code);

            // Same problem bank entry might already have been added to this
            // contest -- keep this idempotent rather than erroring on the
            // (contest_id, short_name)/(contest_id, basename) unique
            // constraints.
            $alreadyAdded = DB::table('problems')->where('contest_id', $contestId)->where('basename', $basename)->exists();
            if ($alreadyAdded) {
                continue;
            }

            $problemId = DB::table('problems')->insertGetId([
                'contest_id' => $contestId,
                'short_name' => $letters[$sortOrder] ?? chr(65 + $sortOrder),
                'name' => $problem->name,
                'basename' => $basename,
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

            // Problem Bank only carries one sample input/output pair (no
            // hidden test cases), but without at least this one, the
            // problem has zero TestCase rows and AutoJudgeService would
            // return "CS - no test cases found" for every submission,
            // making the problem unjudgeable. Not a substitute for a real
            // problem package with hidden cases, but it makes the problem
            // actually functional out of the box.
            if (filled($problem->sample_input) && filled($problem->sample_output)) {
                $inputRelative = "problems/{$contestId}/{$basename}/input/1";
                $outputRelative = "problems/{$contestId}/{$basename}/output/1";

                Storage::disk('local')->put($inputRelative, $problem->sample_input);
                Storage::disk('local')->put($outputRelative, $problem->sample_output);

                DB::table('test_cases')->insert([
                    'problem_id' => $problemId,
                    'number' => 1,
                    'input_file' => $inputRelative,
                    'output_file' => $outputRelative,
                    'input_hash' => hash('sha256', $problem->sample_input),
                    'output_hash' => hash('sha256', $problem->sample_output),
                    'is_sample' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $added++;
        }

        return $added;
    }
}
