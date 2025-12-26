@extends('layouts.app')

@section('title', 'Clarificações')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Clarifications List -->
    <div class="lg:col-span-2">
        <div class="rounded-xl border border-border bg-card overflow-hidden">
            <div class="border-b border-border px-6 py-4">
                <h3 class="text-lg font-semibold flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                    </svg>
                    Clarificações
                </h3>
                <p class="text-sm text-muted-foreground">Perguntas e respostas sobre os problemas da competição</p>
            </div>
            <div class="divide-y divide-border">
                @forelse ($clarifications ?? [] as $clarification)
                <div class="p-4">
                    <div class="flex gap-4">
                        <div class="flex-shrink-0">
                            @if($clarification->answered)
                                <span class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-success/10 text-success">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                    </svg>
                                </span>
                            @else
                                <span class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-warning/10 text-warning">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </span>
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                <span class="font-semibold">{{ $clarification->team_name ?? 'Time' }}</span>
                                <span class="text-muted-foreground">-</span>
                                <span class="text-muted-foreground">Problema {{ $clarification->problem ?? 'Geral' }}</span>
                                <span class="ml-auto text-xs text-muted-foreground">{{ $clarification->created_at }}</span>
                            </div>
                            <div class="rounded-lg bg-muted/50 p-3 mb-2">
                                <p class="text-sm font-medium text-muted-foreground mb-1">Pergunta:</p>
                                <p class="text-sm">{{ $clarification->question }}</p>
                            </div>
                            @if($clarification->answer)
                            <div class="rounded-lg bg-success/10 border border-success/20 p-3">
                                <p class="text-sm font-medium text-success mb-1">Resposta do Júri:</p>
                                <p class="text-sm">{{ $clarification->answer }}</p>
                            </div>
                            @endif
                        </div>
                    </div>
                </div>
                @empty
                <div class="py-12 text-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-muted-foreground/50 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                    </svg>
                    <p class="text-muted-foreground">Nenhuma clarificação ainda</p>
                </div>
                @endforelse
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="space-y-6">
        <!-- New Clarification Form -->
        <div class="rounded-xl border border-border bg-card overflow-hidden">
            <div class="border-b border-border px-6 py-4">
                <h3 class="text-lg font-semibold flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                    </svg>
                    Nova Pergunta
                </h3>
            </div>
            <div class="p-6">
                <form action="/clarifications" method="POST" class="space-y-4">
                    @csrf
                    <div class="space-y-2">
                        <label for="problem_id" class="block text-sm font-medium text-foreground">Problema</label>
                        <select name="problem_id" id="problem_id" class="flex h-10 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2">
                            <option value="">Geral</option>
                            @foreach($exercises ?? [] as $exercise)
                                <option value="{{ $exercise->exercise_id }}">
                                    {{ chr(65 + $loop->index) }} - {{ $exercise->exerciseName }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="space-y-2">
                        <label for="question" class="block text-sm font-medium text-foreground">Sua Pergunta</label>
                        <textarea
                            name="question"
                            id="question"
                            rows="4"
                            required
                            placeholder="Descreva sua dúvida de forma clara e objetiva..."
                            class="flex min-h-[80px] w-full rounded-lg border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                        ></textarea>
                    </div>
                    <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground hover:bg-primary/90 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                        </svg>
                        Enviar Pergunta
                    </button>
                </form>
            </div>
        </div>

        <!-- Instructions -->
        <div class="rounded-xl border border-border bg-card overflow-hidden">
            <div class="border-b border-border px-6 py-4">
                <h3 class="text-lg font-semibold flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Instruções
                </h3>
            </div>
            <div class="p-6">
                <ul class="space-y-2 text-sm text-muted-foreground">
                    <li class="flex items-start gap-2">
                        <span class="text-primary mt-0.5">•</span>
                        <span>Perguntas devem ser claras e objetivas</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="text-primary mt-0.5">•</span>
                        <span>Não inclua código fonte nas perguntas</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="text-primary mt-0.5">•</span>
                        <span>Use para dúvidas sobre enunciado ou ambiente</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="text-primary mt-0.5">•</span>
                        <span>Respostas importantes são publicadas para todos</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="text-primary mt-0.5">•</span>
                        <span>O júri pode responder "Sem comentários" para perguntas inválidas</span>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Legend -->
        <div class="rounded-xl border border-border bg-card p-6">
            <div class="space-y-3">
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-success/10 text-success">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                    </span>
                    <span class="text-sm">Pergunta respondida</span>
                </div>
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-warning/10 text-warning">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </span>
                    <span class="text-sm">Aguardando resposta</span>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
