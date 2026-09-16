@extends('layouts.app')

@section('title', 'Importar pacote de problema')
@section('description', 'Traga um problema no formato da ICPC/Kattis para uma competição.')

@section('page-actions')<a href="{{ route('backend.exercises') }}" class="button-secondary">← Voltar aos problemas da competição</a>@endsection

@section('content')
<div class="feature-stack">
    <section class="surface feature-panel">
        <div class="feature-heading"><h2>Importar pacote de problema</h2></div>
        <p class="feature-help">
            Formato de pacote da ICPC/Kattis — o que o Polygon exporta e o que o ICPC Problem Archive
            publica. Versões lidas: <code>legacy</code> e <code>legacy-icpc</code>.
        </p>
        {{-- A 2025-09 renomeia diretorios. Le-la com as regras anteriores nao
             daria erro: daria um problema sem enunciado e sem validador, em
             silencio. Por isso a leitura recusa, com o motivo dito. --}}
        <p class="feature-help">
            A versão <code>2025-09</code> é recusada de propósito: ela renomeia diretórios, e lê-la com
            as regras anteriores produziria um problema sem enunciado e sem validador, sem avisar.
        </p>
        <p class="feature-help">
            Para trazer uma competição inteira do BOCA para o banco de problemas — outro formato, outro
            destino — use <a class="feature-link" href="{{ route('backend.import-boca') }}">a importação do BOCA</a>.
        </p>
    </section>

    @if(session('error'))
        <p class="feature-message feature-error" role="alert">{{ session('error') }}</p>
    @endif

    @if($diagnostico)
        <section class="surface feature-panel" role="status">
            <div class="feature-heading"><h2>{{ $diagnostico['importado'] ? 'Pacote importado' : 'Conferência do pacote' }}</h2></div>

            @if($diagnostico['importado'])
                <p class="feature-message feature-success">Importado como <strong>{{ $diagnostico['problema'] }}</strong>.</p>
            @else
                <p class="feature-help">Nada foi gravado. Confira os números abaixo antes de importar de verdade.</p>
            @endif

            <dl class="feature-details">
                <div><dt>Nome no pacote</dt><dd>{{ $diagnostico['nome'] }}</dd></div>
                <div><dt>Versão do formato</dt><dd><code>{{ $diagnostico['versao'] }}</code></dd></div>
                <div><dt>Licença</dt><dd>{{ $diagnostico['licenca'] }}</dd></div>
                <div><dt>Limite de tempo</dt><dd>{{ $diagnostico['time_limit'] }} s</dd></div>
                <div><dt>Limite de memória</dt><dd>{{ $diagnostico['memory_limit'] }} MiB</dd></div>
                <div><dt>Casos de amostra</dt><dd>{{ $diagnostico['amostras'] }}</dd></div>
                <div><dt>Casos secretos</dt><dd>{{ $diagnostico['secretos'] }}</dd></div>
                <div><dt>Enunciado</dt><dd>{{ $diagnostico['enunciado'] ? 'Incluído' : 'Ausente no pacote' }}</dd></div>
                <div><dt>Soluções de referência</dt><dd>{{ $diagnostico['solucoes'] }}</dd></div>
            </dl>

            {{-- Um validador proprio muda COMO o problema e julgado, entao
                 nao pode ficar escondido atras de um "sim/nao" na lista. --}}
            @if($diagnostico['validador'])
                <p class="feature-message">O pacote traz um <strong>validador de saída</strong>: o julgamento usará o programa do pacote em vez da comparação padrão.</p>
            @else
                <p class="feature-message">Sem validador próprio. A saída da equipe será comparada com a esperada, tolerando espaços no fim das linhas.</p>
            @endif

            @unless($diagnostico['enunciado'])
                <p class="feature-message feature-error">O pacote não traz enunciado. As equipes verão o problema sem texto até que alguém escreva um.</p>
            @endunless
        </section>
    @endif

    <section class="surface feature-panel">
        <div class="feature-heading"><h2>Enviar pacote</h2></div>

        <form method="POST" action="{{ route('backend.import-package.store') }}" enctype="multipart/form-data" class="feature-form">
            @csrf

            <div class="feature-field">
                <label for="import-contest">Competição de destino</label>
                <select id="import-contest" name="contest_id" required>
                    <option value="">Escolha…</option>
                    @foreach($contests as $id => $name)
                        <option value="{{ $id }}" @selected(old('contest_id') == $id)>{{ $name }}</option>
                    @endforeach
                </select>
                {{-- A prova de treino (#43) nao aparece: os problemas dela sao
                     instantaneos versionados publicados pelo PracticePublisher,
                     e um problema posto a mao ali diverge da biblioteca. --}}
                <small>A prova de treino não recebe problema importado à mão.</small>
                @error('contest_id')<p class="feature-message feature-error">{{ $message }}</p>@enderror
            </div>

            <div class="feature-field">
                <label for="import-package">Arquivo do pacote (.zip)</label>
                <input id="import-package" name="package" type="file" accept=".zip,application/zip" required>
                @error('package')<p class="feature-message feature-error">{{ $message }}</p>@enderror
            </div>

            <div class="feature-field">
                <label for="import-short-name">Letra do problema</label>
                <input id="import-short-name" name="short_name" type="text" maxlength="10" value="{{ old('short_name') }}">
                <small>Em branco, usa a próxima letra livre da competição.</small>
                @error('short_name')<p class="feature-message feature-error">{{ $message }}</p>@enderror
            </div>

            <div class="feature-field feature-check feature-wide">
                <input id="import-dry-run" name="dry_run" type="checkbox" value="1" @checked(old('dry_run'))>
                <label for="import-dry-run">Apenas conferir o pacote, sem importar</label>
            </div>
            <p class="feature-help feature-wide">Com essa opção marcada, os limites, os casos de teste e o validador aparecem sem que nada seja gravado.</p>

            <button class="button-primary feature-wide" type="submit">Enviar pacote</button>
        </form>
    </section>
</div>
@endsection
