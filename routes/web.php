<?php

use App\Http\Controllers\Backend\ConfigurationController;
use App\Http\Controllers\Backend\ContestWizardController;
use App\Http\Controllers\Backend\ProblemBankController;
use App\Http\Controllers\Backend\ProblemManagementController;
use App\Http\Controllers\Backend\TeamController;
use App\Http\Controllers\Backend\UserController;
use App\Http\Controllers\ClarificationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\JudgeController;
use App\Http\Controllers\ProblemController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ScoreboardController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\SubmissionController;
use App\Http\Controllers\SubmitController;
use App\Models\ContestLog;
use App\Models\ProblemBank;
use App\Services\BocaImporterService;
use Helium\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Home with statistics
Route::get('/', [HomeController::class, 'index']);

Route::get('/home', [HomeController::class, 'index'])->middleware('auth')->name('home');

// Profile (own account, any authenticated role)
Route::get('/profile', [ProfileController::class, 'edit'])->middleware('auth')->name('profile.edit');
Route::put('/profile', [ProfileController::class, 'update'])->middleware('auth')->name('profile.update');

// Problems (real BOCA-schema Problem model, scoped to the user's contest)
Route::get('/exercises', [ProblemController::class, 'index'])->name('exercises');

Route::get('/exercise/{problem}', [ProblemController::class, 'show'])->name('exercise.show');

// Scoreboard
Route::get('/scoreboard', [ScoreboardController::class, 'index'])->name('scoreboard');

// Scoreboard Export (CSV)
Route::get('/scoreboard/export', [ScoreboardController::class, 'export'])->name('scoreboard.export');

// Clarifications
Route::get('/clarifications', [ClarificationController::class, 'index'])->name('clarifications');

Route::post('/clarifications', [ClarificationController::class, 'store'])->middleware('auth');

// Submissions
Route::get('/submissions', [SubmissionController::class, 'index'])->middleware('auth')->name('submissions');

Route::get('/submission/{run}', [SubmissionController::class, 'show'])->middleware('auth')->name('submission.show');

// Submit solution -- creates a real Run and dispatches it to the auto-judge
// queue (see App\Http\Controllers\SubmitController). Previously this was a
// stub that wrote a 'pending' row to the legacy exercise_team table and
// never actually judged anything.
Route::get('/submit/{problem}', [SubmitController::class, 'create'])->name('exercise.submit');

Route::post('/submit/{problem}', [SubmitController::class, 'store']);

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

    // request()->ip() is only the real client address because nginx is the
    // sole hop in front of PHP-FPM in every compose file in this repo. If a
    // reverse proxy/load balancer/CDN is ever placed in front of this app,
    // bootstrap/app.php MUST configure ->trustProxies() with that proxy's
    // *specific* IP(s) before this becomes meaningful again -- trusting
    // X-Forwarded-For blindly (e.g. trustProxies(at: '*')) without an
    // actual trusted proxy in front lets any client simply set that header
    // themselves to spoof an allowed address, defeating the lock entirely.
    $clientIp = request()->ip();

    // A user who legitimately logs in from their site's own network with
    // "remember me" checked, then leaves with the device, would otherwise
    // silently bypass isIpAllowed() forever after -- Laravel's remember
    // cookie re-authenticates on later requests without ever going through
    // this route again. Accounts tied to an IP-restricted site never get a
    // persistent session, so every new browser session re-checks the IP.
    $remember = request()->filled('remember');
    if ($remember) {
        $candidate = User::where('email', $credentials['email'] ?? null)->first();
        if ($candidate?->site?->ip_address) {
            $remember = false;
        }
    }

    if (auth()->attempt($credentials, $remember)) {
        $user = auth()->user();

        // Issue #50: Site::ip_address was collected via the admin UI but
        // never enforced. A user whose account belongs to a site with a
        // configured ip_address must be connecting from an allowed
        // address/range -- checked here, not on a later page, so a
        // wrong-network login never gets a live session in the first place.
        if ($user->site && ! $user->site->isIpAllowed($clientIp)) {
            auth()->logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();

            ContestLog::warning(
                $user->contest_id ?? $user->site->contest_id,
                "Login bloqueado: IP fora da rede configurada para o site \"{$user->site->name}\"",
                ['user_id' => $user->user_id, 'site_id' => $user->site_id, 'ip' => $clientIp]
            );

            return back()->withErrors([
                'email' => 'Acesso bloqueado: fora da rede autorizada para o seu site.',
            ])->withInput(request()->only('email'));
        }

        request()->session()->regenerate();

        return redirect()->intended('/home');
    }

    return back()->withErrors([
        'email' => 'Credenciais invalidas.',
    ])->withInput(request()->only('email'));
})->middleware('throttle:5,1');

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
| Judge Routes
|--------------------------------------------------------------------------
*/

