@extends('layouts.app')

@section('title', __('Pedir impressão'))

@section('description', __('Envie um arquivo para a equipe de apoio imprimir.'))

@section('content')
<div class="space-y-6 max-w-3xl">
    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <h2 class="text-xl font-semibold text-foreground">{{ __('Pedir impressão') }}</h2>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('O arquivo vai para a fila da equipe de apoio da sua sede, que leva o papel até a sua mesa.') }}
            </p>
        </div>

        @if(session('success'))
            <div class="mx-6 mt-6 p-4 rounded-lg bg-success-soft text-success text-sm" role="status">{{ session('success') }}</div>
        @endif

        @if(!$contest || !$contest->isRunning())
            <div class="p-6">
                <p class="text-sm text-muted-foreground">{{ __('A competição não está em andamento no momento.') }}</p>
            </div>
        @else
        <form method="POST" action="{{ route('print.store') }}" enctype="multipart/form-data" class="p-6 space-y-4">
            @csrf

            <div>
                <label for="file" class="block text-sm font-medium text-foreground mb-1">{{ __('Arquivo') }}</label>
                <input id="file" type="file" name="file" required
                       accept="{{ collect($extensions)->map(fn ($e) => '.'.$e)->implode(',') }}"
                       class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground">
                <p class="text-xs text-muted-foreground mt-1">
                    {{ __('Até :max KB. Formatos aceitos: :formats.', ['max' => $maxKb, 'formats' => implode(', ', $extensions)]) }}
                </p>
                @error('file')<p class="text-sm text-destructive mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="description" class="block text-sm font-medium text-foreground mb-1">{{ __('Observação') }} <span class="text-muted-foreground">{{ __('(opcional)') }}</span></label>
                <input id="description" type="text" name="description" value="{{ old('description') }}" maxlength="120"
                       placeholder="{{ __('ex.: frente e verso') }}"
                       class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground">
                @error('description')<p class="text-sm text-destructive mt-1">{{ $message }}</p>@enderror
            </div>

            <button type="submit" class="px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary-hover transition-colors">
                {{ __('Enviar para impressão') }}
            </button>
        </form>
        @endif
    </div>

    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <h2 class="text-lg font-semibold text-foreground">{{ __('Seus pedidos') }}</h2>
        </div>
        <div class="divide-y divide-border">
            @forelse($requests as $request)
                <div class="px-6 py-3 flex items-center justify-between gap-4">
                    <div>
                        <p class="text-sm text-foreground">#{{ $request->task_number }} · {{ $request->filename }}</p>
                        <p class="text-xs text-muted-foreground">{{ $request->created_at?->format('d/m H:i') }}</p>
                    </div>
                    <span class="px-2 py-1 text-xs font-medium rounded {{ $request->isDone() ? 'bg-success-soft text-success' : 'bg-muted text-muted-foreground' }}">
                        {{ $request->isDone() ? __('Impresso') : __('Na fila') }}
                    </span>
                </div>
            @empty
                <div class="px-6 py-8 text-center text-sm text-muted-foreground">{{ __('Você ainda não pediu nenhuma impressão.') }}</div>
            @endforelse
        </div>
    </div>
</div>
@endsection
