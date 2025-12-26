@extends('layouts.setup')

@section('title', 'Criar Nova Maratona')

@php
    $availableLanguages = \App\Models\Language::getDefaultLanguages();
    // $problemBank is passed from the route
    $problemBank = $problemBank ?? collect([]);
@endphp

@section('content')
<div class="max-w-5xl mx-auto">
    <!-- Progress Steps -->
    <div class="mb-8">
        <div class="flex items-center justify-between text-xs sm:text-sm">
            @foreach([1 => 'Info', 2 => 'Agenda', 3 => 'Linguagens', 4 => 'Problemas', 5 => 'Confirmar'] as $num => $label)
            <div class="flex items-center">
                <div id="step{{ $num }}-indicator" class="flex items-center justify-center w-8 h-8 sm:w-10 sm:h-10 {{ $num == 1 ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground' }} rounded-full font-bold text-sm">{{ $num }}</div>
                <span class="ml-1 sm:ml-2 font-medium {{ $num == 1 ? 'text-foreground' : 'text-muted-foreground' }} hidden md:inline">{{ $label }}</span>
            </div>
            @if($num < 5)
            <div class="flex-1 h-1 mx-2 sm:mx-4 bg-border rounded">
                <div id="progress-{{ $num }}-{{ $num+1 }}" class="h-full bg-primary rounded transition-all duration-300" style="width: 0%"></div>
            </div>
            @endif
            @endforeach
        </div>
    </div>

    @if(session('error'))
    <div class="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
        <div class="flex gap-3">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-red-600 dark:text-red-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <p class="text-sm text-red-700 dark:text-red-400">{{ session('error') }}</p>
        </div>
    </div>
    @endif

    <form id="wizardForm" action="/backend/contest-wizard" method="POST">
        @csrf

        <!-- Step 1: Basic Info -->
        <div id="step1" class="wizard-step">
            <div class="bg-card rounded-lg border border-border shadow-sm">
                <div class="p-6 border-b border-border">
                    <h2 class="text-xl font-semibold text-foreground">Informacoes Basicas</h2>
                    <p class="text-sm text-muted-foreground mt-1">Defina o nome e descricao da sua maratona</p>
                </div>
                <div class="p-6 space-y-6">
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-2">Nome da Maratona *</label>
                        <input type="text" name="name" id="contestName" required
                            class="w-full px-4 py-3 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent text-lg"
                            placeholder="Ex: Maratona de Programacao 2025">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-2">Descricao</label>
                        <textarea name="description" id="contestDescription" rows="3"
                            class="w-full px-4 py-3 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent resize-none"
                            placeholder="Descreva os objetivos e regras da competicao"></textarea>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-foreground mb-2">Penalidade (minutos)</label>
                            <input type="number" name="penalty" id="contestPenalty" value="20" min="0" max="120"
                                class="w-full px-4 py-3 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-foreground mb-2">Tamanho Max. Arquivo (KB)</label>
                            <input type="number" name="max_file_size" id="contestMaxFile" value="100" min="1" max="10240"
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
                    <h2 class="text-xl font-semibold text-foreground">Agenda da Competicao</h2>
                    <p class="text-sm text-muted-foreground mt-1">Configure quando a maratona vai acontecer</p>
                </div>
                <div class="p-6 space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-foreground mb-2">Data e Hora de Inicio *</label>
                            <input type="datetime-local" name="start_time" id="contestStart" required
                                class="w-full px-4 py-3 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-foreground mb-2">Duracao (minutos) *</label>
                            <input type="number" name="duration" id="contestDuration" value="300" min="30" max="10080" required
                                class="w-full px-4 py-3 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-2">Duracoes Rapidas</label>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" onclick="setDuration(60)" class="px-3 py-1.5 bg-muted text-muted-foreground rounded hover:bg-muted/80">1h</button>
                            <button type="button" onclick="setDuration(120)" class="px-3 py-1.5 bg-muted text-muted-foreground rounded hover:bg-muted/80">2h</button>
                            <button type="button" onclick="setDuration(180)" class="px-3 py-1.5 bg-muted text-muted-foreground rounded hover:bg-muted/80">3h</button>
                            <button type="button" onclick="setDuration(300)" class="px-3 py-1.5 bg-primary text-primary-foreground rounded hover:bg-primary/90">5h</button>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-2">Congelamento do Placar (minutos antes do fim)</label>
                        <input type="number" name="freeze_time" id="contestFreeze" value="60" min="0"
                            class="w-full px-4 py-3 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent">
                    </div>
                    <div class="p-4 bg-muted/50 rounded-lg flex items-center gap-3">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <div>
                            <p class="text-sm font-medium text-foreground">Termino Calculado</p>
                            <p id="calculatedEnd" class="text-lg font-bold text-primary">--</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 3: Languages -->
        <div id="step3" class="wizard-step hidden">
            <div class="bg-card rounded-lg border border-border shadow-sm">
                <div class="p-6 border-b border-border flex items-center justify-between">
                    <div>
                        <h2 class="text-xl font-semibold text-foreground">Linguagens Permitidas</h2>
                        <p class="text-sm text-muted-foreground mt-1">Selecione quais linguagens os participantes poderao usar</p>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" onclick="selectAllLanguages()" class="px-3 py-1.5 bg-primary text-primary-foreground rounded text-sm">Todas</button>
                        <button type="button" onclick="deselectAllLanguages()" class="px-3 py-1.5 bg-muted text-muted-foreground rounded text-sm">Nenhuma</button>
                    </div>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                        @foreach($availableLanguages as $lang)
                        <div class="language-card flex items-center gap-3 p-3 bg-background border-2 rounded-lg cursor-pointer hover:border-primary/50 transition-all {{ $lang['is_active'] ? 'selected' : '' }}" data-lang="{{ $lang['extension'] }}">
                            <input type="checkbox" name="languages[]" value="{{ $lang['extension'] }}" class="hidden" {{ $lang['is_active'] ? 'checked' : '' }}>
                            <div class="w-8 h-8 bg-primary/10 rounded flex items-center justify-center flex-shrink-0">
                                <span class="text-xs font-bold text-primary">.{{ $lang['file_ext'] ?? $lang['extension'] }}</span>
                            </div>
                            <span class="font-medium text-foreground text-sm truncate flex-1">{{ $lang['name'] }}</span>
                            <svg class="check-icon h-5 w-5 text-primary flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
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
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-xl font-semibold text-foreground">Banco de Problemas</h2>
                            <p class="text-sm text-muted-foreground mt-1">Selecione os problemas para a maratona ({{ $problemBank->count() }} disponiveis)</p>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" onclick="selectAllProblems()" class="px-3 py-1.5 bg-primary text-primary-foreground rounded text-sm">Todos</button>
                            <button type="button" onclick="deselectAllProblems()" class="px-3 py-1.5 bg-muted text-muted-foreground rounded text-sm">Nenhum</button>
                        </div>
                    </div>
                    <!-- Filters -->
                    <div class="flex gap-2 mt-4">
                        <button type="button" onclick="filterProblems('all')" class="filter-btn px-3 py-1 bg-primary text-primary-foreground rounded text-xs" data-filter="all">Todos</button>
                        <button type="button" onclick="filterProblems('easy')" class="filter-btn px-3 py-1 bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400 rounded text-xs" data-filter="easy">Facil</button>
                        <button type="button" onclick="filterProblems('medium')" class="filter-btn px-3 py-1 bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400 rounded text-xs" data-filter="medium">Medio</button>
                        <button type="button" onclick="filterProblems('hard')" class="filter-btn px-3 py-1 bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400 rounded text-xs" data-filter="hard">Dificil</button>
                    </div>
                </div>
                <div class="p-6 max-h-96 overflow-y-auto">
                    <div class="space-y-2" id="problemsList">
                        @foreach($problemBank as $problem)
                        <div class="problem-card flex items-center gap-4 p-3 bg-background border-2 border-border rounded-lg cursor-pointer hover:border-primary/50 transition-all" data-difficulty="{{ $problem->difficulty }}" data-id="{{ $problem->id }}">
                            <input type="checkbox" name="problems[]" value="{{ $problem->id }}" class="hidden">
                            <div class="w-16 text-center">
                                <span class="text-xs font-mono font-bold text-primary bg-primary/10 px-2 py-1 rounded">{{ $problem->code }}</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-foreground truncate">{{ $problem->name }}</p>
                                <p class="text-xs text-muted-foreground truncate">{{ Str::limit($problem->description, 80) }}</p>
                            </div>
                            <span class="px-2 py-0.5 text-xs rounded {{ $problem->difficulty_badge }}">{{ $problem->difficulty_label }}</span>
                            <svg class="check-icon h-5 w-5 text-primary hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
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
                    <h2 class="text-xl font-semibold text-foreground">Confirmar Criacao</h2>
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
                            <input type="checkbox" name="is_active" value="1" checked class="w-5 h-5 rounded">
                            <span class="text-sm">Ativar Imediatamente</span>
                        </label>
                        <label class="flex-1 flex items-center gap-3 p-3 bg-background border border-border rounded-lg cursor-pointer">
                            <input type="checkbox" name="is_public" value="1" checked class="w-5 h-5 rounded">
                            <span class="text-sm">Maratona Publica</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <div class="flex items-center justify-between mt-6">
            <button type="button" id="prevBtn" onclick="prevStep()" class="hidden px-6 py-3 bg-muted text-muted-foreground rounded-lg hover:bg-muted/80 transition-colors">
                <span class="flex items-center gap-2">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                    Anterior
                </span>
            </button>
            <div></div>
            <button type="button" id="nextBtn" onclick="nextStep()" class="px-6 py-3 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors">
                <span class="flex items-center gap-2">
                    Proximo
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                </span>
            </button>
            <button type="submit" id="submitBtn" class="hidden px-8 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                <span class="flex items-center gap-2">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                    Criar Maratona
                </span>
            </button>
        </div>
    </form>
