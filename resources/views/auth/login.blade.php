@extends('layouts.auth')

@section('title', 'Entrar')
@section('auth-title', 'Bem-vindo de volta!')
@section('auth-subtitle', 'Entre com suas credenciais para acessar o sistema')

@section('content')
<form method="POST" action="{{ route('login') }}" class="space-y-5">
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
            autocomplete="email"
            autofocus
            placeholder="seu@email.com"
            class="flex h-10 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 @error('email') border-destructive focus-visible:ring-destructive @enderror"
        >
        @error('email')
            <p class="text-sm text-destructive">{{ $message }}</p>
        @enderror
    </div>

    <div class="space-y-2">
        <label for="password" class="block text-sm font-medium text-foreground">
            Senha
        </label>
        <input
            id="password"
            type="password"
            name="password"
            required
            autocomplete="current-password"
            placeholder="Sua senha"
            class="flex h-10 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 @error('password') border-destructive focus-visible:ring-destructive @enderror"
        >
        @error('password')
            <p class="text-sm text-destructive">{{ $message }}</p>
        @enderror
    </div>

    <div class="flex items-center gap-2">
        <input
            type="checkbox"
            name="remember"
            id="remember"
            {{ old('remember') ? 'checked' : '' }}
            class="h-4 w-4 rounded border-input text-primary focus:ring-primary focus:ring-offset-background"
        >
        <label for="remember" class="text-sm text-muted-foreground">
            Lembrar-me
        </label>
    </div>

    <button
        type="submit"
        class="inline-flex w-full items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 transition-colors"
    >
        Entrar
    </button>

    <div class="flex items-center justify-between text-sm">
        @if (Route::has('password.request'))
            <a href="{{ route('password.request') }}" class="text-muted-foreground hover:text-foreground transition-colors">
                Esqueceu a senha?
            </a>
        @endif
        <a href="/home" class="text-muted-foreground hover:text-foreground transition-colors">
            Voltar ao início
        </a>
    </div>
</form>
@endsection

@section('footer')
    Não tem uma conta? <a href="{{ route('register') }}" class="font-semibold text-primary hover:text-primary/80 transition-colors">Cadastre-se</a>
@endsection
