@extends('layouts.auth')

@section('title', 'Cadastro')
@section('auth-title', 'Criar Conta')
@section('auth-subtitle', 'Preencha os dados para participar da maratona')

@section('content')
<form method="POST" action="{{ route('register') }}" class="space-y-5">
    @csrf

    <div class="space-y-2">
        <label for="fullname" class="block text-sm font-medium text-foreground">
            Nome Completo
        </label>
        <input
            id="fullname"
            type="text"
            name="fullname"
            value="{{ old('fullname') }}"
            required
            autofocus
            placeholder="Seu nome completo"
            class="flex h-10 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 @error('fullname') border-destructive focus-visible:ring-destructive @enderror"
        >
        @error('fullname')
            <p class="text-sm text-destructive">{{ $message }}</p>
        @enderror
    </div>

    <div class="space-y-2">
        <label for="username" class="block text-sm font-medium text-foreground">
            Usuário
        </label>
        <input
            id="username"
            type="text"
            name="username"
            value="{{ old('username') }}"
            required
            placeholder="Escolha um nome de usuário"
            class="flex h-10 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 @error('username') border-destructive focus-visible:ring-destructive @enderror"
        >
        @error('username')
            <p class="text-sm text-destructive">{{ $message }}</p>
        @enderror
    </div>

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
            placeholder="seu@email.com"
            class="flex h-10 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 @error('email') border-destructive focus-visible:ring-destructive @enderror"
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
            placeholder="Mínimo 8 caracteres"
            class="flex h-10 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 @error('password') border-destructive focus-visible:ring-destructive @enderror"
        >
        @error('password')
            <p class="text-sm text-destructive">{{ $message }}</p>
        @enderror
    </div>

    <div class="space-y-2">
        <label for="password_confirmation" class="block text-sm font-medium text-foreground">
            Confirmar Senha
        </label>
        <input
            id="password_confirmation"
            type="password"
            name="password_confirmation"
            required
            placeholder="Repita a senha"
            class="flex h-10 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
        >
    </div>

    <button
        type="submit"
        class="inline-flex w-full items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 transition-colors"
    >
        Cadastrar
    </button>

    <div class="text-center">
        <a href="/home" class="text-sm text-muted-foreground hover:text-foreground transition-colors">
            Voltar ao início
        </a>
    </div>
</form>
@endsection

@section('footer')
    Já tem uma conta? <a href="{{ route('login') }}" class="font-semibold text-primary hover:text-primary/80 transition-colors">Entrar</a>
@endsection