</div>

<style>
/* Language cards - use !important to override Tailwind's border-border class */
.language-card.selected {
    border-color: hsl(var(--primary)) !important;
    background-color: hsl(var(--primary) / 0.05) !important;
}
.language-card:not(.selected) {
    border-color: hsl(var(--border)) !important;
    background-color: hsl(var(--background)) !important;
}
.language-card.selected .check-icon {
    display: block !important;
}
.language-card:not(.selected) .check-icon {
    display: none !important;
}

/* Problem cards */
.problem-card.selected {
    border-color: hsl(var(--primary)) !important;
    background-color: hsl(var(--primary) / 0.05) !important;
}
.problem-card:not(.selected) {
    border-color: hsl(var(--border)) !important;
}
.problem-card.selected .check-icon {
    display: block !important;
}
.problem-card:not(.selected) .check-icon {
    display: none !important;
}
</style>

<script>
let currentStep = 1;
const totalSteps = 5;

function showStep(step) {
    document.querySelectorAll('.wizard-step').forEach(el => el.classList.add('hidden'));
    document.getElementById('step' + step).classList.remove('hidden');

    for (let i = 1; i <= totalSteps; i++) {
        const indicator = document.getElementById('step' + i + '-indicator');
        const text = indicator.nextElementSibling;
        if (i < step) {
            indicator.className = 'flex items-center justify-center w-8 h-8 sm:w-10 sm:h-10 bg-green-600 text-white rounded-full font-bold text-sm';
            indicator.innerHTML = '<svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>';
            if (text) text.className = 'ml-1 sm:ml-2 font-medium text-green-600 hidden md:inline';
        } else if (i === step) {
            indicator.className = 'flex items-center justify-center w-8 h-8 sm:w-10 sm:h-10 bg-primary text-primary-foreground rounded-full font-bold text-sm';
            indicator.innerHTML = i;
            if (text) text.className = 'ml-1 sm:ml-2 font-medium text-foreground hidden md:inline';
        } else {
            indicator.className = 'flex items-center justify-center w-8 h-8 sm:w-10 sm:h-10 bg-muted text-muted-foreground rounded-full font-bold text-sm';
            indicator.innerHTML = i;
            if (text) text.className = 'ml-1 sm:ml-2 font-medium text-muted-foreground hidden md:inline';
        }
    }

    for (let i = 1; i < totalSteps; i++) {
        const bar = document.getElementById('progress-' + i + '-' + (i+1));
        if (bar) bar.style.width = (step > i) ? '100%' : '0%';
    }

    document.getElementById('prevBtn').classList.toggle('hidden', step === 1);
    document.getElementById('nextBtn').classList.toggle('hidden', step === totalSteps);
    document.getElementById('submitBtn').classList.toggle('hidden', step !== totalSteps);

    if (step === 5) updateSummary();
}

