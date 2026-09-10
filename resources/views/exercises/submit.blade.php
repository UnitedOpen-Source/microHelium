@extends('layouts.app')

@section('title', 'Submeter - ' . $problem->name)

@section('description', 'Selecione a linguagem e envie seu código-fonte para avaliação.')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Main Form -->
    <div class="lg:col-span-2 min-w-0">
        <div class="bg-card rounded-lg border border-border shadow-sm">
            <div class="p-6 border-b border-border">
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-primary-soft rounded-lg">
                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-semibold text-foreground">Enviar solução</h2>
                        <p class="text-sm text-muted-foreground">Problema: <strong>{{ $problem->short_name }} - {{ $problem->name }}</strong></p>
                    </div>
                </div>
            </div>

            <form action="{{ route('exercise.submit', $problem) }}" method="POST" enctype="multipart/form-data" class="p-6 space-y-6">
                @csrf

                <!-- Language Selection -->
                <div>
                    <label for="language_id" class="block text-sm font-medium text-foreground mb-2">Linguagem de programação</label>
                    <select id="language_id" name="language_id" required class="w-full px-4 py-2.5 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent transition-colors">
                        <option value="">Selecione a linguagem...</option>
                        @foreach($languages as $lang)
                            <option value="{{ $lang->id }}" @selected(old('language_id') == $lang->id)>{{ $lang->name }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- File Upload -->
                <div>
                    <label for="source_file" class="block text-sm font-medium text-foreground mb-2">Arquivo de código-fonte</label>
                    <div class="relative rounded-lg focus-within:ring-2 focus-within:ring-primary">
                        <input type="file" name="source_file" id="source_file"
                               class="sr-only" onchange="updateFileName(this)">
                        <label for="source_file" class="flex flex-col items-center justify-center w-full min-h-40 p-4 border-2 border-dashed border-border rounded-lg cursor-pointer bg-muted/30 hover:bg-muted/50 transition-colors">
                            <div class="flex flex-col items-center justify-center pt-5 pb-6" id="upload-placeholder">
                                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-muted-foreground mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                </svg>
                                <p class="text-sm text-muted-foreground">
                                    <span class="font-medium text-primary">Selecione um arquivo</span> de código-fonte
                                </p>
                            </div>
                            <div class="hidden flex-col items-center justify-center pt-5 pb-6" id="upload-selected">
                                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-success mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <p id="selected-filename" role="status" class="text-sm font-medium text-foreground break-all"></p>
                                <p class="text-xs text-muted-foreground mt-1">Clique para trocar o arquivo</p>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Code Textarea -->
                <div>
                    <label for="code_text" class="block text-sm font-medium text-foreground mb-2">Ou cole seu código aqui:</label>
                    <textarea id="code_text" name="code_text" rows="12" spellcheck="false" autocapitalize="none" autocomplete="off" translate="no" aria-describedby="code-priority-hint" class="w-full px-4 py-3 bg-background border border-border rounded-lg text-foreground font-mono text-sm focus:ring-2 focus:ring-primary focus:border-transparent resize-y" placeholder="#include <stdio.h>

int main() {
    // Seu codigo aqui
    return 0;
}">{{ old('code_text') }}</textarea>
                    <p id="code-priority-hint" class="text-sm text-muted-foreground mt-2">Se você enviar um arquivo, o código colado aqui será ignorado.</p>
                </div>

                <!-- Actions -->
                <div class="flex gap-4 pt-4 border-t border-border">
                    <a href="{{ route('exercise.show', $problem) }}" class="flex-1 px-4 py-2.5 text-center text-foreground bg-muted hover:bg-muted/80 rounded-lg transition-colors">
                        Voltar ao Problema
                    </a>
                    <button type="submit" class="flex-1 px-4 py-2.5 bg-primary text-primary-foreground rounded-lg hover:bg-primary-hover transition-colors flex items-center justify-center gap-2">
                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                        </svg>
                        Enviar solução
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="space-y-6">
        <!-- Problem Info -->
        <div class="bg-card rounded-lg border border-border shadow-sm">
            <div class="p-4 border-b border-border">
                <h2 class="font-semibold text-foreground flex items-center gap-2">
                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Informações
                </h2>
            </div>
            <div class="p-4">
                <dl class="space-y-3">
                    <div class="flex justify-between">
                        <dt class="text-sm text-muted-foreground">Problema</dt>
                        <dd class="text-sm font-medium text-foreground">{{ $problem->short_name }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-sm text-muted-foreground">Tempo Limite</dt>
                        <dd class="text-sm font-medium text-foreground">{{ $problem->time_limit }}s</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-sm text-muted-foreground">Memória</dt>
                        <dd class="text-sm font-medium text-foreground">{{ $problem->memory_limit }}MB</dd>
                    </div>
                </dl>
            </div>
        </div>

        <!-- Languages Available -->
        <div class="bg-card rounded-lg border border-border shadow-sm">
            <div class="p-4 border-b border-border">
                <h2 class="font-semibold text-foreground flex items-center gap-2">
                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                    </svg>
                    Linguagens disponíveis
                </h2>
            </div>
            <div class="p-4">
                <div class="flex flex-wrap gap-2">
                    @foreach($languages as $lang)
                        <span class="px-2 py-1 text-xs font-medium bg-primary-soft text-primary rounded">
                            {{ $lang->name }}
                        </span>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- Tips -->
        <div class="bg-card rounded-lg border border-border shadow-sm">
            <div class="p-4 border-b border-border">
                <h2 class="font-semibold text-foreground flex items-center gap-2">
                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-warning" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                    </svg>
                    Dicas
                </h2>
            </div>
            <div class="p-4">
                <ul class="space-y-2 text-sm text-muted-foreground">
                    <li class="flex items-start gap-2">
                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-success mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        Teste seu codigo localmente antes de enviar
                    </li>
                    <li class="flex items-start gap-2">
                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-success mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        Leia da entrada padrao (stdin)
                    </li>
                    <li class="flex items-start gap-2">
                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-success mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        Escreva na saida padrao (stdout)
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

@endsection

@section('scripts')
<script>
function updateFileName(input) {
    const placeholder = document.getElementById('upload-placeholder');
    const selected = document.getElementById('upload-selected');
    const filename = document.getElementById('selected-filename');

    if (input.files.length > 0) {
        placeholder.classList.add('hidden');
        selected.classList.remove('hidden');
        selected.classList.add('flex');
        filename.textContent = input.files[0].name;
    } else {
        placeholder.classList.remove('hidden');
        selected.classList.add('hidden');
        selected.classList.remove('flex');
    }
}
</script>
@endsection
