@extends('layouts.app')

@section('title', 'Importar Problemas BOCA')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center gap-4">
        <a href="{{ route('backend.problem-bank') }}" class="p-2 hover:bg-muted rounded-lg transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-foreground">Importar Problemas BOCA</h1>
            <p class="text-sm text-muted-foreground mt-1">Importe problemas no formato BOCA para o banco de problemas</p>
        </div>
    </div>

    <!-- Info Card -->
    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
        <div class="flex gap-3">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-blue-600 dark:text-blue-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <div>
                <h3 class="font-semibold text-blue-800 dark:text-blue-300">Sobre o formato BOCA</h3>
                <p class="text-sm text-blue-700 dark:text-blue-400 mt-1">
                    O BOCA (BOCA Online Contest Administrator) e um sistema de juiz automatico amplamente usado em competicoes de programacao no Brasil.
                    Os pacotes de problemas BOCA contem a descricao, casos de teste, limites e scripts de compilacao/execucao.
                </p>
            </div>
        </div>
    </div>

    <div class="grid gap-6 md:grid-cols-2">
        <!-- Upload ZIP -->
        <div class="bg-card border border-border rounded-lg">
            <div class="p-6 border-b border-border">
                <h2 class="text-lg font-semibold text-foreground flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                    </svg>
                    Upload de Arquivo ZIP
                </h2>
                <p class="text-sm text-muted-foreground mt-1">Envie um pacote de problemas BOCA em formato .zip</p>
            </div>
            <form action="/backend/import-boca/upload" method="POST" enctype="multipart/form-data" class="p-6">
                @csrf
                <div class="border-2 border-dashed border-border rounded-lg p-8 text-center hover:border-primary/50 transition-colors" id="dropZone">
                    <input type="file" name="boca_zip" id="fileInput" accept=".zip" class="hidden" onchange="updateFileName(this)">
                    <label for="fileInput" class="cursor-pointer">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-muted-foreground/50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                        </svg>
                        <p class="mt-2 text-sm text-muted-foreground">
                            <span class="text-primary font-medium">Clique para selecionar</span> ou arraste o arquivo
                        </p>
                        <p class="text-xs text-muted-foreground mt-1">Arquivos .zip (max 50MB)</p>
                    </label>
                    <p id="fileName" class="mt-4 text-sm font-medium text-primary hidden"></p>
                </div>
                <button type="submit" class="w-full mt-4 px-4 py-3 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors font-medium">
                    Importar Arquivo
                </button>
            </form>
        </div>

        <!-- Import from GitHub -->
        <div class="bg-card border border-border rounded-lg">
            <div class="p-6 border-b border-border">
                <h2 class="text-lg font-semibold text-foreground flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                    </svg>
                    Importar do BOCA GitHub
                </h2>
                <p class="text-sm text-muted-foreground mt-1">Importe os problemas de exemplo do repositorio oficial</p>
            </div>
            <div class="p-6">
                <div class="bg-muted/50 rounded-lg p-4 mb-4">
                    <h4 class="font-medium text-foreground mb-2">Problemas Disponiveis:</h4>
                    <ul class="text-sm text-muted-foreground space-y-1">
                        <li class="flex items-center gap-2">
                            <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                            <strong>Abacaxi</strong> - Problema de introducao
                        </li>
                        <li class="flex items-center gap-2">
                            <span class="w-2 h-2 bg-yellow-500 rounded-full"></span>
                            <strong>Bits</strong> - Manipulacao de bits
                        </li>
                        <li class="flex items-center gap-2">
                            <span class="w-2 h-2 bg-orange-500 rounded-full"></span>
                            <strong>Formiga</strong> - Problema de simulacao
                        </li>
                        <li class="flex items-center gap-2">
                            <span class="w-2 h-2 bg-red-500 rounded-full"></span>
                            <strong>Multas</strong> - Problema de calculo
                        </li>
                    </ul>
                </div>
                <form action="/backend/import-boca/github" method="POST">
                    @csrf
                    <button type="submit" class="w-full px-4 py-3 bg-gray-800 dark:bg-gray-700 text-white rounded-lg hover:bg-gray-700 dark:hover:bg-gray-600 transition-colors font-medium flex items-center justify-center gap-2">
                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.477 2 2 6.477 2 12c0 4.42 2.865 8.17 6.839 9.49.5.092.682-.217.682-.482 0-.237-.008-.866-.013-1.7-2.782.604-3.369-1.34-3.369-1.34-.454-1.156-1.11-1.464-1.11-1.464-.908-.62.069-.608.069-.608 1.003.07 1.531 1.03 1.531 1.03.892 1.529 2.341 1.087 2.91.831.092-.646.35-1.086.636-1.336-2.22-.253-4.555-1.11-4.555-4.943 0-1.091.39-1.984 1.029-2.683-.103-.253-.446-1.27.098-2.647 0 0 .84-.269 2.75 1.025A9.578 9.578 0 0112 6.836c.85.004 1.705.114 2.504.336 1.909-1.294 2.747-1.025 2.747-1.025.546 1.377.203 2.394.1 2.647.64.699 1.028 1.592 1.028 2.683 0 3.842-2.339 4.687-4.566 4.935.359.309.678.919.678 1.852 0 1.336-.012 2.415-.012 2.743 0 .267.18.578.688.48C19.138 20.167 22 16.418 22 12c0-5.523-4.477-10-10-10z"/>
                        </svg>
                        Importar do GitHub
                    </button>
                </form>
                <p class="text-xs text-muted-foreground text-center mt-3">
                    Fonte: <a href="https://github.com/cassiopc/boca" target="_blank" class="text-primary hover:underline">github.com/cassiopc/boca</a>
                </p>
            </div>
        </div>
    </div>

    <!-- Format Documentation -->
    <div class="bg-card border border-border rounded-lg">
        <div class="p-6 border-b border-border">
            <h2 class="text-lg font-semibold text-foreground">Estrutura do Pacote BOCA</h2>
        </div>
        <div class="p-6">
            <div class="bg-muted rounded-lg p-4 font-mono text-sm overflow-x-auto">
