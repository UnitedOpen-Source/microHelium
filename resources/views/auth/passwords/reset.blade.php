@extends('layouts.auth')

@section('title', 'Redefinir Senha')
@section('auth-title', 'Nova Senha')
@section('auth-subtitle', 'Defina uma nova senha para sua conta')

@section('content')
<form method="POST" action="{{ route('password.update') }}" class="space-y-5">
    @csrf

    <input type="hidden" name="token" value="{{ $token }}">

    <div class="space-y-2">
        <label for="email" class="block text-sm font-medium text-foreground">
            E-mail
        </label>
        <input
            id="email"
            type="email"
            name="email"
            value="{{ $email ?? old('email') }}"
            required
            autofocus
            placeholder="seu@email.com"
            class="flex h-10 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 @error('email') border-destructive focus-visible:ring-destructive @enderror"
        >
        @error('email')
            <p class="text-sm text-destructive">{{ $message }}</p>
        @enderror
    </div>

    <div class="space-y-2">
        <label for="password" class="block text-sm font-medium text-foreground">
            Nova Senha
        </label>
        <input
            id="password"
            type="password"
            name="password"
            required
            placeholder="Mínimo 8 caracteres"
            class="flex h-10 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 @error('password') border-destructive focus-visible:ring-destructive @enderror"
        >
        @error('password')
            <p class="text-sm text-destructive">{{ $message }}</p>
        @enderror
    </div>

    <div class="space-y-2">
        <label for="password_confirmation" class="block text-sm font-medium text-foreground">
            Confirmar Nova Senha
        </label>
        <input
            id="password_confirmation"
            type="password"
            name="password_confirmation"
            required
            placeholder="Repita a nova senha"
            class="flex h-10 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
        >
    </div>

    <button
        type="submit"
        class="inline-flex w-full items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 transition-colors"
    >
        Redefinir Senha
    </button>
</form>
@endsection

@section('footer')
    <a href="{{ route('login') }}" class="font-semibold text-primary hover:text-primary/80 transition-colors">Voltar para o login</a>
@endsection
