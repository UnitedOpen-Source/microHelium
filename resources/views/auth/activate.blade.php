@extends('layouts.auth')

@section('title', 'Ativar conta')
@section('auth-title', 'Defina sua senha')
@section('auth-subtitle', 'Finalize a ativacao da sua conta gerenciada pela organizacao.')

@section('content')
@if($invalid)
    <div role="alert" class="mb-4 rounded-lg border border-destructive/50 bg-destructive-soft p-4 text-destructive">
        Este link de ativação é inválido, já foi usado ou expirou. Solicite um novo link pelo canal institucional.
    </div>
    <a href="/login" class="button-secondary">Ir para o login</a>
@else
    <form method="POST" action="{{ route('activation.store', $token) }}" class="space-y-5">
        @csrf

        <div class="space-y-2">
            <label for="password" class="block text-sm font-medium text-foreground">Nova senha</label>
            <input
                id="password"
                type="password"
                name="password"
                required
                minlength="8"
                autocomplete="new-password"
                class="flex h-10 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 @error('password') border-destructive focus-visible:ring-destructive @enderror"
            >
            @error('password')<p class="text-sm text-destructive">{{ $message }}</p>@enderror
        </div>

        <div class="space-y-2">
            <label for="password_confirmation" class="block text-sm font-medium text-foreground">Confirme a senha</label>
            <input
                id="password_confirmation"
                type="password"
                name="password_confirmation"
                required
                minlength="8"
                autocomplete="new-password"
                class="flex h-10 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
            >
        </div>

        <button
            type="submit"
            class="inline-flex w-full items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 transition-colors"
        >
            Ativar conta
        </button>
    </form>
@endif
@endsection
