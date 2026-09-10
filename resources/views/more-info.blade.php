@extends('layouts.app')
@section('title', 'Central de ajuda')
@section('description', 'Menos dúvidas. Mais espaço para resolver o próximo desafio.')
@section('page-actions')<a class="button-primary" href="/clarifications">Falar com a organização →</a>@endsection
@section('content')
<div class="grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-6 min-w-0">
        <section class="surface p-6 sm:p-8"><p class="eyebrow mb-3">COMO PARTICIPAR</p><h2 class="text-xl font-semibold mb-6">Sua primeira solução, passo a passo</h2>
            <ol class="space-y-6">
                @foreach([
                    ['Escolha um problema', 'Leia o enunciado, os formatos de entrada e saída e os limites do problema.'],
                    ['Desenvolva e teste', 'Use uma linguagem disponível na competição. Confira os exemplos e teste os casos extremos.'],
                    ['Envie seu código-fonte', 'Na página do problema, selecione Submeter solução e confira a linguagem antes de enviar.'],
                    ['Acompanhe o resultado', 'Consulte Submissões para ver o julgamento. Se precisar, revise o código e envie uma nova tentativa.'],
                ] as [$title, $description])
                <li class="flex gap-4"><span class="step-number shrink-0">0{{ $loop->iteration }}</span><div><h3 class="font-semibold mb-1">{{ $title }}</h3><p class="text-sm text-muted-foreground leading-relaxed">{{ $description }}</p></div></li>
                @endforeach
            </ol>
        </section>
        <section class="surface p-6 sm:p-8"><h2 class="text-xl font-semibold mb-5">Perguntas frequentes</h2><div class="divide-y divide-border">
            @foreach([
                ['Como a classificação é calculada?', 'No formato ICPC, o número de problemas resolvidos determina a classificação. Em caso de empate, a penalidade é usada como critério. Consulte a organização para confirmar as regras e os valores configurados para sua competição.'],
                ['O que significa placar congelado?', 'Durante o período de congelamento, o placar pode deixar de mostrar novos resultados. Isso não significa que a competição terminou. Acompanhe o relógio e os avisos da organização.'],
                ['Posso enviar uma solução novamente?', 'Você pode revisar seu código e fazer outro envio durante a competição, respeitando as regras do evento. Cada submissão tem seu próprio resultado.'],
                ['Quando devo enviar uma clarificação?', 'Use as clarificações para perguntar sobre o enunciado, os formatos de entrada e saída ou as regras. Não inclua seu código-fonte nem peça a solução do problema.'],
                ['Quais linguagens e limites posso usar?', 'As linguagens disponíveis são apresentadas no formulário de envio. Confira os limites na página de cada problema; eles podem variar entre problemas e competições.'],
                ['Estou com um problema técnico. O que fazer?', 'Procure a equipe de suporte da competição. Para dúvidas sobre problemas ou regras, entre na sua conta e envie uma clarificação.'],
            ] as [$question, $answer])
            <details class="py-4"><summary class="font-medium text-sm py-1">{{ $question }}</summary><p class="text-sm text-muted-foreground leading-relaxed mt-3 pl-4">{{ $answer }}</p></details>
            @endforeach
        </div></section>
    </div>
    <aside class="space-y-6">
        <section class="surface p-6"><p class="eyebrow mb-3">ENTENDA O RESULTADO</p><h2 class="text-lg font-semibold mb-5">Vereditos</h2><dl class="space-y-4">
            @foreach([
                ['AC', 'Aceito', 'Sua solução passou nos testes.', 'success'],
                ['WA', 'Resposta incorreta', 'A saída não corresponde ao esperado.', 'destructive'],
                ['TLE', 'Tempo excedido', 'A execução ultrapassou o limite.', 'warning'],
                ['MLE', 'Memória excedida', 'O uso de memória ultrapassou o limite.', 'warning'],
                ['RE', 'Erro de execução', 'O programa falhou durante a execução.', 'destructive'],
                ['CE', 'Erro de compilação', 'O código não pôde ser compilado.', 'info'],
            ] as [$code, $title, $description, $tone])
            <div class="flex gap-3"><dt class="shrink-0"><span @class(['inline-flex justify-center w-11 rounded-md py-1 text-xs font-mono font-semibold', 'bg-success/10 text-success' => $tone === 'success', 'bg-destructive/10 text-destructive' => $tone === 'destructive', 'bg-warning/10 text-warning' => $tone === 'warning', 'bg-info/10 text-info' => $tone === 'info'])>{{ $code }}</span></dt><dd><p class="text-sm font-semibold">{{ $title }}</p><p class="text-xs text-muted-foreground mt-1 leading-relaxed">{{ $description }}</p></dd></div>
            @endforeach
        </dl></section>
        <section class="surface p-6"><h2 class="font-semibold mb-3">Pronto para começar?</h2><p class="text-sm text-muted-foreground mb-5">Escolha um desafio e coloque suas ideias em prática.</p><a href="/exercises" class="button-secondary w-full">Explorar problemas →</a></section>
    </aside>
</div>
@endsection
