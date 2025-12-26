<!doctype html>
<html lang="{{ app()->getLocale() }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="{{ asset('img/favicon.ico') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'MicroHelium') }} - @yield('title', 'Login')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        // Prevent flash of wrong theme
        if (localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark')
        }
    </script>
</head>
<body class="h-full bg-gradient-to-br from-primary/20 via-background to-primary/10 dark:from-primary/10 dark:via-background dark:to-primary/5">
    <div id="app" class="min-h-full flex flex-col items-center justify-center p-4">
        <!-- Theme toggle in corner -->
        <div class="fixed top-4 right-4">
            <theme-toggle></theme-toggle>
        </div>

        <div class="w-full max-w-md">
            <!-- Card -->
            <div class="rounded-2xl border border-border bg-card shadow-xl overflow-hidden">
                <!-- Header -->
                <div class="bg-gradient-to-r from-primary to-primary/80 px-8 py-10 text-center">
                    <a href="/" class="inline-block">
                        <img
                            src="{{ asset('img/Logo.png') }}"
                            alt="MicroHelium"
                            class="h-12 w-auto mx-auto mb-4 brightness-0 invert"
                            onerror="this.style.display='none'"
                        >
                    </a>
                    <h1 class="text-2xl font-bold text-primary-foreground">
                        @yield('auth-title', 'MicroHelium')
                    </h1>
                    <p class="mt-2 text-primary-foreground/80 text-sm">
                        @yield('auth-subtitle', 'Sistema de Maratonas de Programação')
                    </p>
                </div>

                <!-- Body -->
                <div class="p-8">
                    @if(session('error'))
                        <div class="mb-4 rounded-lg border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive">
                            {{ session('error') }}
                        </div>
                    @endif
                    @if(session('success'))
                        <div class="mb-4 rounded-lg border border-success/50 bg-success/10 p-4 text-sm text-success">
                            {{ session('success') }}
                        </div>
                    @endif
                    @if(session('status'))
                        <div class="mb-4 rounded-lg border border-success/50 bg-success/10 p-4 text-sm text-success">
                            {{ session('status') }}
                        </div>
                    @endif

                    @yield('content')
                </div>

                <!-- Footer -->
                @hasSection('footer')
                <div class="border-t border-border bg-muted/50 px-8 py-4 text-center text-sm text-muted-foreground">
                    @yield('footer')
                </div>
                @endif
            </div>

            <!-- Bottom links -->
            <p class="mt-6 text-center text-sm text-muted-foreground">
                &copy; {{ date('Y') }} <a href="https://github.com/UniteOpenSource/microHelium" class="hover:text-foreground transition-colors">MicroHelium</a>
            </p>
        </div>
    </div>
</body>
</html>
