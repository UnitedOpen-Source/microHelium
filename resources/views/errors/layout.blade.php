<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · MicroHelium</title>
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div id="app" class="min-h-screen flex flex-col">
        <header class="flex justify-between items-center p-6">@include('partials.brand')<theme-toggle></theme-toggle></header>
        <main class="flex-1 flex items-center justify-center p-6"><div class="max-w-lg text-center">
            <p class="font-mono text-primary text-7xl font-semibold mb-6">@yield('code')</p>
            <h1 class="text-3xl font-semibold tracking-tight mb-4">@yield('title')</h1>
            <p class="text-muted-foreground leading-relaxed mb-8">@yield('message')</p>
            <div class="flex flex-wrap justify-center gap-3"><a href="/" class="button-primary">Voltar ao início</a><a href="/ajuda" class="button-secondary">Consultar ajuda</a></div>
        </div></main>
    </div>
</body></html>
