<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Home with statistics
Route::get('/', [\App\Http\Controllers\HomeController::class, 'index']);

Route::get('/home', [\App\Http\Controllers\HomeController::class, 'index'])->middleware('auth')->name('home');

// Exercises
Route::get('/exercises', [\App\Http\Controllers\ExerciseController::class, 'index'])->name('exercises');

Route::get('/exercise/{id}', [\App\Http\Controllers\ExerciseController::class, 'show'])->name('exercise.show');

// Scoreboard
Route::get('/scoreboard', [\App\Http\Controllers\ScoreboardController::class, 'index'])->name('scoreboard');

// Scoreboard Export (CSV)
Route::get('/scoreboard/export', [\App\Http\Controllers\ScoreboardController::class, 'export'])->name('scoreboard.export');

// Clarifications
Route::get('/clarifications', [\App\Http\Controllers\ClarificationController::class, 'index'])->name('clarifications');

Route::post('/clarifications', [\App\Http\Controllers\ClarificationController::class, 'store'])->middleware('auth');

// Submissions
Route::get('/submissions', [\App\Http\Controllers\SubmissionController::class, 'index'])->middleware('auth')->name('submissions');

// Submit solution
Route::get('/submit/{id}', function ($id) {
    $exercise = DB::table('exercises')->where('exercise_id', $id)->first();
    if (!$exercise) {
        abort(404);
    }
    return view('exercises.submit', compact('exercise'));
})->name('exercise.submit');

