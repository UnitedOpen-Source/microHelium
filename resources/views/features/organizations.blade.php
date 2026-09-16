@extends('layouts.app')
@section('title', 'Organizações')
@section('description', 'Crie, renomeie e arquive organizações, e gerencie quem pode editar os problemas de cada uma.')
@section('content')

<div data-feature-page="organizations">
    <p class="feature-message" role="status">Carregando informações…</p>
</div>
<noscript><div class="surface feature-empty"><h2>Ative o JavaScript para usar esta página</h2><p>Estas ferramentas precisam de JavaScript para consultar e enviar informações.</p><a href="/ajuda" class="button-secondary">Consultar ajuda</a></div></noscript>
@endsection