<pre class="text-muted-foreground">problema.zip
├── description/
│   └── problem.txt        # Descricao do problema
├── input/
│   ├── 01                 # Arquivo de entrada teste 1
│   ├── 02                 # Arquivo de entrada teste 2
│   └── ...
├── output/
│   ├── 01                 # Saida esperada teste 1
│   ├── 02                 # Saida esperada teste 2
│   └── ...
├── limits/
│   ├── c                  # Limites para C
│   ├── cpp                # Limites para C++
│   ├── java               # Limites para Java
│   └── ...
├── compare/
│   └── c                  # Script de comparacao
├── compile/
│   └── c                  # Script de compilacao
└── run/
    └── c                  # Script de execucao</pre>
            </div>
            <div class="mt-4 grid md:grid-cols-2 gap-4 text-sm">
                <div>
                    <h4 class="font-medium text-foreground mb-2">Pastas Obrigatorias</h4>
                    <ul class="text-muted-foreground space-y-1">
                        <li><code class="bg-muted px-1 rounded">input/</code> - Casos de teste de entrada</li>
                        <li><code class="bg-muted px-1 rounded">output/</code> - Saidas esperadas</li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-medium text-foreground mb-2">Pastas Opcionais</h4>
                    <ul class="text-muted-foreground space-y-1">
                        <li><code class="bg-muted px-1 rounded">description/</code> - Enunciado do problema</li>
                        <li><code class="bg-muted px-1 rounded">limits/</code> - Limites de tempo/memoria</li>
                        <li><code class="bg-muted px-1 rounded">compare/</code> - Comparador customizado</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function updateFileName(input) {
    const fileName = document.getElementById('fileName');
    if (input.files.length > 0) {
        fileName.textContent = input.files[0].name;
        fileName.classList.remove('hidden');
    } else {
        fileName.classList.add('hidden');
    }
}

// Drag and drop
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');

['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
    dropZone.addEventListener(eventName, preventDefaults, false);
});

function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
}

['dragenter', 'dragover'].forEach(eventName => {
    dropZone.addEventListener(eventName, highlight, false);
});

['dragleave', 'drop'].forEach(eventName => {
    dropZone.addEventListener(eventName, unhighlight, false);
});

function highlight() {
    dropZone.classList.add('border-primary', 'bg-primary/5');
}

function unhighlight() {
    dropZone.classList.remove('border-primary', 'bg-primary/5');
}

dropZone.addEventListener('drop', handleDrop, false);

function handleDrop(e) {
    const dt = e.dataTransfer;
    const files = dt.files;
    fileInput.files = files;
    updateFileName(fileInput);
}
</script>
@endsection
