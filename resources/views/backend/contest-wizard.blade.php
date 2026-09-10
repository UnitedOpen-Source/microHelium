@extends('layouts.setup')

@section('title', 'Criar nova maratona')

@php
    $availableLanguages = \App\Models\Language::getDefaultLanguages();
    // $problemBank is passed from the route
    $problemBank = $problemBank ?? collect([]);
@endphp

@section('content')
<div class="max-w-5xl mx-auto">
    <!-- Progress Steps -->
    <div class="mb-8">
        <div class="flex flex-wrap items-center justify-between gap-3 text-xs sm:text-sm">
            @foreach([1 => 'Info', 2 => 'Agenda', 3 => 'Linguagens', 4 => 'Problemas', 5 => 'Confirmar'] as $num => $label)
            <div class="flex items-center">
                <div id="step{{ $num }}-indicator" class="flex items-center justify-center w-8 h-8 sm:w-10 sm:h-10 {{ $num == 1 ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground' }} rounded-full font-bold text-sm">{{ $num }}</div>
                <span class="ml-1 sm:ml-2 font-medium {{ $num == 1 ? 'text-foreground' : 'text-muted-foreground' }} hidden md:inline">{{ $label }}</span>
            </div>
            @if($num < 5)
            <div class="flex-1 h-1 mx-2 sm:mx-4 bg-border rounded">
                <div id="progress-{{ $num }}-{{ $num+1 }}" class="h-full bg-primary rounded transition-colors duration-300" style="width: 0%"></div>
            </div>
            @endif
            @endforeach
        </div>
    </div>

    @if(session('error'))
    <div class="mb-6 bg-destructive-soft border border-destructive/25 rounded-lg p-4">
        <div class="flex gap-3">
            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-destructive flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <p class="text-sm text-destructive">{{ session('error') }}</p>
        </div>
    </div>
    @endif

    <p id="wizard-status" role="status" class="text-sm text-muted-foreground mb-4">Etapa 1 de 5</p>
    <form novalidate id="wizardForm" action="/backend/contest-wizard" method="POST">
        @csrf

        <!-- Step 1: Basic Info -->
        <div id="step1" class="wizard-step">
            <div class="bg-card rounded-lg border border-border shadow-sm">
                <div class="p-6 border-b border-border">
                    <h2 class="text-xl font-semibold text-foreground">Informações básicas</h2>
                    <p class="text-sm text-muted-foreground mt-1">Defina o nome e descricao da sua maratona</p>
                </div>
                <div class="p-6 space-y-6">
                    <div>
                        <label for="contestName" class="block text-sm font-medium text-foreground mb-2">Nome da Maratona *</label>
                        <input type="text" name="name" value="{{ old('name') }}" id="contestName" maxlength="255" required
                            class="w-full px-4 py-3 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent text-lg"
                            placeholder="Ex.: Maratona de programação">
                    </div>
                    <div>
                        <label for="contestDescription" class="block text-sm font-medium text-foreground mb-2">Descrição</label>
                        <textarea name="description" id="contestDescription" rows="3"
                            class="w-full px-4 py-3 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent resize-none"
                            placeholder="Descreva os objetivos e regras da competicao">{{ old('description') }}</textarea>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="contestPenalty" class="block text-sm font-medium text-foreground mb-2">Penalidade (minutos)</label>
                            <input type="number" name="penalty" id="contestPenalty" required value="{{ old('penalty', 20) }}" min="0"
                                class="w-full px-4 py-3 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        <div>
                            <label for="contestMaxFile" class="block text-sm font-medium text-foreground mb-2">Tamanho Max. Arquivo (KB)</label>
                            <input type="number" name="max_file_size" id="contestMaxFile" required value="{{ old('max_file_size', 100) }}" min="1"
                                class="w-full px-4 py-3 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 2: Schedule -->
        <div id="step2" class="wizard-step hidden">
            <div class="bg-card rounded-lg border border-border shadow-sm">
                <div class="p-6 border-b border-border">
                    <h2 class="text-xl font-semibold text-foreground">Agenda da competição</h2>
                    <p class="text-sm text-muted-foreground mt-1">Configure quando a maratona vai acontecer</p>
                </div>
                <div class="p-6 space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="contestStart" class="block text-sm font-medium text-foreground mb-2">Data e hora de início *</label>
                            <input type="datetime-local" name="start_time" value="{{ old('start_time') }}" id="contestStart" required
                                class="w-full px-4 py-3 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        <div>
                            <label for="contestDuration" class="block text-sm font-medium text-foreground mb-2">Duração (minutos) *</label>
                            <input type="number" name="duration" id="contestDuration" value="{{ old('duration', 300) }}" min="1" required
                                class="w-full px-4 py-3 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-2">Durações rápidas</label>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" data-wizard-action="setDuration" data-value="60" class="px-3 py-1.5 bg-muted text-muted-foreground rounded hover:bg-muted/80">1h</button>
                            <button type="button" data-wizard-action="setDuration" data-value="120" class="px-3 py-1.5 bg-muted text-muted-foreground rounded hover:bg-muted/80">2h</button>
                            <button type="button" data-wizard-action="setDuration" data-value="180" class="px-3 py-1.5 bg-muted text-muted-foreground rounded hover:bg-muted/80">3h</button>
                            <button type="button" data-wizard-action="setDuration" data-value="300" class="px-3 py-1.5 bg-primary text-primary-foreground rounded hover:bg-primary-hover">5h</button>
                        </div>
                    </div>
                    <div>
                        <label for="contestFreeze" class="block text-sm font-medium text-foreground mb-2">Congelamento do Placar (minutos antes do fim)</label>
                        <input type="number" name="freeze_time" id="contestFreeze" required value="{{ old('freeze_time', 60) }}" min="0"
                            class="w-full px-4 py-3 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent">
                    </div>
                    <div class="p-4 bg-muted/50 rounded-lg flex items-center gap-3">
                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <div>
                            <p class="text-sm font-medium text-foreground">Término calculado</p>
                            <p id="calculatedEnd" class="text-lg font-bold text-primary">--</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 3: Languages -->
        <div id="step3" class="wizard-step hidden">
            <div class="bg-card rounded-lg border border-border shadow-sm">
                <div class="p-6 border-b border-border flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-xl font-semibold text-foreground">Linguagens Permitidas</h2>
                        <p class="text-sm text-muted-foreground mt-1">Selecione quais linguagens os participantes poderao usar</p>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" data-wizard-action="selectAllLanguages" class="px-3 py-1.5 bg-primary text-primary-foreground rounded text-sm">Todas</button>
                        <button type="button" data-wizard-action="deselectAllLanguages" class="px-3 py-1.5 bg-muted text-muted-foreground rounded text-sm">Nenhuma</button>
                    </div>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                        @foreach($availableLanguages as $lang)
                        <label class="language-card flex items-center gap-3 p-3 bg-background border-2 rounded-lg cursor-pointer hover:border-primary/50 transition-colors {{ $lang['is_active'] ? 'selected' : '' }}" data-lang="{{ $lang['extension'] }}">
                            <input type="checkbox" name="languages[]" value="{{ $lang['extension'] }}" class="sr-only" @checked(session()->hasOldInput('languages') ? in_array($lang['extension'], old('languages', [])) : $lang['is_active'])>
                            <span class="w-8 h-8 bg-primary-soft rounded flex items-center justify-center flex-shrink-0">
                                <span class="text-xs font-bold text-primary">.{{ $lang['file_ext'] ?? $lang['extension'] }}</span>
                            </span>
                            <span class="font-medium text-foreground text-sm truncate flex-1">{{ $lang['name'] }}</span>
                            <svg aria-hidden="true" class="check-icon h-5 w-5 text-primary flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                        </label>
                        @endforeach
                    </div>
                    <p class="text-sm text-muted-foreground mt-4"><span id="selectedLangCount">{{ collect($availableLanguages)->where('is_active', true)->count() }}</span> linguagem(ns) selecionada(s)</p>
                </div>
            </div>
        </div>

        <!-- Step 4: Problems -->
        <div id="step4" class="wizard-step hidden">
            <div class="bg-card rounded-lg border border-border shadow-sm">
                <div class="p-6 border-b border-border">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="text-xl font-semibold text-foreground">Banco de Problemas</h2>
                            <p class="text-sm text-muted-foreground mt-1">Selecione os problemas para a maratona ({{ $problemBank->count() }} disponiveis)</p>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" data-wizard-action="selectAllProblems" class="px-3 py-1.5 bg-primary text-primary-foreground rounded text-sm">Todos</button>
                            <button type="button" data-wizard-action="deselectAllProblems" class="px-3 py-1.5 bg-muted text-muted-foreground rounded text-sm">Nenhum</button>
                        </div>
                    </div>
                    <!-- Filters -->
                    <div class="flex gap-2 mt-4">
                        <button type="button" data-wizard-action="filterProblems" data-value="all" class="filter-btn px-3 py-1 bg-primary text-primary-foreground rounded text-xs" data-filter="all">Todos</button>
                        <button type="button" data-wizard-action="filterProblems" data-value="easy" class="filter-btn px-3 py-1 bg-success-soft text-success rounded text-xs" data-filter="easy">Fácil</button>
                        <button type="button" data-wizard-action="filterProblems" data-value="medium" class="filter-btn px-3 py-1 bg-warning-soft text-warning rounded text-xs" data-filter="medium">Médio</button>
                        <button type="button" data-wizard-action="filterProblems" data-value="hard" class="filter-btn px-3 py-1 bg-destructive-soft text-destructive rounded text-xs" data-filter="hard">Difícil</button>
                    </div>
                </div>
                <div class="p-6 max-h-96 overflow-y-auto">
                    <div class="space-y-2" id="problemsList">
                        @foreach($problemBank as $problem)
                        <label class="problem-card flex items-center gap-4 p-3 bg-background border-2 border-border rounded-lg cursor-pointer hover:border-primary/50 transition-colors" data-difficulty="{{ $problem->difficulty }}" data-id="{{ $problem->id }}">
                            <input type="checkbox" name="problems[]" value="{{ $problem->id }}" class="sr-only" @checked(in_array($problem->id, old('problems', [])))>
                            <span class="w-16 text-center">
                                <span class="text-xs font-mono font-bold text-primary bg-primary-soft px-2 py-1 rounded">{{ $problem->code }}</span>
                            </span>
                            <span class="flex-1 min-w-0">
                                <span class="font-medium text-foreground truncate">{{ $problem->name }}</span>
                                <span class="text-xs text-muted-foreground truncate">{{ Str::limit($problem->description, 80) }}</span>
                            </span>
                            <span class="px-2 py-0.5 text-xs rounded {{ ['easy' => 'bg-success-soft text-success', 'medium' => 'bg-warning-soft text-warning', 'hard' => 'bg-destructive-soft text-destructive'][$problem->difficulty] ?? 'bg-muted text-muted-foreground' }}">{{ $problem->difficulty_label }}</span>
                            <svg aria-hidden="true" class="check-icon h-5 w-5 text-primary hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                        </label>
                        @endforeach
                    </div>
                </div>
                <div class="p-4 border-t border-border bg-muted/30">
                    <p class="text-sm text-muted-foreground"><span id="selectedProblemCount">0</span> problema(s) selecionado(s)</p>
                </div>
            </div>
        </div>

        <!-- Step 5: Confirmation -->
        <div id="step5" class="wizard-step hidden">
            <div class="bg-card rounded-lg border border-border shadow-sm">
                <div class="p-6 border-b border-border">
                    <h2 class="text-xl font-semibold text-foreground">Confirmar criação</h2>
                    <p class="text-sm text-muted-foreground mt-1">Revise as informacoes antes de criar a maratona</p>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="p-4 bg-muted/50 rounded-lg">
                            <p class="text-xs text-muted-foreground uppercase">Nome</p>
                            <p id="summaryName" class="font-semibold text-foreground">--</p>
                        </div>
                        <div class="p-4 bg-muted/50 rounded-lg">
                            <p class="text-xs text-muted-foreground uppercase">Duracao</p>
                            <p id="summaryDuration" class="font-semibold text-foreground">--</p>
                        </div>
                        <div class="p-4 bg-muted/50 rounded-lg">
                            <p class="text-xs text-muted-foreground uppercase">Inicio</p>
                            <p id="summaryStart" class="font-semibold text-foreground">--</p>
                        </div>
                        <div class="p-4 bg-muted/50 rounded-lg">
                            <p class="text-xs text-muted-foreground uppercase">Penalidade/Freeze</p>
                            <p id="summaryPenalty" class="font-semibold text-foreground">--</p>
                        </div>
                    </div>
                    <div class="p-4 bg-muted/50 rounded-lg">
                        <p class="text-xs text-muted-foreground uppercase">Linguagens</p>
                        <p id="summaryLanguages" class="font-semibold text-foreground text-sm">--</p>
                    </div>
                    <div class="p-4 bg-muted/50 rounded-lg">
                        <p class="text-xs text-muted-foreground uppercase">Problemas</p>
                        <p id="summaryProblems" class="font-semibold text-foreground text-sm">--</p>
                    </div>
                    <div class="flex gap-4">
                        <label class="flex-1 flex items-center gap-3 p-3 bg-background border border-border rounded-lg cursor-pointer">
                            <input type="checkbox" name="is_active" value="1" @checked(session()->hasOldInput('is_active') ? old('is_active') : true) class="w-5 h-5 rounded">
                            <span class="text-sm">Ativar Imediatamente</span>
                        </label>
                        <label class="flex-1 flex items-center gap-3 p-3 bg-background border border-border rounded-lg cursor-pointer">
                            <input type="checkbox" name="is_public" value="1" @checked(session()->hasOldInput('is_public') ? old('is_public') : true) class="w-5 h-5 rounded">
                            <span class="text-sm">Maratona Publica</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <div class="flex flex-wrap items-center justify-between gap-3 mt-6">
            <button type="button" id="prevBtn" data-wizard-action="prevStep" class="hidden px-6 py-3 bg-muted text-muted-foreground rounded-lg hover:bg-muted/80 transition-colors">
                <span class="flex items-center gap-2">
                    <svg aria-hidden="true" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                    Anterior
                </span>
            </button>
            <div></div>
            <button type="button" id="nextBtn" data-wizard-action="nextStep" class="px-6 py-3 bg-primary text-primary-foreground rounded-lg hover:bg-primary-hover transition-colors">
                <span class="flex items-center gap-2">
                    Próximo
                    <svg aria-hidden="true" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                </span>
            </button>
            <button type="submit" id="submitBtn" class="hidden px-8 py-3 bg-success text-success-foreground rounded-lg hover:bg-success-hover transition-colors">
                <span class="flex items-center gap-2">
                    <svg aria-hidden="true" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                    Criar Maratona
                </span>
            </button>
        </div>
    </form>
</div>



@endsection
