@extends('layouts.app')
@section('title', 'Primeiros passos')
@section('description', 'Da escolha do problema à sua primeira submissão.')
@section('content')
<div class="grid gap-5 md:grid-cols-3">
    @foreach([
        ['01', 'Escolha seu desafio', 'Explore os problemas, leia os enunciados e confira as linguagens disponíveis.', '/exercises', 'Explorar problemas'],
        ['02', 'Prepare sua solução', 'Desenvolva seu código e teste os exemplos de entrada e saída antes de enviar.', '/ajuda', 'Consultar o guia'],
        ['03', 'Acompanhe a competição', 'Confira os resultados dos envios e a classificação dos times no placar.', '/scoreboard', 'Ver classificação'],
    ] as [$number, $title, $description, $url, $action])
    <article class="surface p-6"><span class="step-number">{{ $number }}</span><h2 class="text-xl font-semibold my-4">{{ $title }}</h2><p class="text-muted-foreground mb-6">{{ $description }}</p><a class="text-primary font-semibold" href="{{ $url }}">{{ $action }} →</a></article>
    @endforeach
</div>
@endsection
