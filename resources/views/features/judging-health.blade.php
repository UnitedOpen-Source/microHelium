@extends('layouts.app')
@section('title', 'Saúde do julgamento')
@section('description', 'Acompanhe atrasos, retentativas e falhas nos envios do seu escopo.')
@section('content')

<div data-feature-page="judging-health" data-mode="library" data-problem-id="{{ request()->route('problemId') }}">
    <p class="feature-message" role="status">Carregando informações…</p>
</div>
<noscript><div class="surface feature-empty"><h2>Ative o JavaScript para usar esta página</h2><p>Estas ferramentas precisam de JavaScript para consultar e enviar informações.</p><a href="/ajuda" class="button-secondary">Consultar ajuda</a></div></noscript>
@endsection
