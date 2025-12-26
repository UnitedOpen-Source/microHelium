<?php

namespace App\Http\Middleware;

use App\Models\Role;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles  Allowed roles (admin, participant, spectator)
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        $user = Auth::user();

        // If no roles specified, allow any authenticated user
        if (empty($roles)) {
            return $next($request);
        }

        // Check if user has any of the allowed roles
        foreach ($roles as $role) {
            if ($user->hasRole($role)) {
                return $next($request);
            }

            // Also check by method name
            $methodName = 'is' . ucfirst($role);
            if (method_exists($user, $methodName) && $user->$methodName()) {
                return $next($request);
            }
        }

        // User doesn't have permission
        abort(403, 'Voce nao tem permissao para acessar esta pagina.');
    }
}
