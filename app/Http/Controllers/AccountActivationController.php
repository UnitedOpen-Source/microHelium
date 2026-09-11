<?php

namespace App\Http\Controllers;

use App\Models\AccountActivation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Issue #47 -- real, working activation flow for accounts created by
 * Api\Frontend\ManagedAccountsController::store(). Deliberately NOT behind
 * 'auth' -- the whole point is the user has no usable password yet. This is
 * a normal Blade form + redirect flow, not a JSON API (see
 * docs/specs/README.md's contract, which only governs /api/frontend/*).
 *
 * Managed accounts never have an email address (spec: "Sem birthdate,
 * email, senha ou token" on the create payload), so the existing /login
 * route (which authenticates by email) cannot be reused for them. Instead,
 * a successful activation logs the user in directly.
 */
class AccountActivationController extends Controller
{
    public function show(string $token): View
    {
        $activation = $this->findByToken($token);

        return view('auth.activate', [
            'invalid' => ! $activation || ! $activation->isValid(),
            'token' => $token,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $activation = $this->findByToken($token);

        // Re-checked here (not just in show()) so a token that expired or
        // was used between GET and POST is still denied -- reuse/expiration
        // are enforced at the point of the actual privilege grant, not just
        // when the page was rendered.
        if (! $activation || ! $activation->isValid()) {
            return redirect()->route('activation.show', $token)->withErrors([
                'token' => 'Este link de ativacao e invalido, ja foi usado ou expirou.',
            ]);
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $activation->user;

        $claimed = DB::transaction(function () use ($activation, $user, $validated) {
            // isValid() above is a plain read, not a lock -- two concurrent
            // submissions of the same still-valid token (a double-click, a
            // retried request) could otherwise both pass it before either
            // commits. This single atomic UPDATE...WHERE used_at IS NULL is
            // the actual single-use guarantee: only the request whose
            // UPDATE affects a row may set the password; a losing
            // concurrent request affects zero rows and is rejected below
            // instead of also granting access.
            $claimedRows = AccountActivation::query()
                ->where('id', $activation->id)
                ->valid() // AccountActivation::scopeValid() -- same predicate as isValid()
                ->update(['used_at' => now()]);

            if ($claimedRows !== 1) {
                return false;
            }

            $user->forceFill([
                'password' => Hash::make($validated['password']),
                'is_enabled' => true,
            ])->save();

            return true;
        });

        if (! $claimed) {
            return redirect()->route('activation.show', $token)->withErrors([
                'token' => 'Este link de ativacao e invalido, ja foi usado ou expirou.',
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/home')->with('success', 'Senha definida com sucesso. Bem-vindo(a)!');
    }

    private function findByToken(string $token): ?AccountActivation
    {
        return AccountActivation::where('token_hash', hash('sha256', $token))->first();
    }
}
