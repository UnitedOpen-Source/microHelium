<?php

// Laravel 11+ expects this file to list application service providers (see
// Illuminate\Foundation\Application::configure()'s default ->withProviders()
// call, which reads bootstrap/providers.php if present). This repository's
// Laravel upgrade never added it, so Helium\Providers\AppServiceProvider
// has never actually been booted -- it's needed now for issue #42's
// SimilarityEngineInterface container binding (see
// app/Providers/AppServiceProvider.php) to take effect at runtime.
return [
    Helium\Providers\AppServiceProvider::class,
];
