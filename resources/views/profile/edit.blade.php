@extends('layouts.app')

@section('title', 'Perfil')

@section('content')
<div class="max-w-xl mx-auto space-y-6">
    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <h2 class="text-xl font-semibold text-foreground">Meu Perfil</h2>
            <p class="text-sm text-muted-foreground">{{ $user->username }} &mdash; {{ ucfirst($user->user_type) }}</p>
        </div>

        <form method="POST" action="{{ route('profile.update') }}" class="p-6 space-y-5">
            @csrf
            @method('PUT')

            <div class="space-y-2">
                <label for="fullname" class="block text-sm font-medium text-foreground">Nome completo</label>
                <input
                    id="fullname"
                    type="text"
                    name="fullname"
                    value="{{ old('fullname', $user->fullname) }}"
                    required
                    class="flex h-10 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 @error('fullname') border-destructive focus-visible:ring-destructive @enderror"
                >
                @error('fullname')
                    <p class="text-sm text-destructive">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-2">
                <label for="email" class="block text-sm font-medium text-foreground">E-mail</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    value="{{ old('email', $user->email) }}"
                    required
                    class="flex h-10 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 @error('email') border-destructive focus-visible:ring-destructive @enderror"
                >
                @error('email')
                    <p class="text-sm text-destructive">{{ $message }}</p>
                @enderror
            </div>

            <div class="my-1 h-px bg-border"></div>

            <p class="text-sm text-muted-foreground">Deixe os campos abaixo em branco para manter a senha atual.</p>

            <div class="space-y-2">
                <label for="password" class="block text-sm font-medium text-foreground">Nova senha</label>
                <input
                    id="password"
                    type="password"
                    name="password"
                    autocomplete="new-password"
                    class="flex h-10 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 @error('password') border-destructive focus-visible:ring-destructive @enderror"
                >
                @error('password')
                    <p class="text-sm text-destructive">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-2">
                <label for="password_confirmation" class="block text-sm font-medium text-foreground">Confirmar nova senha</label>
                <input
                    id="password_confirmation"
                    type="password"
                    name="password_confirmation"
                    autocomplete="new-password"
                    class="flex h-10 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                >
            </div>

            <div class="space-y-2">
                <label for="current_password" class="block text-sm font-medium text-foreground">Senha atual</label>
                <input
                    id="current_password"
                    type="password"
                    name="current_password"
                    autocomplete="current-password"
                    placeholder="Necessária apenas ao alterar a senha"
                    class="flex h-10 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 @error('current_password') border-destructive focus-visible:ring-destructive @enderror"
                >
                @error('current_password')
                    <p class="text-sm text-destructive">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 transition-colors"
            >
                Salvar alterações
            </button>
        </form>
    </div>
</div>
@endsection
