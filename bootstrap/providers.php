<?php

// Laravel 11+ expects this file to list application service providers (see
// Illuminate\Foundation\Application::configure()'s default ->withProviders()
// call, which reads bootstrap/providers.php if present). This repository's
// Laravel upgrade never added it, so Helium\Providers\AppServiceProvider
// has never actually been booted -- it's needed now for issue #42's
// SimilarityEngineInterface container binding (see
// app/Providers/AppServiceProvider.php) to take effect at runtime.
//
// Only AppServiceProvider is registered here, intentionally -- the other
// four classes in app/Providers/ are dead legacy code left over from a
// pre-Laravel-11 skeleton, not omitted by oversight:
//   - AuthServiceProvider / EventServiceProvider reference classes that
//     don't exist anywhere in this codebase (Helium\Model,
//     Helium\Policies\ModelPolicy, Helium\Events\Event,
//     Helium\Listeners\EventListener) and would fail to boot.
//   - RouteServiceProvider::map() calls Route::group() over
//     routes/web.php and routes/api.php a SECOND time -- both are already
//     loaded once via bootstrap/app.php's ->withRouting(). Registering it
//     risks duplicate/conflicting named-route registration across the
//     whole app, not just something scoped to this feature.
// Cleaning these up (deleting them, or rewriting them to something real)
// is left for a future, separately-scoped PR -- out of bounds for #42.
return [
    Helium\Providers\AppServiceProvider::class,
];
