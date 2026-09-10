@extends('layouts.app')

@section('title', $problem->name)

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <div class="bg-card rounded-lg border border-border shadow-sm p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3">
                        <span class="text-2xl font-bold text-primary">{{ $problem->short_name }}</span>
                        <h1 class="text-xl font-semibold text-foreground">{{ $problem->name }}</h1>
                    </div>
                </div>
                @if($problem->color_name)
                <span class="px-3 py-1 text-xs font-medium rounded-full text-white" style="background-color: {{ $problem->color_hex ?? '#666' }}">
                    {{ $problem->color_name }}
                </span>
                @endif
            </div>

            <div class="prose prose-sm max-w-none mt-4 text-foreground">
                {!! nl2br(e($problem->description ?? 'Sem descricao disponivel.')) !!}
            </div>
        </div>

        @php $samples = $problem->testCases()->where('is_sample', true)->get(); @endphp
        @if($samples->isNotEmpty())
        <div class="bg-card rounded-lg border border-border shadow-sm p-6">
            <h2 class="font-semibold text-foreground mb-4">Exemplos</h2>
            <div class="space-y-4">
                @foreach($samples as $sample)
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <p class="text-xs font-medium text-muted-foreground uppercase mb-1">Entrada</p>
                        <pre class="bg-muted rounded p-3 text-sm overflow-x-auto">{{ file_exists($sample->getInputPath()) ? file_get_contents($sample->getInputPath()) : '' }}</pre>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-muted-foreground uppercase mb-1">Saida</p>
                        <pre class="bg-muted rounded p-3 text-sm overflow-x-auto">{{ file_exists($sample->getOutputPath()) ? file_get_contents($sample->getOutputPath()) : '' }}</pre>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>

    <div class="space-y-6">
        <div class="bg-card rounded-lg border border-border shadow-sm p-6">
            <a href="{{ route('exercise.submit', $problem) }}" class="block text-center w-full px-4 py-2.5 bg-primary text-primary-foreground rounded-lg font-medium hover:bg-primary/90 transition-colors">
                Submeter Solucao
            </a>
        </div>

        <div class="bg-card rounded-lg border border-border shadow-sm p-6">
            <h2 class="font-semibold text-foreground mb-3">Limites</h2>
            <dl class="text-sm space-y-2">
                <div class="flex justify-between">
                    <dt class="text-muted-foreground">Tempo Limite</dt>
                    <dd class="font-medium">{{ $problem->time_limit }}s</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-muted-foreground">Memoria</dt>
                    <dd class="font-medium">{{ $problem->memory_limit }} MB</dd>
                </div>
            </dl>
        </div>

        <div class="bg-card rounded-lg border border-border shadow-sm p-6">
            <a href="{{ route('exercises') }}" class="block text-center w-full px-4 py-2 border border-border rounded-lg text-sm hover:bg-muted transition-colors">
                Voltar para Lista
            </a>
        </div>
    </div>
</div>
@endsection
