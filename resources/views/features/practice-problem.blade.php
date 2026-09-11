@extends('layouts.app')
@section('title', 'Problema de treino')
@section('description', 'Leia o enunciado, teste suas ideias e envie uma solução.')
@section('content')

<div data-feature-page="practice" data-mode="problem" data-problem-id="{{ request()->route('problemId') }}">
    <p class="feature-message" role="status">Carregando informações…</p>
</div>
<noscript><div class="surface feature-empty"><h2>Ative o JavaScript para usar esta página</h2><p>Estas ferramentas precisam de JavaScript para consultar e enviar informações.</p><a href="/ajuda" class="button-secondary">Consultar ajuda</a></div></noscript>
@endsection