Route::post('/submit/{id}', function ($id) {
    // Handle file upload and submission processing
    // This would integrate with the auto-judge system
    $exercise = DB::table('exercises')->where('exercise_id', $id)->first();
    if (!$exercise) {
        abort(404);
    }

    DB::table('exercise_team')->insert([
        'exercise_id' => $id,
        'team_id' => auth()->id() ?? 1,
        'language' => request('language'),
        'result' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return redirect()->route('submissions')->with('success', 'Submissao enviada! Aguarde o julgamento.');
});

// Help page
Route::get('/ajuda', function () {
    return view('more-info');
})->name('ajuda');

Route::get('/wizard', function () {
    return view('wizard');
})->name('wizard');

// Health check
Route::get('/up', function () {
    return response('OK', 200);
});

// Auth routes
Route::get('/login', function () {
    return view('auth.login');
})->name('login');

Route::post('/login', function () {
    $credentials = request()->only('email', 'password');

    if (auth()->attempt($credentials, request()->filled('remember'))) {
        request()->session()->regenerate();
        return redirect()->intended('/home');
    }

    return back()->withErrors([
        'email' => 'Credenciais invalidas.',
    ])->withInput(request()->only('email'));
});

Route::get('/register', function () {
    return view('auth.register');
})->name('register');

Route::post('/register', function () {
    $validated = request()->validate([
        'fullname' => 'required|string|max:255',
        'username' => 'required|string|max:255|unique:users',
        'email' => 'required|string|email|max:255|unique:users',
        'password' => 'required|string|min:8|confirmed',
    ]);

    $userId = DB::table('users')->insertGetId([
        'fullname' => $validated['fullname'],
        'username' => $validated['username'],
        'email' => $validated['email'],
        'password' => bcrypt($validated['password']),
        'user_type' => 'team',
        'is_enabled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    auth()->loginUsingId($userId);

    return redirect('/home')->with('success', 'Conta criada com sucesso!');
});

Route::post('/logout', function () {
    auth()->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
    return redirect('/');
})->name('logout');

Route::get('/password/reset', function () {
    return view('auth.passwords.email');
})->name('password.request');

Route::post('/password/email', function () {
    // This is a placeholder to make the route exist.
    // A full implementation would send a password reset link.
    return back()->with('status', 'Password reset link sent!');
})->name('password.email');

/*
|--------------------------------------------------------------------------
| Backend Routes (Admin)
|--------------------------------------------------------------------------
*/

Route::prefix('backend')->middleware(['auth', 'admin'])->group(function () {

    // Exercises Management
    Route::get('/exercises', function () {
        $exercises = DB::table('exercises')->orderBy('exercise_id', 'desc')->get();
        return view('backend.exercises', compact('exercises'));
    })->name('backend.exercises');

    Route::post('/exercises', function () {
        DB::table('exercises')->insert([
            'exerciseName' => request('exerciseName'),
            'category' => request('category'),
            'difficulty' => request('difficulty'),
            'score' => request('score', 100),
            'expectedOutcome' => request('expectedOutcome'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return redirect()->route('backend.exercises')->with('success', 'Exercicio criado com sucesso!');
    });

    Route::delete('/exercises/{id}', function ($id) {
        DB::table('exercises')->where('exercise_id', $id)->delete();
        return redirect()->route('backend.exercises')->with('success', 'Exercicio excluido com sucesso!');
    });

    // Users Management
    Route::get('/users', [\App\Http\Controllers\Backend\UserController::class, 'index'])->name('backend.users');
    Route::post('/users', [\App\Http\Controllers\Backend\UserController::class, 'store']);
    Route::delete('/users/{id}', [\App\Http\Controllers\Backend\UserController::class, 'destroy'])->name('backend.users.destroy');

    // Teams Management
    Route::get('/teams', [\App\Http\Controllers\Backend\TeamController::class, 'index'])->name('backend.teams');
    Route::post('/teams', [\App\Http\Controllers\Backend\TeamController::class, 'store']);
    Route::delete('/teams/{id}', [\App\Http\Controllers\Backend\TeamController::class, 'destroy'])->name('backend.teams.destroy');

    // Configurations/Hackathons Management
    Route::get('/configurations', [\App\Http\Controllers\Backend\ConfigurationController::class, 'index'])->name('backend.configurations');
    Route::post('/configurations', [\App\Http\Controllers\Backend\ConfigurationController::class, 'store']);

    // Contest Wizard
    Route::get('/contest-wizard', [\App\Http\Controllers\Backend\ContestWizardController::class, 'index'])->name('backend.contest-wizard');
    Route::post('/contest-wizard', [\App\Http\Controllers\Backend\ContestWizardController::class, 'store']);

    // Clarifications Management (Admin)
    Route::get('/clarifications', function () {
        $clarifications = DB::table('clarifications')
            ->leftJoin('users', 'clarifications.user_id', '=', 'users.user_id')
            ->select('clarifications.*', 'users.fullname as team_name')
            ->orderBy('clarifications.status', 'asc')
            ->orderBy('clarifications.created_at', 'desc')
            ->get()
            ->map(function ($item) {
                $item->answered = $item->status === 'answered';
                $item->problem = $item->problem_id ? 'Problema #' . $item->problem_id : null;
                return $item;
            });
        return view('backend.clarifications', compact('clarifications'));
    })->name('backend.clarifications');

    Route::post('/clarifications/{id}/answer', function ($id) {
        DB::table('clarifications')->where('id', $id)->update([
            'answer' => request('answer'),
            'status' => 'answered',
            'answered_time' => 0,
            'updated_at' => now(),
        ]);
        return redirect()->route('backend.clarifications')->with('success', 'Resposta enviada!');
    });

    // Submissions Management (Admin)
    Route::get('/submissions', function () {
        $submissions = collect([]);

        if (Schema::hasTable('runs')) {
            $submissions = DB::table('runs')
                ->leftJoin('users', 'runs.user_id', '=', 'users.user_id')
                ->leftJoin('problems', 'runs.problem_id', '=', 'problems.id')
                ->leftJoin('answers', 'runs.answer_id', '=', 'answers.id')
                ->leftJoin('languages', 'runs.language_id', '=', 'languages.id')
                ->select('runs.*', 'users.fullname as team_name', 'problems.name as problem_name',
                         'answers.short_name as result', 'languages.name as language')
                ->orderBy('runs.created_at', 'desc')
                ->get();
        }

        return view('backend.submissions', compact('submissions'));
    })->name('backend.submissions');

    // Contest Management Actions
    Route::post('/contest/{id}/activate', function ($id) {
        // Deactivate all contests first
        DB::table('contests')->update(['is_active' => false]);
        // Activate the selected one
        DB::table('contests')->where('id', $id)->update(['is_active' => true]);
        return redirect()->route('backend.configurations')->with('success', 'Maratona ativada com sucesso!');
    });

    Route::get('/contest/{id}/edit', function ($id) {
        $hackathon = DB::table('hackathons')->where('hackathon_id', $id)->first();
        $contest = DB::table('contests')->where('id', $id)->first();
        if (!$hackathon) {
            return redirect()->route('backend.configurations')->with('error', 'Maratona nao encontrada');
        }
        return view('backend.contest-edit', compact('hackathon', 'contest'));
    })->name('backend.contest.edit');

    Route::put('/contest/{id}/update', function ($id, \Illuminate\Http\Request $request) {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'start_time' => 'required|date',
            'duration' => 'required|integer|min:30|max:10080',
            'penalty' => 'integer|min:0|max:120',
            'freeze_time' => 'integer|min:0',
            'max_file_size' => 'integer|min:1|max:10240',
            'unlock_key' => 'nullable|string|max:100',
        ]);

        $startTime = new \DateTime($validated['start_time']);
        $endTime = clone $startTime;
        $endTime->modify('+' . $validated['duration'] . ' minutes');

        // Check for duplicate name (excluding current hackathon)
        $existing = DB::table('hackathons')
            ->where('eventName', $validated['name'])
            ->where('hackathon_id', '!=', $id)
            ->first();
        if ($existing) {
            return redirect()->back()->with('error', 'Ja existe uma maratona com este nome');
        }

        // Update hackathon
        DB::table('hackathons')->where('hackathon_id', $id)->update([
            'eventName' => $validated['name'],
            'description' => $validated['description'],
            'starts_at' => $startTime->format('Y-m-d H:i:s'),
            'ends_at' => $endTime->format('Y-m-d H:i:s'),
            'updated_at' => now(),
        ]);

        // Update contest
        DB::table('contests')->where('id', $id)->update([
            'name' => $validated['name'],
            'description' => $validated['description'],
            'start_time' => $startTime->format('Y-m-d H:i:s'),
            'duration' => $validated['duration'],
            'freeze_time' => $validated['freeze_time'] ?? 60,
            'penalty' => $validated['penalty'] ?? 20,
            'max_file_size' => $validated['max_file_size'] ?? 100,
            'is_active' => $request->has('is_active'),
            'is_public' => $request->has('is_public'),
            'unlock_key' => $validated['unlock_key'],
            'updated_at' => now(),
        ]);

        return redirect()->route('backend.configurations')->with('success', 'Maratona atualizada com sucesso!');
    })->name('backend.contest.update');

    Route::delete('/contest/{id}/delete', function ($id) {
        DB::table('hackathons')->where('hackathon_id', $id)->delete();
        DB::table('contests')->where('id', $id)->delete();
        DB::table('languages')->where('contest_id', $id)->delete();
        DB::table('answers')->where('contest_id', $id)->delete();
        DB::table('sites')->where('contest_id', $id)->delete();
        return redirect()->route('backend.configurations')->with('success', 'Maratona excluida com sucesso!');
    });

    Route::post('/contest/freeze', function () {
        $contest = DB::table('contests')->where('is_active', true)->first();
        if ($contest) {
            DB::table('contests')->where('id', $contest->id)->update([
                'freeze_time' => 0, // Freeze immediately
                'updated_at' => now(),
            ]);
            return redirect()->route('backend.configurations')->with('success', 'Placar congelado!');
        }
        return redirect()->route('backend.configurations')->with('error', 'Nenhuma maratona ativa');
    });

    Route::post('/contest/end', function () {
        $contest = DB::table('contests')->where('is_active', true)->first();
        if ($contest) {
            DB::table('contests')->where('id', $contest->id)->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
            return redirect()->route('backend.configurations')->with('success', 'Competicao encerrada!');
        }
        return redirect()->route('backend.configurations')->with('error', 'Nenhuma maratona ativa');
    });

    // Problem Bank Management
    Route::get('/problem-bank', [\App\Http\Controllers\Backend\ProblemBankController::class, 'index'])->name('backend.problem-bank');
    Route::delete('/problem-bank/{id}', [\App\Http\Controllers\Backend\ProblemBankController::class, 'destroy'])->name('backend.problem-bank.destroy');
    Route::post('/problem-bank/{id}/toggle', [\App\Http\Controllers\Backend\ProblemBankController::class, 'toggle'])->name('backend.problem-bank.toggle');

    // BOCA Import Routes
    Route::get('/import-boca', function () {
        return view('backend.import-boca');
    })->name('backend.import-boca');

    Route::post('/import-boca/upload', function () {
        $file = request()->file('boca_zip');

        if (!$file || !$file->isValid()) {
            return redirect()->route('backend.import-boca')->with('error', 'Arquivo invalido');
        }

        // Store temporarily
        $path = $file->store('temp/boca');
        $fullPath = storage_path('app/' . $path);

        // Import
        $importer = new \App\Services\BocaImporterService();
        $result = $importer->importFromZip($fullPath);

        // Cleanup
        @unlink($fullPath);

        if ($result['success']) {
            return redirect()->route('backend.problem-bank')
                ->with('success', 'Importados ' . $result['count'] . ' problema(s) do BOCA!');
        }

        return redirect()->route('backend.import-boca')
            ->with('error', implode(', ', $result['errors']));
    });

    Route::post('/import-boca/github', function () {
        $importer = new \App\Services\BocaImporterService();
        $result = $importer->importFromBocaGitHub();

        if ($result['success'] || $result['count'] > 0) {
            $message = 'Importados ' . $result['count'] . ' problema(s) do BOCA GitHub!';
            if (!empty($result['errors'])) {
                $message .= ' (Alguns erros: ' . implode(', ', array_slice($result['errors'], 0, 3)) . ')';
            }
            return redirect()->route('backend.problem-bank')->with('success', $message);
        }

        return redirect()->route('backend.import-boca')
            ->with('error', implode(', ', $result['errors']));
    });
});