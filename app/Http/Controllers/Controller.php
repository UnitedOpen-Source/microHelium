<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * Shared shape for "admin bypasses, everyone else must match a scope id
     * on the target record, otherwise 403" -- previously copy-pasted as
     * JudgeController::authorizeRunAccess(), StaffController::
     * authorizeTaskAccess(), and SiteController::authorizeSiteAccess(),
     * each comparing a different scope column (contest_id, site_id) with
     * an identical three-line body. A `role:` middleware alone (see
     * CheckRole) only checks the user's type, not which contest/site the
     * record being acted on belongs to -- without this, e.g. a judge from
     * contest A could judge a run in contest B just by guessing/
     * incrementing its id.
     */
    protected function authorizeScopedAccess(int|string|null $userScope, int|string|null $resourceScope, string $message): void
    {
        if (auth()->user()->isAdmin()) {
            return;
        }

        if ($userScope !== $resourceScope) {
            abort(403, $message);
        }
    }
}