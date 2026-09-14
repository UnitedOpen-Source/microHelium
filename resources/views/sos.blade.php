@extends('layouts.app')

@section('title', 'S.O.S.')

@section('description', 'Chame a organização da sua sede.')

@section('content')
<div class="space-y-6 max-w-3xl">
    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <h2 class="text-xl font-semibold text-foreground">S.O.S.</h2>
            <p class="text-sm text-muted-foreground mt-1">
                Para quando o problema é com a <strong>equipe</strong>, não com a questão: máquina travada,
                teclado quebrado, falta de energia na baia, alguém passando mal. O chamado vai para a
                organização da sua sede, que vem até a sua mesa.
            </p>
            <p class="text-sm text-muted-foreground mt-2">
                Dúvida sobre o enunciado de um problema não é S.O.S. — use as
                <a href="{{ route('clarifications') }}" class="text-primary hover:underline">Clarificações</a>,
                que vão para a banca.
            </p>
        </div>

        @if(session('success'))
            <div class="mx-6 mt-6 p-4 rounded-lg bg-success-soft text-success text-sm" role="status">{{ session('success') }}</div>
        @endif

        @if(session('info'))
            <div class="mx-6 mt-6 p-4 rounded-lg bg-warning-soft text-warning text-sm" role="status">{{ session('info') }}</div>
        @endif

        @error('confirmation')
            <div class="mx-6 mt-6 p-4 rounded-lg bg-destructive-soft text-destructive text-sm" role="alert">{{ $message }}</div>
        @enderror

        @if(!$contest)
            <div class="p-6">
                <p class="text-sm text-muted-foreground">Sua conta não está associada a nenhuma competição.</p>
            </div>
        @elseif($openCall)
            {{-- The dedupe rule, said out loud. A team that can see its own
                 open call has no reason to press the button again, which is
                 most of why the queue stays short. --}}
            <div class="p-6">
                <p class="text-sm text-foreground font-medium">Você já tem um chamado aberto.</p>
                <p class="text-sm text-muted-foreground mt-1">
                    A organização da sua sede já foi avisada
                    @if($openCall->isAcknowledged())
                        e alguém está a caminho.
                    @else
                        e o chamado está na fila.
                    @endif
                    Aberto às {{ $openCall->created_at?->format('H:i') }}.
                </p>
            </div>
        @else
        <form method="POST" action="{{ route('sos.store') }}" class="p-6 space-y-4">
            @csrf

            <div>
                <label for="note" class="block text-sm font-medium text-foreground mb-1">
                    O que está acontecendo? <span class="text-muted-foreground">(opcional)</span>
                </label>
                <input id="note" type="text" name="note" value="{{ old('note') }}" maxlength="{{ $noteMax }}"
                       placeholder="ex.: máquina não liga, baia 14"
                       class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground">
                <p class="text-xs text-muted-foreground mt-1">Até {{ $noteMax }} caracteres. Se não escrever nada, alguém vem descobrir na sua mesa.</p>
                @error('note')<p class="text-sm text-destructive mt-1">{{ $message }}</p>@enderror
            </div>

            {{-- BOCA asks for a confirmation before sending an S.O.S., and it
                 is right to: a stray click puts a staff member on their feet
                 and walking. Done as a disclosure plus a checkbox rather than
                 a window.confirm() because the CSP (#90/#143) has no
                 'unsafe-inline' for scripts -- and this works without
                 JavaScript at all, which on a competition machine that just
                 misbehaved is a feature. --}}
            <details class="rounded-lg border border-destructive/50 bg-destructive-soft p-4">
                <summary class="cursor-pointer text-sm font-semibold text-destructive">
                    Chamar a organização (S.O.S.)
                </summary>

                <div class="mt-4 space-y-4">
                    <p class="text-sm text-muted-foreground">
                        Isso vai tirar alguém da equipe de apoio do lugar para vir até você. Use quando precisar
                        mesmo — e confirme abaixo.
                    </p>

                    <label class="flex items-start gap-2 text-sm text-foreground">
                        <input type="checkbox" name="confirmation" value="confirm" required
                               class="mt-0.5 rounded border-border">
                        <span>Confirmo que preciso da organização na minha mesa agora.</span>
                    </label>

                    <button type="submit"
                            class="px-4 py-2 bg-destructive text-destructive-foreground rounded-lg hover:opacity-90 transition-opacity font-semibold">
                        Enviar S.O.S.
                    </button>
                </div>
            </details>
        </form>
        @endif
    </div>

    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <h2 class="text-lg font-semibold text-foreground">Seus chamados</h2>
        </div>
        <div class="divide-y divide-border">
            @forelse($calls as $call)
                <div class="px-6 py-3 flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        {{-- The team's own text, escaped: it is untrusted
                             display data even when the team is reading it
                             back to itself. --}}
                        <p class="text-sm text-foreground break-words">{{ $call->note ?: 'Sem observação' }}</p>
                        <p class="text-xs text-muted-foreground">{{ $call->created_at?->format('d/m H:i') }}</p>
                    </div>
                    @if($call->isResolved())
                        <span class="shrink-0 px-2 py-1 text-xs font-medium rounded bg-success-soft text-success">Resolvido</span>
                    @elseif($call->isAcknowledged())
                        <span class="shrink-0 px-2 py-1 text-xs font-medium rounded bg-warning-soft text-warning">A caminho</span>
                    @else
                        <span class="shrink-0 px-2 py-1 text-xs font-medium rounded bg-destructive-soft text-destructive">Aberto</span>
                    @endif
                </div>
            @empty
                <div class="px-6 py-8 text-center text-sm text-muted-foreground">Você ainda não chamou a organização.</div>
            @endforelse
        </div>
    </div>
</div>
@endsection
