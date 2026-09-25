@extends('layouts.app')
@section('title', __('Central de ajuda'))
@section('description', __('Menos dúvidas. Mais espaço para resolver o próximo desafio.'))
@section('page-actions')<a class="button-primary" href="/clarifications">{{ __('Falar com a organização') }} <span aria-hidden="true">→</span></a>@endsection
@section('content')
<div class="grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-6 min-w-0">
        <section class="surface p-6 sm:p-8"><p class="eyebrow mb-3">{{ __('COMO PARTICIPAR') }}</p><h2 class="text-xl font-semibold mb-6">{{ __('Sua primeira solução, passo a passo') }}</h2>
            <ol class="space-y-6">
                @foreach([
                    [__('Escolha um problema'), __('Leia o enunciado, os formatos de entrada e saída e os limites do problema.')],
                    [__('Desenvolva e teste'), __('Use uma linguagem disponível na competição. Confira os exemplos e teste os casos extremos.')],
                    [__('Envie seu código-fonte'), __('Na página do problema, selecione Submeter solução e confira a linguagem antes de enviar.')],
                    [__('Acompanhe o resultado'), __('Consulte Submissões para ver o julgamento. Se precisar, revise o código e envie uma nova tentativa.')],
                ] as [$title, $description])
                <li class="flex gap-4"><span class="step-number shrink-0">0{{ $loop->iteration }}</span><div><h3 class="font-semibold mb-1">{{ $title }}</h3><p class="text-sm text-muted-foreground leading-relaxed">{{ $description }}</p></div></li>
                @endforeach
            </ol>
        </section>
        <section class="surface p-6 sm:p-8"><h2 class="text-xl font-semibold mb-5">{{ __('Perguntas frequentes') }}</h2><div class="divide-y divide-border">
            @foreach([
                [__('Como a classificação é calculada?'), __('No formato ICPC, o número de problemas resolvidos determina a classificação. Em caso de empate, a penalidade é usada como critério. Consulte a organização para confirmar as regras e os valores configurados para sua competição.')],
                [__('O que significa placar congelado?'), __('Durante o período de congelamento, o placar pode deixar de mostrar novos resultados. Isso não significa que a competição terminou. Acompanhe o relógio e os avisos da organização.')],
                [__('Posso enviar uma solução novamente?'), __('Você pode revisar seu código e fazer outro envio durante a competição, respeitando as regras do evento. Cada submissão tem seu próprio resultado.')],
                [__('Quando devo enviar uma clarificação?'), __('Use as clarificações para perguntar sobre o enunciado, os formatos de entrada e saída ou as regras. Não inclua seu código-fonte nem peça a solução do problema.')],
                [__('Quais linguagens e limites posso usar?'), __('As linguagens disponíveis são apresentadas no formulário de envio. Confira os limites na página de cada problema; eles podem variar entre problemas e competições.')],
                [__('Estou com um problema técnico. O que fazer?'), __('Procure a equipe de suporte da competição. Para dúvidas sobre problemas ou regras, entre na sua conta e envie uma clarificação.')],
            ] as [$question, $answer])
            <details class="py-4"><summary class="font-medium text-sm py-1">{{ $question }}</summary><p class="text-sm text-muted-foreground leading-relaxed mt-3 pl-4">{{ $answer }}</p></details>
            @endforeach
        </div></section>
    </div>
    <aside class="space-y-6">
        <section class="surface p-6"><p class="eyebrow mb-3">{{ __('ENTENDA O RESULTADO') }}</p><h2 class="text-lg font-semibold mb-5">{{ __('Vereditos') }}</h2><dl class="space-y-4">
            @foreach([
                ['AC', __('Aceito'), __('Sua solução passou nos testes.'), 'success'],
                ['WA', __('Resposta incorreta'), __('A saída não corresponde ao esperado.'), 'destructive'],
                ['TLE', __('Tempo excedido'), __('A execução ultrapassou o limite.'), 'warning'],
                ['MLE', __('Memória excedida'), __('O uso de memória ultrapassou o limite.'), 'warning'],
                ['RE', __('Erro de execução'), __('O programa falhou durante a execução.'), 'destructive'],
                ['CE', __('Erro de compilação'), __('O código não pôde ser compilado.'), 'info'],
            ] as [$code, $title, $description, $tone])
            <div class="flex gap-3"><dt class="shrink-0"><span @class(['inline-flex justify-center w-11 rounded-md py-1 text-xs font-mono font-semibold', 'bg-success-soft text-success' => $tone === 'success', 'bg-destructive-soft text-destructive' => $tone === 'destructive', 'bg-warning-soft text-warning' => $tone === 'warning', 'bg-info-soft text-info' => $tone === 'info'])>{{ $code }}</span></dt><dd><p class="text-sm font-semibold">{{ $title }}</p><p class="text-xs text-muted-foreground mt-1 leading-relaxed">{{ $description }}</p></dd></div>
            @endforeach
        </dl></section>
        <section class="surface p-6"><h2 class="font-semibold mb-3">{{ __('Pronto para começar?') }}</h2><p class="text-sm text-muted-foreground mb-5">{{ __('Escolha um desafio e coloque suas ideias em prática.') }}</p><a href="/exercises" class="button-secondary w-full">{{ __('Explorar problemas') }} <span aria-hidden="true">→</span></a></section>
    </aside>
</div>
@endsection
