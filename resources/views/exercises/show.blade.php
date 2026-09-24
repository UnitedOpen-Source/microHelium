@extends('layouts.app')
@section('title', $problem->name ?? __('Problema'))
@section('description', __('Leia o enunciado, confira os limites e prepare sua solução.'))
@section('page-actions')<a href="/exercises" class="button-secondary">← {{ __('Todos os problemas') }}</a>@endsection
@section('content')
<div class="grid gap-6 lg:grid-cols-3">
    <article class="surface lg:col-span-2 min-w-0">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border p-6">
            <span class="text-sm text-muted-foreground">{{ $problem->short_name }}</span>
            <span class="text-sm text-muted-foreground">{{ $problem->color_name }}</span>
        </div>
        <div class="p-6 sm:p-8 space-y-8">
            {{-- Issue #137: a problem imported from a BOCA package has no `description` text at all -- its
                 statement is the file in description/. Showing only the text column meant the normal import
                 path led to "o enunciado ainda não foi disponibilizado" with the PDF already on the server.
                 When both exist they are both shown: the text is the summary, the file is the document. --}}
            <section><h2 class="text-lg font-semibold mb-3">{{ __('Enunciado') }}</h2>
                @if($problem->description)
                <div class="reading-content whitespace-pre-wrap break-words">{{ $problem->description }}</div>
                @elseif(! $hasStatementFile)
                <div class="reading-content">{{ __('O enunciado ainda não foi disponibilizado.') }}</div>
                @endif
                @if($hasStatementFile)
                <div class="mt-4 rounded-xl border border-border p-4 flex flex-wrap items-center justify-between gap-3">
                    @if($statementReleased)
                    <p class="text-sm text-muted-foreground">{{ $problem->description ? __('Enunciado oficial em arquivo — o texto acima é apenas um resumo.') : __('Enunciado oficial em arquivo.') }}</p>
                    <a href="{{ route('exercise.statement', $problem) }}" target="_blank" rel="noopener" class="button-secondary">{{ __('Abrir enunciado completo') }} →</a>
                    @else
                    <p class="text-sm text-muted-foreground">{{ __('O enunciado completo será liberado no início da prova.') }}</p>
                    @endif
                </div>
                @endif
            </section>
            @php $samples = $problem->testCases()->where('is_sample', true)->get(); @endphp
            @if($samples->isNotEmpty())
            <section><h2 class="text-lg font-semibold mb-4">{{ __('Exemplos') }}</h2><div class="space-y-4">
                @foreach($samples as $sample)
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="min-w-0 rounded-xl border border-border overflow-hidden"><h3 class="px-4 py-3 border-b border-border text-xs font-semibold bg-muted">{{ __('Entrada de exemplo') }}</h3><pre tabindex="0" translate="no" class="p-4 text-sm leading-relaxed">{{ file_exists($sample->getInputPath()) ? file_get_contents($sample->getInputPath()) : __('Exemplo não disponível.') }}</pre></div>
                    <div class="min-w-0 rounded-xl border border-border overflow-hidden"><h3 class="px-4 py-3 border-b border-border text-xs font-semibold bg-muted">{{ __('Saída de exemplo') }}</h3><pre tabindex="0" translate="no" class="p-4 text-sm leading-relaxed">{{ file_exists($sample->getOutputPath()) ? file_get_contents($sample->getOutputPath()) : __('Exemplo não disponível.') }}</pre></div>
                </div>
                @endforeach
            </div></section>
            @endif
        </div>
    </article>
    <aside class="space-y-5">
        <section class="surface p-6"><p class="eyebrow mb-3">{{ __('DA IDEIA À SOLUÇÃO') }}</p><h2 class="text-lg font-semibold mb-2">{{ __('Código pronto?') }}</h2><p class="text-sm text-muted-foreground mb-5">{{ __('Teste os exemplos e confira a linguagem antes de enviar.') }}</p><a href="{{ route('exercise.submit', $problem) }}" class="button-primary w-full">{{ __('Submeter solução') }} →</a></section>
        <section class="surface p-6"><h2 class="font-semibold mb-5">{{ __('Limites do problema') }}</h2><dl class="space-y-4 text-sm">
            <div class="flex justify-between gap-3"><dt class="text-muted-foreground">{{ __('Tempo') }}</dt><dd class="font-mono font-semibold">{{ $problem->time_limit ?? 10 }} s</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-muted-foreground">{{ __('Memória') }}</dt><dd class="font-mono font-semibold">{{ $problem->memory_limit ?? 512 }} MB</dd></div>
        </dl></section>
        <section class="surface p-6"><h2 class="font-semibold mb-3">{{ __('Precisa de ajuda?') }}</h2><p class="text-sm text-muted-foreground mb-4">{{ __('Pergunte à organização sobre o enunciado ou os formatos de entrada e saída.') }}</p><a href="/clarifications" class="text-primary text-sm font-semibold">{{ __('Ver clarificações') }} →</a></section>
    </aside>
</div>
@endsection
