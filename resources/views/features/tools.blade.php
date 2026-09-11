@extends('layouts.app')
@section('title', 'Ferramentas da organização')
@section('description', 'Cuide da integridade, da operação e da privacidade da sua competição.')
@section('content')
<div class="feature-grid">
<article class="surface feature-panel feature-problem"><p class="eyebrow">GESTÃO</p><h2>Similaridade de código</h2><p class="feature-help">Compare soluções aceitas e reúna evidências para uma revisão humana.</p><a href="/backend/similarity" class="button-secondary">Abrir similaridade de código</a></article>
<article class="surface feature-panel feature-problem"><p class="eyebrow">OPERAÇÃO</p><h2>Transmissão e premiação</h2><p class="feature-help">Prepare o placar da cerimônia e controle quem pode acessá-lo.</p><a href="/backend/webcast" class="button-secondary">Abrir transmissão e premiação</a></article>
<article class="surface feature-panel feature-problem"><p class="eyebrow">GESTÃO</p><h2>Organização do banco</h2><p class="feature-help">Defina responsáveis pelos problemas e organize as etiquetas de busca.</p><a href="/backend/bank-governance" class="button-secondary">Abrir organização do banco</a></article>
<article class="surface feature-panel feature-problem"><p class="eyebrow">GESTÃO</p><h2>Contas gerenciadas</h2><p class="feature-help">Cadastre participantes e acompanhe as restrições de privacidade.</p><a href="/backend/managed-accounts" class="button-secondary">Abrir contas gerenciadas</a></article>
<article class="surface feature-panel feature-problem"><p class="eyebrow">OPERAÇÃO</p><h2>Saúde do julgamento</h2><p class="feature-help">Acompanhe atrasos, retentativas e falhas nos envios do seu escopo.</p><a href="/judge/health" class="button-secondary">Abrir saúde do julgamento</a></article>
</div>
@endsection
