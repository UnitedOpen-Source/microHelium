<?php

namespace App\Http\Middleware;

use App\Models\Contest;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfNoContest
{
    /**
     * Redirect admin to contest setup wizard if no contest exists.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip if already on wizard route
        if ($request->is('backend/contest-wizard') || $request->is('backend/contest-wizard/*')) {
            return $next($request);
        }

        // Skip if user is not authenticated
        if (!Auth::check()) {
            return $next($request);
        }

        $user = Auth::user();

        // Only redirect admins
        if (!$user->isAdmin()) {
            return $next($request);
        }

        // Check if there's any contest (active or not)
        $hasContest = Contest::exists();

        if (!$hasContest) {
            // Redirect admin to setup wizard
            return redirect()->route('backend.contest-wizard')
                ->with('info', 'Bem-vindo! Configure sua primeira maratona para comecar.');
        }

        return $next($request);
    }
}