Route::get('/judge/runs', [JudgeController::class, 'index'])->name('judge.runs');
Route::post('/judge/runs/{run}', [JudgeController::class, 'judge'])->name('judge.runs.judge');

/*
|--------------------------------------------------------------------------
| Staff Routes
|--------------------------------------------------------------------------
*/

Route::get('/staff/tasks', [StaffController::class, 'tasks'])->name('staff.tasks');
Route::post('/staff/tasks/{task}/complete', [StaffController::class, 'complete'])->name('staff.tasks.complete');

/*
|--------------------------------------------------------------------------
| Site Coordinator Routes (issue #18 -- multi-site coordination)
|--------------------------------------------------------------------------
*/

Route::get('/site/dashboard', [SiteController::class, 'dashboard'])->name('site.dashboard');
Route::get('/site/tasks', [SiteController::class, 'tasks'])->name('site.tasks');
Route::post('/site/tasks/{task}/complete', [SiteController::class, 'completeTask'])->name('site.tasks.complete');
Route::get('/site/teams', [SiteController::class, 'teams'])->name('site.teams');
Route::post('/site/teams', [SiteController::class, 'storeTeam'])->name('site.teams.store');
Route::get('/site/clarifications', [SiteController::class, 'clarifications'])->name('site.clarifications');
Route::post('/site/clarifications/{clarification}/answer', [SiteController::class, 'answerClarification'])->name('site.clarifications.answer');

/*
|--------------------------------------------------------------------------
| Backend Routes (Admin)
|--------------------------------------------------------------------------
*/