function nextStep() {
    if (!validateStep(currentStep)) return;
    if (currentStep < totalSteps) { currentStep++; showStep(currentStep); }
}

function prevStep() {
    if (currentStep > 1) { currentStep--; showStep(currentStep); }
}

function validateStep(step) {
    if (step === 1) {
        const name = document.getElementById('contestName').value.trim();
        if (!name) { alert('Informe o nome da maratona'); return false; }
    }
    if (step === 2) {
        const start = document.getElementById('contestStart').value;
        const duration = document.getElementById('contestDuration').value;
        if (!start) { alert('Informe a data de inicio'); return false; }
        if (!duration || duration < 30) { alert('Duracao minima: 30 minutos'); return false; }
    }
    if (step === 3) {
        if (document.querySelectorAll('.language-card input:checked').length === 0) {
            alert('Selecione pelo menos uma linguagem'); return false;
        }
    }
    return true;
}

function updateSummary() {
    document.getElementById('summaryName').textContent = document.getElementById('contestName').value || '--';
    const start = document.getElementById('contestStart').value;
    if (start) document.getElementById('summaryStart').textContent = new Date(start).toLocaleString('pt-BR');
    const duration = parseInt(document.getElementById('contestDuration').value) || 0;
    const h = Math.floor(duration / 60), m = duration % 60;
    document.getElementById('summaryDuration').textContent = h > 0 ? h + 'h ' + m + 'min' : m + 'min';
    document.getElementById('summaryPenalty').textContent = (document.getElementById('contestPenalty').value || 20) + 'min / ' + (document.getElementById('contestFreeze').value || 0) + 'min';
    const langs = Array.from(document.querySelectorAll('.language-card input:checked')).map(cb => cb.value.toUpperCase()).join(', ');
    document.getElementById('summaryLanguages').textContent = langs || 'Nenhuma';
    const probs = document.querySelectorAll('.problem-card input:checked').length;
    document.getElementById('summaryProblems').textContent = probs + ' problema(s) selecionado(s)';
}

