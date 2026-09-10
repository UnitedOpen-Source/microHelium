@extends('layouts.auth')
@section('title', 'Recuperar senha')
@section('auth-title', 'Vamos recuperar seu acesso')
@section('auth-subtitle', 'Solicite ajuda à organização da competição.')
@section('content')
<div class="rounded-xl border border-info/25 bg-info-soft p-5 mb-6">
    <h2 class="font-semibold text-info mb-2">Recuperação por e-mail indisponível</h2>
    <p class="text-sm text-foreground">Entre em contato com a organização e informe o e-mail ou nome de usuário da sua conta para solicitar uma nova senha.</p>
</div>
<p class="text-sm text-muted-foreground mb-6">Você não precisa criar outra conta para continuar participando.</p>
<a href="{{ route('login') }}" class="button-primary w-full">Voltar para entrar</a>
@endsection
@section('footer')
    <a href="{{ route('ajuda') }}" class="font-semibold text-primary hover:underline">Consultar ajuda da competição</a>
@endsection
