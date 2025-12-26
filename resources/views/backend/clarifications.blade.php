@extends('layouts.app')

@section('title', 'Gerenciar Clarificacoes')

@section('content')
<div class="space-y-6">
    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-foreground">Gerenciar Clarificacoes</h2>
                    <p class="text-sm text-muted-foreground mt-1">Responda as duvidas dos participantes</p>
                </div>
            </div>
        </div>

        <!-- Filter tabs -->
        <div class="border-b border-border">
            <nav class="flex gap-4 px-6">
                <button class="px-4 py-3 text-sm font-medium text-primary border-b-2 border-primary">Todas</button>
                <button class="px-4 py-3 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors">Pendentes</button>
                <button class="px-4 py-3 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors">Respondidas</button>
            </nav>
        </div>

        <div class="p-6 space-y-4">
            @forelse ($clarifications as $clarification)
            <div class="border border-border rounded-lg p-5 {{ !$clarification->answered ? 'bg-yellow-50 dark:bg-yellow-900/10 border-yellow-200 dark:border-yellow-900/30' : 'bg-card' }}">
                <div class="flex flex-col lg:flex-row gap-4">
                    <div class="flex-1">
                        <div class="flex flex-wrap items-center gap-2 mb-3">
                            <span class="font-semibold text-foreground">{{ $clarification->team_name ?? 'Time #' . $clarification->team_id }}</span>
                            @if($clarification->problem)
                                <span class="px-2 py-1 text-xs font-medium bg-primary/10 text-primary rounded">{{ $clarification->problem }}</span>
                            @else
                                <span class="px-2 py-1 text-xs font-medium bg-muted text-muted-foreground rounded">Geral</span>
                            @endif
                            <span class="text-sm text-muted-foreground ml-auto">{{ $clarification->created_at }}</span>
                        </div>

                        <div class="bg-muted/50 rounded-lg p-4 mb-4">
                            <p class="text-sm font-medium text-muted-foreground mb-1">Pergunta:</p>
                            <p class="text-foreground">{{ $clarification->question }}</p>
                        </div>

                        @if($clarification->answered)
                            <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4 border border-green-200 dark:border-green-900/30">
                                <p class="text-sm font-medium text-green-700 dark:text-green-400 mb-1">Resposta:</p>
                                <p class="text-green-800 dark:text-green-300">{{ $clarification->answer }}</p>
                            </div>
                        @else
                            <form action="/backend/clarifications/{{ $clarification->id }}/answer" method="POST">
                                @csrf
                                <div class="mb-3">
                                    <textarea name="answer" rows="3" required
                                        class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground placeholder-muted-foreground focus:ring-2 focus:ring-primary focus:border-transparent resize-none"
                                        placeholder="Digite sua resposta..."></textarea>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                        Responder
                                    </button>
                                    <button type="button" onclick="this.form.answer.value='Sem comentarios.'"
                                        class="px-4 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 transition-colors">
                                        Sem Comentarios
                                    </button>
                                    <button type="button" onclick="this.form.answer.value='Leia o enunciado com atencao.'"
                                        class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                                        Leia o Enunciado
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>

                    <div class="flex lg:flex-col items-center justify-center lg:w-32">
                        @if($clarification->answered)
                            <span class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 rounded-lg">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                                Respondida
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400 rounded-lg">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                Pendente
                            </span>
                        @endif
                    </div>
                </div>
            </div>
            @empty
            <div class="text-center py-12">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-muted-foreground/50 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                </svg>
                <p class="text-muted-foreground">Nenhuma clarificacao recebida</p>
            </div>
            @endforelse
        </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-card rounded-lg border border-border shadow-sm p-6 text-center">
            <div class="inline-flex items-center justify-center w-12 h-12 bg-blue-100 dark:bg-blue-900/30 rounded-lg mb-3">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                </svg>
            </div>
            <p class="text-sm text-muted-foreground">Total de Perguntas</p>
            <p class="text-3xl font-bold text-foreground mt-1">{{ $clarifications->count() }}</p>
        </div>
        <div class="bg-card rounded-lg border border-border shadow-sm p-6 text-center">
            <div class="inline-flex items-center justify-center w-12 h-12 bg-yellow-100 dark:bg-yellow-900/30 rounded-lg mb-3">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-yellow-600 dark:text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <p class="text-sm text-muted-foreground">Pendentes</p>
            <p class="text-3xl font-bold text-yellow-600 mt-1">{{ $clarifications->where('answered', false)->count() }}</p>
        </div>
        <div class="bg-card rounded-lg border border-border shadow-sm p-6 text-center">
            <div class="inline-flex items-center justify-center w-12 h-12 bg-green-100 dark:bg-green-900/30 rounded-lg mb-3">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <p class="text-sm text-muted-foreground">Respondidas</p>
            <p class="text-3xl font-bold text-green-600 mt-1">{{ $clarifications->where('answered', true)->count() }}</p>
        </div>
    </div>
</div>
@endsection
