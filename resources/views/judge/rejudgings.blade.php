@extends('layouts.app')

@section('title', 'Rejulgamento em lote')
@section('description', 'Selecione os envios, confira quantos são atingidos e só então monte o conjunto.')

@section('page-actions')<a href="{{ route('judge.runs') }}" class="button-secondary">← Voltar ao julgamento</a>@endsection

@section('content')
<div class="feature-stack">
    <section class="surface feature-panel">
        <div class="feature-heading"><h2>Rejulgamento em lote</h2></div>
        <p class="feature-help">
            O caso típico é um caso de teste errado descoberto com a prova andando: conserta-se o
            pacote e os envios atingidos voltam para a fila.
        </p>
        {{-- A previa existe justamente para conferir quantos envios serao
             atingidos ANTES de mexer no placar, e montar o conjunto ja
             dispara julgamento de verdade. Por isso sao dois botoes. --}}
        <p class="feature-help">
            Montar o conjunto <strong>dispara julgamento de verdade</strong>. Confira a contagem
            primeiro: descobrir que o critério pegou 4000 envios em vez de 40 depois da fila cheia é tarde.
        </p>
        <p class="feature-help">
            Enquanto o conjunto é julgado em sombra, as equipes continuam vendo o veredito de sempre e a
            classificação não se mexe. Aplicar é uma segunda decisão; cancelar não deixa rastro.
        </p>
    </section>

    @if($contests->isEmpty())
        <p class="feature-message">Nenhuma competição cadastrada.</p>
    @else
    <section class="surface feature-panel">
        <form method="GET" action="{{ route('judge.rejudgings') }}" class="feature-search">
            <label for="rej-contest">Competição
                <select id="rej-contest" name="contest_id">
                    @foreach($contests as $c)
                        <option value="{{ $c->id }}" @selected($contest && $contest->id === $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </label>
            <button class="button-secondary" type="submit">Exibir</button>
        </form>
    </section>

    @if($previa && $previa['contest_id'] === $contest->id)
        <section class="surface feature-panel" role="status">
            <div class="feature-heading"><h2>Conferência do critério</h2></div>
            <p class="feature-message {{ $previa['matches'] === 0 ? 'feature-error' : '' }}">
                <strong>{{ $previa['matches'] }}</strong> {{ Str::plural('envio', $previa['matches']) }} {{ $previa['matches'] === 1 ? 'seria atingido' : 'seriam atingidos' }}.
                Nada foi julgado nem gravado.
            </p>
            @if($previa['matches'] === 0)
                <p class="feature-help">Nenhum envio bate com este critério. Confira se o problema e a competição são os mesmos.</p>
            @endif
            @if($previa['seconds_from'] !== null || $previa['seconds_to'] !== null)
                {{-- A conta minuto -> segundo escrita por extenso: uma
                     conversao que acontece escondida e uma conversao que
                     ninguem confere. --}}
                <p class="feature-help">
                    Janela conferida:
                    {{ $previa['seconds_from'] !== null ? 'do minuto '.intdiv($previa['seconds_from'], 60).' ('.$previa['seconds_from'].' s de prova)' : 'do início' }}
                    {{ $previa['seconds_to'] !== null ? 'até o minuto '.intdiv($previa['seconds_to'], 60).' ('.$previa['seconds_to'].' s)' : 'até o fim' }}.
                </p>
            @endif
            @if($previa['accepted_excluded'] > 0)
                {{-- Tirar um AC de uma equipe no meio da prova e a coisa mais
                     cara que um rejulgamento faz, e quase nunca e o que se
                     queria ao pedir "rejulgue o problema C". --}}
                <p class="feature-message">
                    <strong>{{ $previa['accepted_excluded'] }}</strong> {{ Str::plural('envio aceito', $previa['accepted_excluded']) }} {{ $previa['accepted_excluded'] === 1 ? 'ficou' : 'ficaram' }} de fora.
                    Tirar um aceito de uma equipe é a mudança mais cara que um rejulgamento faz — marque a
                    opção abaixo só se for isso mesmo.
                </p>
            @endif
        </section>
    @endif

    <section class="surface feature-panel">
        <div class="feature-heading"><h2>Critério</h2></div>

        <form method="POST" action="{{ route('judge.rejudgings.preview', $contest) }}" class="feature-form" id="criterio">
            @csrf

            <div class="feature-field">
                <label for="rej-problem">Problema</label>
                <select id="rej-problem" name="problem_id" form="criterio">
                    <option value="">Qualquer</option>
                    @foreach($problems as $p)
                        <option value="{{ $p->id }}" @selected(old('problem_id') == $p->id)>{{ $p->short_name }} — {{ $p->name }}</option>
                    @endforeach
                </select>
                @error('problem_id')<p class="feature-message feature-error">{{ $message }}</p>@enderror
            </div>

            <div class="feature-field">
                <label for="rej-language">Linguagem</label>
                <select id="rej-language" name="language_id">
                    <option value="">Qualquer</option>
                    @foreach($languages as $l)
                        <option value="{{ $l->id }}" @selected(old('language_id') == $l->id)>{{ $l->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="feature-field">
                <label for="rej-answer">Veredito atual</label>
                <select id="rej-answer" name="answer_id">
                    <option value="">Qualquer</option>
                    @foreach($answers as $a)
                        <option value="{{ $a->id }}" @selected(old('answer_id') == $a->id)>{{ $a->short_name }} — {{ $a->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="feature-field">
                <label for="rej-site">Sede</label>
                <select id="rej-site" name="site_id">
                    <option value="">Qualquer</option>
                    @foreach($sites as $s)
                        <option value="{{ $s->id }}" @selected(old('site_id') == $s->id)>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="feature-field">
                <label for="rej-judgehost">Máquina de julgamento</label>
                <select id="rej-judgehost" name="judgehost_id">
                    <option value="">Qualquer</option>
                    @foreach($judgehosts as $j)
                        <option value="{{ $j->id }}" @selected(old('judgehost_id') == $j->id)>{{ $j->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="feature-field">
                <label for="rej-user">Equipe (código de usuário)</label>
                <input id="rej-user" name="user_id" type="number" min="1" value="{{ old('user_id') }}">
            </div>

            {{-- Minutos DE PROVA, que e como a organizacao fala do que
                 aconteceu: "os envios da primeira hora". A conversao para os
                 segundos de runs.contest_time acontece no controlador e
                 volta escrita na conferencia. --}}
            <div class="feature-field">
                <label for="rej-from">A partir do minuto de prova</label>
                <input id="rej-from" name="contest_minute_from" type="number" min="0" value="{{ old('contest_minute_from') }}">
                @error('contest_minute_from')<p class="feature-message feature-error">{{ $message }}</p>@enderror
            </div>

            <div class="feature-field">
                <label for="rej-to">Até o minuto de prova</label>
                <input id="rej-to" name="contest_minute_to" type="number" min="0" value="{{ old('contest_minute_to') }}">
                @error('contest_minute_to')<p class="feature-message feature-error">{{ $message }}</p>@enderror
            </div>

            <div class="feature-field feature-check feature-wide">
                <input id="rej-accepted" name="include_accepted" type="checkbox" value="1" @checked(old('include_accepted'))>
                <label for="rej-accepted">Incluir envios já aceitos</label>
            </div>
            <p class="feature-help feature-wide">
                Sem marcar, os aceitos ficam de fora. Tirar um AC de uma equipe no meio da prova é a
                mudança mais cara que um rejulgamento faz, e a decisão fica gravada no conjunto.
            </p>

            <button class="button-secondary feature-wide" type="submit">Conferir quantos envios</button>
        </form>
    </section>

    @if($previa && $previa['contest_id'] === $contest->id && $previa['matches'] > 0)
        <section class="surface feature-panel">
            <div class="feature-heading"><h2>Montar o conjunto</h2></div>
            <form method="POST" action="{{ route('judge.rejudgings.store', $contest) }}" class="feature-form">
                @csrf
                {{-- O criterio conferido viaja junto: montar com um criterio
                     diferente do que foi contado entregaria outro conjunto
                     que o numero acima. --}}
                @foreach(['problem_id', 'language_id', 'answer_id', 'site_id', 'judgehost_id', 'user_id', 'contest_minute_from', 'contest_minute_to'] as $campo)
                    <input type="hidden" name="{{ $campo }}" value="{{ old($campo) }}">
                @endforeach
                <input type="hidden" name="include_accepted" value="{{ old('include_accepted') ? 1 : 0 }}">

                <div class="feature-field feature-wide">
                    <label for="rej-reason">Motivo</label>
                    <input id="rej-reason" name="reason" type="text" maxlength="255" required value="{{ old('reason') }}" placeholder="ex.: caso de teste 7 do problema C estava errado">
                    <small>Fica gravado no conjunto e no log. É a primeira pergunta de quem contestar o resultado depois.</small>
                    @error('reason')<p class="feature-message feature-error">{{ $message }}</p>@enderror
                </div>

                <button class="button-primary feature-wide" type="submit">Montar conjunto com {{ $previa['matches'] }} {{ Str::plural('envio', $previa['matches']) }}</button>
            </form>
        </section>
    @endif

    <section class="surface feature-panel">
        <div class="feature-heading"><h2>Conjuntos desta competição</h2></div>

        @if($rejudgings->isEmpty())
            <p class="feature-empty">Nenhum rejulgamento em lote nesta competição.</p>
        @else
            @foreach($rejudgings as $r)
                <div class="feature-record">
                    <a class="feature-link" href="{{ route('judge.rejudgings.show', $r) }}">Conjunto #{{ $r->id }}</a>
                    <span class="feature-badge {{ $r->status === 'applied' ? 'feature-success' : ($r->status === 'cancelled' ? 'feature-error' : '') }}">{{ $r->status }}</span>
                    <p>{{ $r->reason }}</p>
                    <dl class="feature-details">
                        <div><dt>Criado por</dt><dd>{{ $r->creator?->fullname ?? 'Desconhecido' }}</dd></div>
                        <div><dt>Envios</dt><dd>{{ $r->members()->count() }}</dd></div>
                        <div><dt>Aceitos incluídos</dt><dd>{{ $r->include_accepted ? 'Sim' : 'Não' }}</dd></div>
                        <div><dt>Criado em</dt><dd>{{ $r->created_at?->format('d/m/Y H:i T') }}</dd></div>
                    </dl>
                </div>
            @endforeach
        @endif
    </section>
    @endif
</div>
@endsection