function setDuration(mins) {
    document.getElementById('contestDuration').value = mins;
    updateEndTime();
}

function updateEndTime() {
    const start = document.getElementById('contestStart').value;
    const duration = parseInt(document.getElementById('contestDuration').value) || 0;
    if (start && duration) {
        const endDate = new Date(new Date(start).getTime() + duration * 60 * 1000);
        document.getElementById('calculatedEnd').textContent = endDate.toLocaleString('pt-BR');
    } else {
        document.getElementById('calculatedEnd').textContent = '--';
    }
}

function selectAllLanguages() {
    document.querySelectorAll('.language-card').forEach(card => {
        card.querySelector('input').checked = true;
        card.classList.add('selected');
    });
    updateLangCount();
}

function deselectAllLanguages() {
    document.querySelectorAll('.language-card').forEach(card => {
        card.querySelector('input').checked = false;
        card.classList.remove('selected');
    });
    updateLangCount();
}

function updateLangCount() {
    document.getElementById('selectedLangCount').textContent = document.querySelectorAll('.language-card input:checked').length;
}

function selectAllProblems() {
    document.querySelectorAll('.problem-card:not([style*="display: none"])').forEach(card => {
        card.querySelector('input').checked = true;
        card.classList.add('selected');
    });
    updateProblemCount();
}

function deselectAllProblems() {
    document.querySelectorAll('.problem-card').forEach(card => {
        card.querySelector('input').checked = false;
        card.classList.remove('selected');
    });
    updateProblemCount();
}

function updateProblemCount() {
    document.getElementById('selectedProblemCount').textContent = document.querySelectorAll('.problem-card input:checked').length;
}

function filterProblems(difficulty) {
    document.querySelectorAll('.problem-card').forEach(card => {
        if (difficulty === 'all' || card.dataset.difficulty === difficulty) {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const now = new Date();
    now.setHours(now.getHours() + 1);
    now.setMinutes(0);
    document.getElementById('contestStart').value = now.toISOString().slice(0, 16);
    updateEndTime();
    updateLangCount();

    document.getElementById('contestStart').addEventListener('change', updateEndTime);
    document.getElementById('contestDuration').addEventListener('input', updateEndTime);

    document.querySelectorAll('.language-card').forEach(card => {
        card.addEventListener('click', function(e) {
            e.preventDefault();
            const cb = this.querySelector('input');
            cb.checked = !cb.checked;
            this.classList.toggle('selected', cb.checked);
            updateLangCount();
        });
    });

    document.querySelectorAll('.problem-card').forEach(card => {
        card.addEventListener('click', function(e) {
            e.preventDefault();
            const cb = this.querySelector('input');
            cb.checked = !cb.checked;
            this.classList.toggle('selected', cb.checked);
            updateProblemCount();
        });
    });
});
</script>
@endsection
