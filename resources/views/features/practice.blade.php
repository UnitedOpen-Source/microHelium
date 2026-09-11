@extends('layouts.app')
@section('title', 'Treino Livre')
@section('description', 'Explore problemas e pratique programação no seu ritmo.')
@section('content')

<div data-feature-page="practice" data-mode="library" data-problem-id="{{ request()->route('problemId') }}">
    <p class="feature-message" role="status">Carregando informações…</p>
</div>
<noscript><div class="surface feature-empty"><h2>Ative o JavaScript para usar esta página</h2><p>Estas ferramentas precisam de JavaScript para consultar e enviar informações.</p><a href="/ajuda" class="button-secondary">Consultar ajuda</a></div></noscript>
@endsection
