@extends('layouts.app')

@section('title', 'Editar usuário')

@section('description', 'Corrija os dados de uma conta sem precisar excluí-la.')

@section('content')
<div class="space-y-6 max-w-2xl">
    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <h2 class="text-xl font-semibold text-foreground">Editar {{ $user->username }}</h2>
            <p class="text-sm text-muted-foreground mt-1">
                {{-- Issue #100: excluir arrasta runs, scores, tarefas e logs. --}}
                Excluir a conta apaga junto as submissões, pontuações, tarefas e registros dela. Editar não.
            </p>
        </div>

        @if($errors->any())
            <div class="mx-6 mt-6 p-4 rounded-lg bg-destructive-soft text-destructive text-sm" role="alert">
                <ul class="list-disc list-inside space-y-1">
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('backend.users.update', $user) }}" class="p-6 space-y-4">
            @csrf
            @method('PUT')

            <div>
                <label for="fullname" class="block text-sm font-medium text-foreground mb-1">Nome completo</label>
                <input id="fullname" type="text" name="fullname" required maxlength="255" value="{{ old('fullname', $user->fullname) }}" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground">
            </div>

            <div>
                <label for="username" class="block text-sm font-medium text-foreground mb-1">Nome de usuário</label>
                <input id="username" type="text" name="username" required maxlength="255" value="{{ old('username', $user->username) }}" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground">
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-foreground mb-1">E-mail</label>
                <input id="email" type="email" name="email" required maxlength="255" value="{{ old('email', $user->email) }}" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-foreground mb-1">Nova senha <span class="text-muted-foreground">(deixe em branco para manter)</span></label>
                <input id="password" type="password" name="password" minlength="8" autocomplete="new-password" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground">
            </div>

            <div>
                <label for="user_type" class="block text-sm font-medium text-foreground mb-1">Tipo</label>
                <select id="user_type" name="user_type" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground">
                    @foreach(['admin' => 'Administrador', 'judge' => 'Juiz', 'team' => 'Equipe', 'staff' => 'Apoio', 'score' => 'Placar', 'site' => 'Coordenação de sede'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('user_type', $user->user_type) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="site_id" class="block text-sm font-medium text-foreground mb-1">Sede</label>
                <select id="site_id" name="site_id" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground">
                    <option value="">Nenhuma</option>
                    @foreach($sitesByContest as $contestName => $sites)
                        <optgroup label="{{ $contestName ?? 'Sem competição' }}">
                            @foreach($sites as $site)
                                <option value="{{ $site->id }}" @selected((int) old('site_id', $user->site_id) === $site->id)>{{ $site->name }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <p class="text-xs text-muted-foreground mt-1">A competição da conta acompanha a sede escolhida.</p>
            </div>

            <div>
                <label for="icpc_id" class="block text-sm font-medium text-foreground mb-1">ID ICPC <span class="text-muted-foreground">(opcional, equipes)</span></label>
                <input id="icpc_id" type="text" name="icpc_id" maxlength="50" value="{{ old('icpc_id', $user->icpc_id) }}" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground">
                <p class="text-xs text-muted-foreground mt-1">Usado no relatório de classificação da ICPC (<code>contest:icpc-report</code>).</p>
            </div>

            <div class="flex items-center gap-2">
                <input type="hidden" name="is_enabled" value="0">
                <input id="is_enabled" type="checkbox" name="is_enabled" value="1" @checked(old('is_enabled', $user->is_enabled)) class="rounded border-border">
                <label for="is_enabled" class="text-sm font-medium text-foreground">Conta habilitada</label>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit" class="px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary-hover transition-colors">Salvar</button>
                <a href="{{ route('backend.users') }}" class="px-4 py-2 border border-border rounded-lg text-foreground hover:bg-accent transition-colors">Cancelar</a>
            </div>
        </form>
    </div>
</div>
@endsection
