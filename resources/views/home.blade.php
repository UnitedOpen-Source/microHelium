@extends('layouts.app')
@section('title', 'Visão geral')
@section('description', 'Seu ponto de partida para a próxima solução.')
@section('page-actions')<a href="/wizard" class="button-secondary">Primeiros passos <span aria-hidden="true">↗</span></a>@endsection
@section('content')
<section class="hero-panel">
    <div><p class="eyebrow">PRONTO PARA O PRÓXIMO DESAFIO?</p><h2>Grandes soluções<br>começam com uma tentativa.</h2><p>Escolha um problema, desenvolva sua ideia e acompanhe cada conquista do seu time.</p><a href="/exercises" class="button-primary mt-5">Explorar problemas <span aria-hidden="true">→</span></a></div>
    <div class="hero-art" aria-hidden="true">{ }</div>
</section>
<dl class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
    @foreach([['Problemas disponíveis', $totalProblems ?? 0], ['Times participantes', $totalTeams ?? 0], ['Soluções enviadas', $totalSubmissions ?? 0], ['Soluções aceitas', $acceptedSubmissions ?? 0]] as [$label, $value])
    <div class="surface stat-card"><dt>{{ $label }}</dt><dd>{{ number_format($value, 0, ',', '.') }}</dd></div>
    @endforeach
</dl>
<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
    <div class="xl:col-span-2 min-w-0">
        <!-- Recent Submissions -->
        <div class="rounded-xl border border-border bg-card overflow-hidden">
            <div class="border-b border-border px-6 py-4">
                <h2 class="text-lg font-semibold flex items-center gap-2">
                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Submissões Recentes
                </h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-border bg-muted/50">
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">ID</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Time</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Problema</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Linguagem</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Resultado</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Tempo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse($recentSubmissions ?? [] as $submission)
                        <tr class="hover:bg-muted/50 transition-colors">
                            <td class="px-4 py-3 text-sm">{{ $submission->id }}</td>
                            <td class="px-4 py-3 text-sm font-medium">{{ $submission->team_name }}</td>
                            <td class="px-4 py-3 text-sm">{{ $submission->problem_name }}</td>
                            <td class="px-4 py-3 text-sm">{{ $submission->language }}</td>
                            <td class="px-4 py-3 text-sm">
                                @include('partials.verdict', ['code' => $submission->result, 'compact' => true])
                            </td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">{{ $submission->time !== null ? $submission->time . 's' : '—' }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">
                                <p class="font-medium text-foreground mb-1">A competição começa com uma ideia.</p><p>Os envios mais recentes aparecerão aqui.</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>
    <aside class="space-y-5">
        <section class="surface p-6"><p class="eyebrow mb-3">SUA COMPETIÇÃO</p><contest-timer></contest-timer><p class="text-sm text-muted-foreground mt-4 mb-5">Confira a classificação e acompanhe os resultados dos times.</p><a href="/scoreboard" class="button-secondary w-full">Abrir placar →</a></section>
        <section class="surface p-6"><h2 class="font-semibold mb-2">Uma dúvida no caminho?</h2><p class="text-sm text-muted-foreground mb-5">Consulte o guia ou envie uma pergunta para a organização.</p><div class="flex flex-col gap-3"><a href="/ajuda" class="text-sm text-primary font-semibold">Guia da competição →</a><a href="/clarifications" class="text-sm text-primary font-semibold">Ver clarificações →</a></div></section>
    </aside>
</div>
@endsection
