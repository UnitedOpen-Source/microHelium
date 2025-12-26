<!doctype html>
<html lang="{{ app()->getLocale() }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="{{ asset('img/favicon.ico') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'MicroHelium') }}</title>
    <meta name="keywords" content="hackathon, programação, maratona, contest">
    <meta name="description" content="MicroHelium - Sistema de Gerenciamento de Maratonas de Programação">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        // Prevent flash of wrong theme
        if (localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark')
        }
    </script>
</head>
<body class="h-full bg-background text-foreground antialiased">
    <div id="app" class="min-h-full flex flex-col">
        <!-- Navbar -->
        <nav class="sticky top-0 z-50 border-b border-border bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60">
            <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 items-center justify-between">
                    <!-- Logo -->
                    <a href="/" class="flex items-center">
                        <img src="{{ asset('img/Logo.png') }}" alt="MicroHelium" class="h-8 w-auto dark:invert" onerror="this.parentElement.innerHTML='<span class=\'text-xl font-bold text-primary\'>MicroHelium</span>'">
                    </a>

                    <!-- Navigation Links -->
                    <div class="hidden md:flex items-center gap-6">
                        @if (Auth::guest())
                            <a href="{{ route('login') }}" class="text-sm font-medium text-muted-foreground hover:text-foreground transition-colors">
                                Entrar
                            </a>
                            <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 transition-colors">
                                Cadastrar
                            </a>
                        @else
                            <div class="relative">
                                <button
                                    onclick="toggleUserMenu()"
                                    class="flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
                                >
                                    {{ Auth::user()->fullname ?? Auth::user()->username }}
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </button>
                                <div id="user-menu" class="hidden absolute right-0 mt-2 w-48 rounded-lg border border-border bg-popover p-1 shadow-lg">
                                    <a href="/home" class="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-popover-foreground hover:bg-accent">
                                        Dashboard
                                    </a>
                                    <div class="my-1 h-px bg-border"></div>
                                    <a
                                        href="{{ route('logout') }}"
                                        onclick="event.preventDefault(); document.getElementById('logout-form').submit();"
                                        class="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-destructive hover:bg-destructive/10"
                                    >
                                        Sair
                                    </a>
                                </div>
                            </div>
                        @endif

                        <!-- Theme toggle -->
                        <theme-toggle></theme-toggle>
                    </div>

                    <!-- Mobile menu button -->
                    <div class="md:hidden flex items-center gap-2">
                        <theme-toggle></theme-toggle>
                        <button
                            onclick="toggleMobileMenu()"
                            class="inline-flex items-center justify-center rounded-md p-2 text-muted-foreground hover:bg-accent hover:text-accent-foreground"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Mobile menu -->
            <div id="mobile-menu" class="hidden md:hidden border-t border-border">
                <div class="space-y-1 px-4 py-3">
                    @if (Auth::guest())
                        <a href="{{ route('login') }}" class="block rounded-lg px-3 py-2 text-base font-medium text-muted-foreground hover:bg-accent hover:text-accent-foreground">
                            Entrar
                        </a>
                        <a href="{{ route('register') }}" class="block rounded-lg px-3 py-2 text-base font-medium text-muted-foreground hover:bg-accent hover:text-accent-foreground">
                            Cadastrar
                        </a>
                    @else
                        <a href="/home" class="block rounded-lg px-3 py-2 text-base font-medium text-muted-foreground hover:bg-accent hover:text-accent-foreground">
                            Dashboard
                        </a>
                        <a
                            href="{{ route('logout') }}"
                            onclick="event.preventDefault(); document.getElementById('logout-form').submit();"
                            class="block rounded-lg px-3 py-2 text-base font-medium text-destructive hover:bg-destructive/10"
                        >
                            Sair
                        </a>
                    @endif
                </div>
            </div>
        </nav>

        <!-- Main content -->
        <main class="flex-1">
            @yield('content')
        </main>

        <!-- Footer -->
        <footer class="border-t border-border py-8">
            <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                <p class="text-center text-sm text-muted-foreground">
                    &copy; {{ date('Y') }} <a href="https://github.com/UniteOpenSource/microHelium" class="hover:text-foreground transition-colors">MicroHelium</a> -
                    Sistema de Maratonas de Programação
                </p>
            </div>
        </footer>
    </div>

    @auth
    <form id="logout-form" action="{{ route('logout') }}" method="POST" class="hidden">
        @csrf
    </form>
    @endauth

    <script>
        function toggleUserMenu() {
            document.getElementById('user-menu')?.classList.toggle('hidden');
        }

        function toggleMobileMenu() {
            document.getElementById('mobile-menu')?.classList.toggle('hidden');
        }

        // Close menus when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('#user-menu') && !event.target.closest('button')) {
                document.getElementById('user-menu')?.classList.add('hidden');
            }
        });
    </script>
    @yield('scripts')
</body>
</html>