Route::prefix('backend')->middleware(['auth', 'admin'])->group(function () {

    // Problem Management (real Problem/TestCase rows -- see issue #34;
    // replaces the old "Exercises Management" that targeted the legacy,
    // disconnected `exercises` table)
    Route::get('/exercises', [ProblemManagementController::class, 'index'])->name('backend.exercises');
    Route::post('/exercises', [ProblemManagementController::class, 'store']);

    // Users Management
    Route::get('/users', [UserController::class, 'index'])->name('backend.users');
    Route::post('/users', [UserController::class, 'store']);
    Route::delete('/users/{id}', [UserController::class, 'destroy'])->name('backend.users.destroy');

    // Teams Management
    Route::get('/teams', [TeamController::class, 'index'])->name('backend.teams');
    Route::post('/teams', [TeamController::class, 'store']);
    Route::delete('/teams/{id}', [TeamController::class, 'destroy'])->name('backend.teams.destroy');

    // Site Management (multi-site coordination -- see issue #18)
    Route::get('/sites', [App\Http\Controllers\Backend\SiteController::class, 'index'])->name('backend.sites');
    Route::post('/sites', [App\Http\Controllers\Backend\SiteController::class, 'store'])->name('backend.sites.store');
    Route::put('/sites/{site}', [App\Http\Controllers\Backend\SiteController::class, 'update'])->name('backend.sites.update');
    Route::delete('/sites/{site}', [App\Http\Controllers\Backend\SiteController::class, 'destroy'])->name('backend.sites.destroy');

    // Configurations/Hackathons Management
    Route::get('/configurations', [ConfigurationController::class, 'index'])->name('backend.configurations');
    Route::post('/configurations', [ConfigurationController::class, 'store']);

    // Contest Wizard
    Route::get('/contest-wizard', [ContestWizardController::class, 'index'])->name('backend.contest-wizard');
    Route::post('/contest-wizard', [ContestWizardController::class, 'store']);

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
                $item->problem = $item->problem_id ? 'Problema #'.$item->problem_id : null;

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
        if (! $hackathon) {
            return redirect()->route('backend.configurations')->with('error', 'Maratona nao encontrada');
        }

        return view('backend.contest-edit', compact('hackathon', 'contest'));
    })->name('backend.contest.edit');

    Route::put('/contest/{id}/update', function ($id, Request $request) {
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

        $startTime = new DateTime($validated['start_time']);
        $endTime = clone $startTime;
        $endTime->modify('+'.$validated['duration'].' minutes');

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
    Route::get('/problem-bank', [ProblemBankController::class, 'index'])->name('backend.problem-bank');
    Route::delete('/problem-bank/{id}', [ProblemBankController::class, 'destroy'])->name('backend.problem-bank.destroy');
    Route::post('/problem-bank/{id}/toggle', [ProblemBankController::class, 'toggle'])->name('backend.problem-bank.toggle');

    // BOCA Import Routes
    Route::get('/import-boca', function () {
        return view('backend.import-boca');
    })->name('backend.import-boca');

    Route::post('/import-boca/upload', function () {
        // Issue #46 (docs/specs/46-bank-ownership.md) calls out this route
        // by name: it creates new problem_bank rows, so it must reapply the
        // same ownership policy as the new bank-governance screen. This
        // route also sits behind the `admin` middleware today, so the
        // check below is currently unreachable for non-admins -- it's
        // defense-in-depth so import stays admin-only even if this route's
        // gate is ever loosened.
        abort_unless(auth()->user()->can('create', ProblemBank::class), 403);

        $file = request()->file('boca_zip');

        if (! $file || ! $file->isValid()) {
            return redirect()->route('backend.import-boca')->with('error', 'Arquivo invalido');
        }

        // Store temporarily
        $path = $file->store('temp/boca');
        $fullPath = storage_path('app/'.$path);

        // Import
        $importer = new BocaImporterService;
        $result = $importer->importFromZip($fullPath);

        // Cleanup
        @unlink($fullPath);

        if ($result['success']) {
            return redirect()->route('backend.problem-bank')
                ->with('success', 'Importados '.$result['count'].' problema(s) do BOCA!');
        }

        return redirect()->route('backend.import-boca')
            ->with('error', implode(', ', $result['errors']));
    });

    Route::post('/import-boca/github', function () {
        // Issue #46: same reasoning as /import-boca/upload above (also
        // currently unreachable for non-admins, kept as defense-in-depth).
        abort_unless(auth()->user()->can('create', ProblemBank::class), 403);

        $importer = new BocaImporterService;
        $result = $importer->importFromBocaGitHub();

        if ($result['success'] || $result['count'] > 0) {
            $message = 'Importados '.$result['count'].' problema(s) do BOCA GitHub!';
            if (! empty($result['errors'])) {
                $message .= ' (Alguns erros: '.implode(', ', array_slice($result['errors'], 0, 3)).')';
            }

            return redirect()->route('backend.problem-bank')->with('success', $message);
        }

        return redirect()->route('backend.import-boca')
            ->with('error', implode(', ', $result['errors']));
    });
});

require __DIR__.'/frontend.php';
require __DIR__.'/frontend_api_similarity.php';
require __DIR__.'/frontend_api_bank_governance.php';
require __DIR__.'/frontend_api_managed_accounts.php';
