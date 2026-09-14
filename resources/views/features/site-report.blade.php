@extends('layouts.app')
@section('title', 'Relatório da sede')
@section('description', 'Submissões, vereditos e tempos de julgamento da sua sede.')
@section('content')

{{-- Issue #144 -- BOCA's src/staff/report/. Shell only: the gate is on the
     route (routes/frontend.php) and repeated on the data route
     (routes/frontend_api_reports.php), and the site scope is decided by
     ContestReportController, never here. --}}
<div data-feature-page="site-report">
    <p class="feature-message" role="status">Carregando informações…</p>
</div>
<noscript><div class="surface feature-empty"><h2>Ative o JavaScript para usar esta página</h2><p>Os gráficos deste relatório são desenhados no navegador.</p><a href="/ajuda" class="button-secondary">Consultar ajuda</a></div></noscript>
@endsection
