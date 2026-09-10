@extends('layouts.app')

@section('title', 'Submissão #' . $run->run_number)

@section('description', 'Confira o resultado, o código enviado e as mensagens da avaliação.')

@section('content')
<div class="space-y-6">
    <div class="rounded-xl border border-border bg-card overflow-hidden">
        <div class="border-b border-border px-6 py-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold">Submissão #{{ $run->run_number }}</h2>
                <p class="text-sm text-muted-foreground">
                    {{ $run->problem->short_name }} - {{ $run->problem->name }} &middot; {{ $run->language->name }}
                </p>
            </div>
            @if($run->answer)
                <span class="px-3 py-1.5 text-sm font-medium rounded-lg {{ $run->answer->is_accepted ? 'bg-success-soft text-success ' : 'bg-destructive-soft text-destructive ' }}">
                    {{ $run->answer->name }}
                </span>
            @else
                <span class="px-3 py-1.5 text-sm font-medium rounded-lg bg-muted text-muted-foreground">
                    {{ $run->status === 'judging' ? 'Julgando…' : 'Aguardando julgamento' }}
                </span>
            @endif
        </div>

        <div class="p-6 grid grid-cols-2 md:grid-cols-4 gap-4 border-b border-border">
            <div>
                <p class="text-xs text-muted-foreground uppercase">Data/Hora</p>
                <p class="text-sm font-medium">{{ $run->created_at->format('d/m/Y H:i:s') }}</p>
            </div>
            <div>
                <p class="text-xs text-muted-foreground uppercase">Arquivo</p>
                <p class="text-sm font-medium break-all">{{ $run->filename }}</p>
            </div>
            <div>
                <p class="text-xs text-muted-foreground uppercase">Status</p>
                <p class="text-sm font-medium">{{ ['pending' => 'Na fila', 'judging' => 'Em avaliação', 'judged' => 'Avaliada'][$run->status] ?? $run->status }}</p>
            </div>
            <div>
                <p class="text-xs text-muted-foreground uppercase">Resultado da avaliação</p>
                <p class="text-sm font-medium">{{ $run->auto_judge_result ?? '-' }}</p>
            </div>
        </div>

        @if($run->auto_judge_stdout || $run->auto_judge_stderr)
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4 border-b border-border">
            @if($run->auto_judge_stdout)
            <div>
                <p class="text-xs font-medium text-muted-foreground uppercase mb-1" id="submission-output">Saída (stdout)</p>
                <pre tabindex="0" translate="no" role="region" aria-labelledby="submission-output" class="bg-muted rounded p-3 text-xs overflow-x-auto max-h-64 overflow-y-auto">{{ $run->auto_judge_stdout }}</pre>
            </div>
            @endif
            @if($run->auto_judge_stderr)
            <div>
                <p class="text-xs font-medium text-muted-foreground uppercase mb-1" id="submission-errors">Erros (stderr)</p>
                <pre tabindex="0" translate="no" role="region" aria-labelledby="submission-errors" class="bg-muted rounded p-3 text-xs overflow-x-auto max-h-64 overflow-y-auto">{{ $run->auto_judge_stderr }}</pre>
            </div>
            @endif
        </div>
        @endif

        <div class="p-6">
            <p class="text-xs font-medium text-muted-foreground uppercase mb-2" id="submission-source">Código-fonte</p>
            @if($sourceCode !== null)
                <pre tabindex="0" translate="no" role="region" aria-labelledby="submission-source" class="bg-muted rounded p-4 text-sm overflow-x-auto max-h-[32rem] overflow-y-auto">{{ $sourceCode }}</pre>
            @else
                <p class="text-sm text-muted-foreground">Arquivo de código-fonte não encontrado.</p>
            @endif
        </div>

        <div class="p-6 border-t border-border">
            <a href="{{ route('submissions') }}" class="px-4 py-2 text-sm border border-border rounded-lg hover:bg-muted transition-colors inline-block">
                Voltar às submissões
            </a>
        </div>
    </div>
</div>
@endsection
