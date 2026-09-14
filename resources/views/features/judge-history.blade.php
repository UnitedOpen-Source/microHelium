@extends('layouts.app')
@section('title', 'Histórico de julgamento')
@section('description', 'O que cada juiz julgou, para conferir um veredito contestado.')
@section('content')

{{-- Issue #144 -- BOCA's src/judge/history.php. Shell only; see
     routes/frontend_api_reports.php for the gate that matters. --}}
<div data-feature-page="judge-history">
    <p class="feature-message" role="status">Carregando informações…</p>
</div>
<noscript><div class="surface feature-empty"><h2>Ative o JavaScript para usar esta página</h2><p>Esta página consulta o histórico no servidor.</p><a href="/ajuda" class="button-secondary">Consultar ajuda</a></div></noscript>
@endsection
