@extends('layouts.app')

@section('title', 'Conjunto de rejulgamento #' . $rejudging->id)
@section('description', 'Confira o que mudaria antes de gravar.')

@section('page-actions')<a href="{{ route('judge.rejudgings', ['contest_id' => $rejudging->contest_id]) }}" class="button-secondary">← Voltar aos conjuntos</a>@endsection

@section('content')
<div class="feature-stack">
    <section class="surface feature-panel">
        <div class="feature-heading">
            <div><p class="eyebrow">CONJUNTO #{{ $rejudging->id }}</p><h2>{{ $rejudging->reason }}</h2></div>
            <span class="feature-badge {{ $rejudging->status === 'applied' ? 'feature-success' : ($rejudging->status === 'cancelled' ? 'feature-error' : '') }}">{{ $rejudging->status }}</span>
        </div>
        <dl class="feature-details">
            <div><dt>Criado por</dt><dd>{{ $rejudging->creator?->fullname ?? 'Desconhecido' }}</dd></div>
            <div><dt>Criado em</dt><dd>{{ $rejudging->created_at?->format('d/m/Y H:i T') }}</dd></div>
            <div><dt>Aceitos incluídos</dt><dd>{{ $rejudging->include_accepted ? 'Sim' : 'Não' }}</dd></div>
            @if($rejudging->applied_at)<div><dt>Aplicado em</dt><dd>{{ $rejudging->applied_at->format('d/m/Y H:i T') }}</dd></div>@endif
            @if($rejudging->cancelled_at)<div><dt>Cancelado em</dt><dd>{{ $rejudging->cancelled_at->format('d/m/Y H:i T') }}</dd></div>@endif
        </dl>

        {{-- O criterio como foi PEDIDO, e nao so a lista de envios que ele
             selecionou: a lista diz o que foi feito, o criterio diz por que,
             e "todos os envios do problema C em C++" e o que alguem vai
             querer ler seis meses depois. --}}
        @if($rejudging->filters)
            <h3>Critério pedido</h3>
            <dl class="feature-details">
                @foreach($rejudging->filters as $campo => $valor)
                    <div><dt>{{ $campo }}</dt><dd>{{ is_array($valor) ? implode(', ', $valor) : $valor }}</dd></div>
                @endforeach
            </dl>
        @else
            <p class="feature-help">Sem filtro: a competição inteira.</p>
        @endif
    </section>

    <section class="surface feature-panel" role="status">
        <div class="feature-heading"><h2>O que mudaria</h2></div>

        @if($rejudging->status === 'applied')
            <p class="feature-message feature-success">Já aplicado. Os números abaixo são o que este conjunto fez.</p>
        @elseif($rejudging->status === 'cancelled')
            <p class="feature-message">Cancelado. Nenhum veredito foi tocado.</p>
        @elseif($rejudging->status === 'preparing')
            <p class="feature-message">
                Julgando em sombra: {{ $previa['judged'] }} de {{ $previa['total'] }} prontos.
                As equipes continuam vendo o veredito de sempre e a classificação não se mexeu.
                Atualize esta página para acompanhar.
            </p>
        @else
            <p class="feature-message">Pronto para decidir. Nada foi gravado ainda.</p>
        @endif

        <dl class="feature-details">
            <div><dt>Envios no conjunto</dt><dd>{{ $previa['total'] }}</dd></div>
            <div><dt>Julgados</dt><dd>{{ $previa['judged'] }}</dd></div>
            <div><dt>Mudariam de veredito</dt><dd><strong>{{ $previa['changes'] }}</strong></dd></div>
            <div><dt>Falharam</dt><dd>{{ $previa['errors'] }}</dd></div>
            <div><dt>Julgados com outra versão do toolchain</dt><dd>{{ $previa['toolchain_changes'] }}</dd></div>
        </dl>

        @if($previa['toolchain_changes'] > 0)
            {{-- Issue #392 -- um veredito que mudou junto com o compilador
                 pede outra leitura que um que mudou porque o caso de teste
                 foi consertado. --}}
            <p class="feature-message feature-error">
                {{ $previa['toolchain_changes'] }} {{ Str::plural('envio', $previa['toolchain_changes']) }} {{ $previa['toolchain_changes'] === 1 ? 'foi rejulgado' : 'foram rejulgados' }} com outra versão do toolchain da linguagem. Confira a coluna “Toolchain” antes de aplicar.
            </p>
        @endif

        @if($previa['errors'] > 0)
            {{-- Um membro que falhou fica como estava: trocar um veredito
                 real por uma falha de infraestrutura seria a equipe pagando
                 por um defeito nosso. --}}
            <p class="feature-message feature-error">
                {{ $previa['errors'] }} {{ Str::plural('envio', $previa['errors']) }} {{ $previa['errors'] === 1 ? 'falhou' : 'falharam' }} ao ser {{ $previa['errors'] === 1 ? 'julgado' : 'julgados' }}.
                {{ $previa['errors'] === 1 ? 'Ele fica' : 'Eles ficam' }} com o veredito que já {{ $previa['errors'] === 1 ? 'tinha' : 'tinham' }} — uma falha nossa não pode virar veredito da equipe.
            </p>
        @endif
    </section>

    @if($rejudging->isOpen())
        <section class="surface feature-panel">
            <div class="feature-heading"><h2>Decidir</h2></div>
            <p class="feature-help">
                Aplicar grava os vereditos novos de uma vez só e recompõe o placar. Cancelar descarta o
                conjunto sem tocar em nada.
            </p>
            @if($rejudging->contest?->isFrozen())
                {{-- Decidido na spec, e antes e nao depois: aplicar durante o
                     congelamento e permitido. Um rejulgamento so mexe em
                     celulas anteriores ao corte, e aqueles vereditos ja eram
                     publicos -- corrigi-los e o motivo de o rejulgamento
                     existir. O que o congelamento esconde continua escondido. --}}
                <p class="feature-message">
                    O placar está congelado. Aplicar é permitido: só células anteriores ao corte mudam, e
                    aqueles vereditos já eram públicos. O que o congelamento esconde continua escondido.
                </p>
            @endif
            <div class="feature-actions">
                <form method="POST" action="{{ route('judge.rejudgings.apply', $rejudging) }}"
                      data-confirm="Gravar {{ $previa['changes'] }} veredito(s) novo(s) e recompor o placar? A classificação muda para quem não pediu nada.">
                    @csrf
                    <button class="button-primary" type="submit" @disabled(! $rejudging->isDecidable())>Aplicar</button>
                </form>
                <form method="POST" action="{{ route('judge.rejudgings.cancel', $rejudging) }}"
                      data-confirm="Descartar este conjunto? Nenhum veredito é tocado.">
                    @csrf
                    <button class="button-secondary" type="submit">Cancelar conjunto</button>
                </form>
            </div>
            @unless($rejudging->isDecidable())
                <p class="feature-help">Aplicar fica disponível quando todos os membros terminarem de ser julgados.</p>
            @endunless
        </section>
    @endif

    <section class="surface feature-panel">
        <div class="feature-heading"><h2>Envio a envio</h2></div>

        @if($previa['items'] === [])
            <p class="feature-empty">Nenhum envio neste conjunto — o critério não pegou nada.</p>
        @else
            <div class="overflow-x-auto">
                <table>
                    <thead><tr><th>Envio</th><th>Antes</th><th>Depois</th><th>Muda?</th><th>Toolchain</th><th>Falha</th></tr></thead>
                    <tbody>
                        @foreach($previa['items'] as $item)
                            <tr>
                                <td>#{{ $item['run_number'] ?? $item['run_id'] }}</td>
                                <td>{{ $item['from'] ?? '—' }}</td>
                                <td>{{ $item['to'] ?? ($item['error'] ? '—' : 'aguardando') }}</td>
                                <td>{{ $item['changes'] ? 'Sim' : 'Não' }}</td>
                                <td>
                                    @if($item['toolchain_changed'])
                                        <strong>{{ $item['toolchain_from'] }} → {{ $item['toolchain_to'] }}</strong>
                                    @else
                                        {{ $item['toolchain_to'] ?? $item['toolchain_from'] ?? '—' }}
                                    @endif
                                </td>
                                <td>{{ $item['error'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
@endsection
