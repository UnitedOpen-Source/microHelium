@extends('layouts.app')
@section('title', 'Contas gerenciadas')
@section('description', 'Cadastre participantes e acompanhe as restrições de privacidade.')
@section('content')
<a href="/backend/tools" class="button-secondary mb-5">Voltar às ferramentas</a>
<div data-feature-page="managed-accounts" data-mode="library" data-problem-id="{{ request()->route('problemId') }}">
    <p class="feature-message" role="status">Carregando informações…</p>
</div>
<noscript><div class="surface feature-empty"><h2>Ative o JavaScript para usar esta página</h2><p>Estas ferramentas precisam de JavaScript para consultar e enviar informações.</p><a href="/ajuda" class="button-secondary">Consultar ajuda</a></div></noscript>
@endsection
