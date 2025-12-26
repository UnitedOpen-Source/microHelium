@extends('layouts.auth')

@section('title', 'Recuperar Senha')
@section('auth-title', 'Esqueceu a senha?')
@section('auth-subtitle', 'Informe seu e-mail para receber o link de recuperação')

@section('content')
<form method="POST" action="{{ route('password.email') }}" class="space-y-5">
    @csrf

    <div class="space-y-2">
        <label for="email" class="block text-sm font-medium text-foreground">
            E-mail
        </label>
        <input
            id="email"
            type="email"
            name="email"
            value="{{ old('email') }}"
            required
            autofocus
            placeholder="seu@email.com"
            class="flex h-10 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 @error('email') border-destructive focus-visible:ring-destructive @enderror"
        >
        @error('email')
            <p class="text-sm text-destructive">{{ $message }}</p>
        @enderror
    </div>

    <button
        type="submit"
        class="inline-flex w-full items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 transition-colors"
    >
        Enviar Link de Recuperação
    </button>

    <div class="text-center">
        <a href="{{ route('login') }}" class="text-sm text-muted-foreground hover:text-foreground transition-colors">
            Voltar para o login
        </a>
    </div>
</form>
@endsection

@section('footer')
    Lembrou a senha? <a href="{{ route('login') }}" class="font-semibold text-primary hover:text-primary/80 transition-colors">Entrar</a>
@endsection
