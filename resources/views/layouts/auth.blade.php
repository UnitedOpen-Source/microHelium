<!doctype html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="{{ asset('img/favicon.ico') }}">
    <title>@yield('title', 'Entrar') · MicroHelium</title>
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<a href="#main-content" class="skip-link">Pular para o conteúdo</a>
<div id="app" class="auth-shell">
    <aside class="auth-story">
        @include('partials.brand')
        <div class="auth-story-copy"><span class="eyebrow">IDEIAS. CÓDIGO. CONQUISTAS.</span>
            <h2>Seu próximo<br>desafio começa<br><em>aqui.</em></h2>
            <p>Um espaço para resolver problemas, evoluir com seu time e transformar conhecimento em soluções.</p>
            <div class="code-window" aria-hidden="true"><span class="code-window-label">solution.cpp</span><code><span>// uma ideia de cada vez</span><br>while (challenge) {<br>&nbsp;&nbsp;learn();<br>&nbsp;&nbsp;build();<br>&nbsp;&nbsp;try_again();<br>}</code><span class="code-result">✓ Pronto para o próximo desafio</span></div>
        </div>
        <p class="text-sm">MicroHelium · Competições de programação</p>
    </aside>
    <main id="main-content" tabindex="-1" class="auth-main">
        <div class="auth-topbar"><a href="/" class="text-sm text-muted-foreground hover:text-primary">← Voltar ao início</a><theme-toggle></theme-toggle></div>
        <div class="auth-form">
            <p class="eyebrow mb-3">BEM-VINDO À ARENA</p>
            <h1>@yield('auth-title', 'MicroHelium')</h1>
            <p class="text-muted-foreground mt-3 mb-8">@yield('auth-subtitle', 'Sistema de Maratonas de Programação')</p>
            @foreach(['error' => 'destructive', 'success' => 'success', 'status' => 'success'] as $key => $tone)
                @if(session($key))<div role="status" class="mb-4 rounded-lg border p-4 text-sm">{{ session($key) }}</div>@endif
            @endforeach
            @yield('content')
            @hasSection('footer')<div class="auth-footer">@yield('footer')</div>@endif
        </div>
        <p class="auth-copyright">&copy; {{ date('Y') }} MicroHelium</p>
    </main>
</div>
@yield('scripts')
</body></html>
