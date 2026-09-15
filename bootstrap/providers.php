<?php

// Laravel 11+ expects this file to list application service providers (see
// Illuminate\Foundation\Application::configure()'s default ->withProviders()
// call, which reads bootstrap/providers.php if present). This repository's
// Laravel upgrade never added it, so Helium\Providers\AppServiceProvider
// has never actually been booted -- it's needed now for issue #42's
// SimilarityEngineInterface container binding (see
// app/Providers/AppServiceProvider.php) to take effect at runtime.
//
// AppServiceProvider is the only one, and now the only one that exists.
// The other four classes that used to sit in app/Providers/ were dead
// legacy code from a pre-Laravel-11 skeleton -- AuthServiceProvider and
// EventServiceProvider referenced classes that exist nowhere in this
// codebase (Helium\Model, Helium\Policies\ModelPolicy, Helium\Events\
// Event, Helium\Listeners\EventListener) and would have failed to boot,
// and RouteServiceProvider::map() would have loaded routes/web.php and
// routes/api.php a SECOND time on top of bootstrap/app.php's
// ->withRouting(). This comment used to say cleaning them up was left for
// a separately-scoped PR; #180 was that PR, and they are gone, along with
// the equally dead Helium\Http\Kernel, Helium\Console\Kernel and the
// three middleware classes Laravel 11+ replaced with ->withMiddleware().
return [
    Helium\Providers\AppServiceProvider::class,
];
