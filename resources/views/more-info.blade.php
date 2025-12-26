@extends('layouts.app')

@section('title', 'Ajuda')

@php
    $availableLanguages = collect(\App\Models\Language::getDefaultLanguages())
        ->filter(fn($l) => $l['is_active'] ?? true);
@endphp

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Main Content -->
    <div class="lg:col-span-2 space-y-6">
        <div class="bg-card rounded-lg border border-border shadow-sm">
            <div class="p-6 border-b border-border">
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-primary/10 rounded-lg">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-semibold text-foreground">Ajuda</h2>
                        <p class="text-sm text-muted-foreground">Guia de uso do sistema MicroHelium</p>
                    </div>
                </div>
            </div>
            <div class="p-6 space-y-6">
                <div>
                    <h3 class="text-lg font-semibold text-foreground mb-3 flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        Bem-vindo ao MicroHelium!
                    </h3>
                    <p class="text-muted-foreground leading-relaxed">
                        Esta plataforma foi desenvolvida para gerenciar competicoes de programacao e hackathons no estilo ICPC.
                        Aqui voce pode submeter solucoes para os problemas propostos, acompanhar o placar em tempo real
                        e se comunicar com os juizes atraves do sistema de clarificacoes.
                    </p>
                </div>

                <hr class="border-border">

                <div>
                    <h3 class="text-lg font-semibold text-foreground mb-3 flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                        </svg>
                        Como Submeter uma Solucao
                    </h3>
                    <ol class="list-decimal list-inside space-y-2 text-muted-foreground">
                        <li>Acesse a pagina de <a href="/exercises" class="text-primary hover:underline">Problemas</a></li>
                        <li>Selecione o problema que deseja resolver</li>
                        <li>Leia o enunciado com atencao, verificando formato de entrada e saida</li>
                        <li>Desenvolva sua solucao localmente</li>
                        <li>Teste com os exemplos fornecidos</li>
                        <li>Clique em "Submeter Solucao" e faca o upload do seu codigo</li>
                        <li>Aguarde o resultado do julgamento automatico</li>
                    </ol>
                </div>

                <hr class="border-border">

                <div>
                    <h3 class="text-lg font-semibold text-foreground mb-3 flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                        </svg>
                        Sistema de Clarificacoes
                    </h3>
                    <p class="text-muted-foreground mb-3">Durante a competicao, utilize o sistema de <a href="/clarifications" class="text-primary hover:underline">Clarificacoes</a> para:</p>
                    <ul class="list-disc list-inside space-y-1 text-muted-foreground mb-4">
                        <li>Tirar duvidas sobre o enunciado dos problemas</li>
                        <li>Reportar problemas tecnicos</li>
                        <li>Questionar ambiguidades nos problemas</li>
                    </ul>
                    <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-900/30 rounded-lg p-4">
                        <p class="text-yellow-800 dark:text-yellow-300 text-sm">
                            <strong>Atencao:</strong> Nao inclua seu codigo fonte nas clarificacoes. Perguntas sobre logica de solucao
                            serao respondidas com "Sem comentarios".
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-card rounded-lg border border-border shadow-sm">
            <div class="p-6 border-b border-border">
                <h3 class="text-xl font-semibold text-foreground flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                    Regulamento
                </h3>
            </div>
            <div class="p-6 space-y-6">
                <div>
                    <h4 class="font-semibold text-foreground mb-2">1. Formato da Competicao</h4>
                    <p class="text-muted-foreground mb-2">A competicao segue o formato ICPC:</p>
                    <ul class="list-disc list-inside space-y-1 text-muted-foreground">
                        <li>O ranking e determinado pelo numero de problemas resolvidos</li>
                        <li>Em caso de empate, vence quem tiver menor tempo total</li>
                        <li>Tempo total = soma dos tempos de resolucao + penalidades</li>
                    </ul>
                </div>

                <div>
                    <h4 class="font-semibold text-foreground mb-2">2. Penalidades</h4>
                    <ul class="list-disc list-inside space-y-1 text-muted-foreground">
                        <li>Cada submissao incorreta adiciona <strong class="text-foreground">20 minutos</strong> de penalidade</li>
                        <li>Penalidades sao contabilizadas apenas se o problema for aceito posteriormente</li>
                    </ul>
                </div>

                <div>
                    <h4 class="font-semibold text-foreground mb-2">3. Conduta</h4>
                    <ul class="list-disc list-inside space-y-1 text-muted-foreground">
                        <li>E proibida comunicacao externa durante a competicao</li>
                        <li>Nao e permitido uso de codigo pre-escrito nao autorizado</li>
                        <li>Tentativas de acesso nao autorizado ao sistema resultam em desclassificacao</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="space-y-6">
        <div class="bg-card rounded-lg border border-border shadow-sm">
            <div class="p-4 border-b border-border">
                <h3 class="font-semibold text-foreground flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Vereditos
                </h3>
            </div>
            <div class="p-4">
                <div class="space-y-3">
                    <div class="flex items-start gap-3">
                        <span class="px-2 py-1 text-xs font-bold bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 rounded">AC</span>
                        <div>
                            <p class="font-medium text-foreground text-sm">Accepted</p>
                            <p class="text-xs text-muted-foreground">Solucao correta!</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="px-2 py-1 text-xs font-bold bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400 rounded">WA</span>
                        <div>
                            <p class="font-medium text-foreground text-sm">Wrong Answer</p>
                            <p class="text-xs text-muted-foreground">Resposta incorreta</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="px-2 py-1 text-xs font-bold bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400 rounded">TLE</span>
                        <div>
                            <p class="font-medium text-foreground text-sm">Time Limit</p>
                            <p class="text-xs text-muted-foreground">Tempo excedido</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="px-2 py-1 text-xs font-bold bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400 rounded">MLE</span>
                        <div>
                            <p class="font-medium text-foreground text-sm">Memory Limit</p>
                            <p class="text-xs text-muted-foreground">Memoria excedida</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="px-2 py-1 text-xs font-bold bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400 rounded">RTE</span>
                        <div>
                            <p class="font-medium text-foreground text-sm">Runtime Error</p>
                            <p class="text-xs text-muted-foreground">Erro de execucao</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <span class="px-2 py-1 text-xs font-bold bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400 rounded">CE</span>
                        <div>
                            <p class="font-medium text-foreground text-sm">Compile Error</p>
                            <p class="text-xs text-muted-foreground">Erro de compilacao</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-card rounded-lg border border-border shadow-sm">
            <div class="p-4 border-b border-border">
                <h3 class="font-semibold text-foreground flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                    </svg>
                    Linguagens
                </h3>
            </div>
            <div class="p-4">
                <ul class="space-y-2">
                    @foreach($availableLanguages as $lang)
                    <li class="flex items-center gap-2 text-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        <span class="font-medium text-foreground">{{ $lang['name'] }}</span>
                    </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="bg-card rounded-lg border border-border shadow-sm">
            <div class="p-4 border-b border-border">
                <h3 class="font-semibold text-foreground flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    Limites Padrao
                </h3>
            </div>
            <div class="p-4">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="flex items-center gap-2 text-sm text-muted-foreground">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            Tempo
                        </span>
                        <span class="font-semibold text-foreground">10 segundos</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="flex items-center gap-2 text-sm text-muted-foreground">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
                            </svg>
                            Memoria
                        </span>
                        <span class="font-semibold text-foreground">512 MB</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="flex items-center gap-2 text-sm text-muted-foreground">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            Codigo
                        </span>
                        <span class="font-semibold text-foreground">100 KB</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-card rounded-lg border border-border shadow-sm">
            <div class="p-4 border-b border-border">
                <h3 class="font-semibold text-foreground flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                    Suporte
                </h3>
            </div>
            <div class="p-4">
                <p class="text-sm text-muted-foreground mb-4">Em caso de problemas tecnicos, procure a equipe de suporte na sala de competicao.</p>
                <a href="/clarifications" class="flex items-center justify-center gap-2 w-full px-4 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                    </svg>
                    Enviar Clarificacao
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
