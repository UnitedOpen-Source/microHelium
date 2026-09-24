<!doctype html>
<html lang="{{ \App\Support\InterfaceLocale::htmlLang() }}" class="h-full">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="{{ asset('img/favicon.ico') }}">
    <title>@yield('title', __('Entrar')) · MicroHelium</title>
    @include('partials.theme-init')
    @include('partials.i18n-catalog')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<a href="#main-content" class="skip-link">{{ __('Pular para o conteúdo') }}</a>
<div id="app" class="auth-shell">
    <aside class="auth-story">
        @include('partials.brand')
        <div class="auth-story-copy"><span class="eyebrow">{{ __('IDEIAS. CÓDIGO. CONQUISTAS.') }}</span>
            <h2>{!! __('Seu próximo<br>desafio começa<br><em>aqui.</em>') !!}</h2>
            <p>{{ __('Um espaço para resolver problemas, evoluir com seu time e transformar conhecimento em soluções.') }}</p>
            <div class="code-window" aria-hidden="true"><span class="code-window-label" translate="no">solution.cpp</span><code><span>// {{ __('uma ideia de cada vez') }}</span><br><span translate="no">while (challenge) {<br>&nbsp;&nbsp;learn();<br>&nbsp;&nbsp;build();<br>&nbsp;&nbsp;try_again();<br>}</span></code><span class="code-result">✓ {{ __('Pronto para o próximo desafio') }}</span></div>
        </div>
        <p class="text-sm">MicroHelium · {{ __('Competições de programação') }}</p>
    </aside>
    <main id="main-content" tabindex="-1" class="auth-main">
        <div class="auth-topbar"><a href="/" class="text-sm text-muted-foreground hover:text-primary">← {{ __('Voltar ao início') }}</a><div class="flex items-center gap-2">@include('partials.locale-switcher')<theme-toggle></theme-toggle></div></div>
        <div class="auth-form">
            <p class="eyebrow mb-3">{{ __('BEM-VINDO À ARENA') }}</p>
            <h1>@yield('auth-title', 'MicroHelium')</h1>
            <p class="text-muted-foreground mt-3 mb-8">@yield('auth-subtitle', __('Sistema de Maratonas de Programação'))</p>
            @foreach(['error' => 'destructive', 'success' => 'success', 'status' => 'success'] as $key => $tone)
                @if(session($key))<div role="status" class="mb-4 rounded-lg border p-4 text-sm">{{ session($key) }}</div>@endif
            @endforeach
            @include('partials.error-summary')
            @yield('content')
            @hasSection('footer')<div class="auth-footer">@yield('footer')</div>@endif
        </div>
        <p class="auth-copyright">&copy; {{ date('Y') }} MicroHelium</p>
        {{-- AGPL-3.0 secao 13: quem ve o login ja esta usando o sistema pela rede. --}}
        <p class="auth-copyright">{{ __('Software livre sob') }} <a href="{{ config('app.source_url') }}/blob/master/LICENSE">AGPL-3.0-or-later</a> — <a href="{{ config('app.source_url') }}">{{ __('código-fonte desta instalação') }}</a></p>
    </main>
</div>
@yield('scripts')
</body></html>
