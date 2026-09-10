@extends('layouts.app')

@section('title', 'Submissão #' . $run->run_number)

@section('content')
<div class="space-y-6">
    <div class="rounded-xl border border-border bg-card overflow-hidden">
        <div class="border-b border-border px-6 py-4 flex items-center justify-between">
            <div>
                <h3 class="text-lg font-semibold">Submissão #{{ $run->run_number }}</h3>
                <p class="text-sm text-muted-foreground">
                    {{ $run->problem->short_name }} - {{ $run->problem->name }} &middot; {{ $run->language->name }}
                </p>
            </div>
            @if($run->answer)
                <span class="px-3 py-1.5 text-sm font-medium rounded-lg {{ $run->answer->is_accepted ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' }}">
                    {{ $run->answer->name }}
                </span>
            @else
                <span class="px-3 py-1.5 text-sm font-medium rounded-lg bg-muted text-muted-foreground">
                    {{ $run->status === 'judging' ? 'Julgando...' : 'Aguardando julgamento' }}
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
                <p class="text-sm font-medium">{{ $run->filename }}</p>
            </div>
            <div>
                <p class="text-xs text-muted-foreground uppercase">Status</p>
                <p class="text-sm font-medium">{{ ucfirst($run->status) }}</p>
            </div>
            <div>
                <p class="text-xs text-muted-foreground uppercase">Resultado do Judge</p>
                <p class="text-sm font-medium">{{ $run->auto_judge_result ?? '-' }}</p>
            </div>
        </div>

        @if($run->auto_judge_stdout || $run->auto_judge_stderr)
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4 border-b border-border">
            @if($run->auto_judge_stdout)
            <div>
                <p class="text-xs font-medium text-muted-foreground uppercase mb-1">Saida (stdout)</p>
                <pre class="bg-muted rounded p-3 text-xs overflow-x-auto max-h-64 overflow-y-auto">{{ $run->auto_judge_stdout }}</pre>
            </div>
            @endif
            @if($run->auto_judge_stderr)
            <div>
                <p class="text-xs font-medium text-muted-foreground uppercase mb-1">Erros (stderr)</p>
                <pre class="bg-muted rounded p-3 text-xs overflow-x-auto max-h-64 overflow-y-auto">{{ $run->auto_judge_stderr }}</pre>
            </div>
            @endif
        </div>
        @endif

        <div class="p-6">
            <p class="text-xs font-medium text-muted-foreground uppercase mb-2">Codigo Fonte</p>
            @if($sourceCode !== null)
                <pre class="bg-muted rounded p-4 text-sm overflow-x-auto max-h-[32rem] overflow-y-auto">{{ $sourceCode }}</pre>
            @else
                <p class="text-sm text-muted-foreground">Arquivo de codigo fonte nao encontrado.</p>
            @endif
        </div>

        <div class="p-6 border-t border-border">
            <a href="{{ route('submissions') }}" class="px-4 py-2 text-sm border border-border rounded-lg hover:bg-muted transition-colors inline-block">
                Voltar
            </a>
        </div>
    </div>
</div>
@endsection
