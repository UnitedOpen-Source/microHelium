<!doctype html>
<html lang="{{ app()->getLocale() }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="{{ asset('img/favicon.ico') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'MicroHelium') }} - @yield('title', 'Setup')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        if (localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark')
        }
    </script>
</head>
<body class="h-full bg-background text-foreground antialiased">
    <div id="app" class="min-h-full">
        <div class="min-h-screen flex flex-col">
            <!-- Top bar -->
            <header class="border-b border-border bg-background/95 backdrop-blur">
                <div class="max-w-4xl mx-auto px-4 py-4 flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <img src="{{ asset('img/Logo.png') }}" alt="MicroHelium" class="h-8 w-auto dark:invert" onerror="this.parentElement.innerHTML='<span class=\'text-xl font-bold text-primary\'>MicroHelium</span>'">
                        <span class="text-lg font-semibold">@yield('title', 'Setup')</span>
                    </div>
                    <div class="flex items-center gap-4">
                        <theme-toggle></theme-toggle>
                        @if(Auth::check())
                            <span class="text-sm text-muted-foreground">{{ Auth::user()->fullname ?? Auth::user()->username }}</span>
                        @endif
                    </div>
                </div>
            </header>

            <!-- Main content -->
            <main class="flex-1 py-8">
                <div class="max-w-4xl mx-auto px-4">
                    @if(session('success'))
                        <div class="mb-6 rounded-lg border border-green-500/50 bg-green-500/10 p-4 text-green-600 dark:text-green-400">
                            <div class="flex items-center gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span>{{ session('success') }}</span>
                            </div>
                        </div>
                    @endif
                    @if(session('error'))
                        <div class="mb-6 rounded-lg border border-red-500/50 bg-red-500/10 p-4 text-red-600 dark:text-red-400">
                            <div class="flex items-center gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span>{{ session('error') }}</span>
                            </div>
                        </div>
                    @endif
                    @if(session('info'))
                        <div class="mb-6 rounded-lg border border-blue-500/50 bg-blue-500/10 p-4 text-blue-600 dark:text-blue-400">
                            <div class="flex items-center gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span>{{ session('info') }}</span>
                            </div>
                        </div>
                    @endif

                    @yield('content')
                </div>
            </main>

            <!-- Footer -->
            <footer class="border-t border-border py-4">
                <div class="max-w-4xl mx-auto px-4 text-center text-sm text-muted-foreground">
                    &copy; {{ date('Y') }} <a href="https://github.com/UniteOpenSource/microHelium" class="hover:text-foreground transition-colors">MicroHelium</a>
                </div>
            </footer>
        </div>
    </div>
    @yield('scripts')
</body>
</html>
