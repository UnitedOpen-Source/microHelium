<?php

namespace App\Services\Results;

use App\Models\Contest;
use App\Models\Leaderboard;
use App\Services\Clics\ClicsPresenter;
use App\Services\ContestAwards;
use App\Services\IcpcReportBuilder;
use Helium\User;
use Illuminate\Support\Collection;
use ZipArchive;

/**
 * Issue #271 -- o pacote de resultados, para alimentar um ranking nacional.
 *
 * Um ZIP gerado **somente de prova finalizada**, seguindo o precedente que o
 * `Backup\BackupService` estabeleceu: um `manifest.json` que diz "o que é
 * este arquivo", escrito para quem o abre num dia ruim, sem este repositório
 * aberto ao lado.
 *
 *   manifest.json       identidade da prova, datas, finalização, versão do
 *                       exportador, e o SHA-256 de cada arquivo
 *   standings.json      formato CLICS: equipes, problemas, classificação,
 *                       premiação
 *   standings.csv       as cinco colunas do BOCA, inalteradas
 *   organizations.json  as instituições referenciadas
 *
 * Dois consumidores num pacote só: a ferramenta que lê CLICS, e quem já
 * espera o arquivo do BOCA.
 *
 * ## A propriedade que sustenta a confiança
 *
 * **Exportar duas vezes a mesma prova finalizada produz os mesmos bytes**,
 * exceto `generated_at` -- que fica isolado num campo só do manifesto,
 * justamente para o resto ser comparável.
 *
 * Com isso o site nacional não precisa *confiar* no arquivo: ele pede a
 * reexportação e compara. Adulteração aparece como divergência, não como
 * suspeita.
 *
 * Isso custa cuidado em quatro pontos, e cada um tem guarda:
 *
 *   ordem       toda coleção é ordenada explicitamente, e os mapas do
 *               manifesto são ordenados por chave antes de serem
 *               serializados -- `json_encode` preserva a ordem de inserção,
 *               então um mapa montado em ordem de consulta muda quando o
 *               plano do banco muda;
 *   relógio     nada além de `generated_at` lê `now()`;
 *   caminhos    nenhum caminho absoluto entra no conteúdo;
 *   o ZIP       entradas gravadas em ordem fixa e com mtime fixo. Sem isso o
 *               contêiner difere mesmo com conteúdo idêntico, porque o
 *               formato guarda a data de cada entrada -- e "idêntico byte a
 *               byte" viraria uma promessa que o próprio formato quebra.
 *
 * ## Privacidade
 *
 * A régua: **o pacote não exporta mais do que o placar público já mostra,
 * mais a chave de agregação.**
 *
 * Nome de equipe, colocação, problemas e tempos já são públicos. `icpc_id`
 * **não** é -- é identificador pessoal, e é justamente o que torna a
 * agregação possível. Entra por decisão explícita, escrita aqui, e não por
 * efeito colateral.
 *
 * O que **não** entra em hipótese alguma: data de nascimento, e-mail, e
 * qualquer campo que a `ProfilePrivacyPolicy` proteja (#47 -- este sistema
 * tem contas gerenciadas de menores com `profile_visibility` privado por
 * padrão). Há teste que varre o ZIP inteiro procurando por eles.
 */
class ResultsBundleBuilder
{
    /**
     * A versão do FORMATO, não a da aplicação.
     *
     * O site nacional precisa saber como interpretar o que recebeu, e a
     * versão da aplicação muda por motivos que não mudam o formato -- usar
     * ela faria o receptor reagir a mudanças que não lhe dizem respeito.
     */
    public const FORMAT = 'microhelium-results-bundle';

    public const FORMAT_VERSION = 1;

    /**
     * O carimbo de tempo de cada entrada do ZIP.
     *
     * Fixo, e não `now()`: o formato ZIP guarda a data de cada arquivo, e
     * duas exportações da mesma prova produziriam contêineres diferentes só
     * por isso. 2000-01-01 porque é o piso do formato -- datas anteriores
     * não são representáveis em ZIP.
     */
    private const ENTRY_MTIME = 946684800;

    public function __construct(
        private IcpcReportBuilder $standings,
        private ClicsPresenter $presenter,
        private ContestAwards $awards,
    ) {}

    /**
     * Os arquivos do pacote, em ordem determinística.
     *
     * Separado de `zip()` porque é isto que a reprodutibilidade precisa
     * comparar: o contêiner é detalhe de empacotamento, o conteúdo é o
     * documento.
     *
     * @return array<string, string> caminho no pacote => conteúdo
     */
    public function files(Contest $contest): array
    {
        $this->assertExportable($contest);

        $teams = $this->teams($contest);

        // ksort e não a ordem em que foram montados: um mapa PHP preserva
        // ordem de insercao, entao reordenar o codigo mudaria o pacote sem
        // mudar o conteudo.
        $files = [
            'organizations.json' => $this->encode($this->presenter->organizations($teams)),
            'standings.csv' => $this->standings->csv($contest),
            'standings.json' => $this->encode($this->standingsDocument($contest, $teams)),
        ];

        ksort($files);

        return $files;
    }

    /**
     * O manifesto, que fecha o pacote sobre si mesmo.
     *
     * @param  array<string, string>  $files
     */
    public function manifest(Contest $contest, array $files, ?string $generatedAt = null): string
    {
        $entries = [];

        foreach ($files as $path => $content) {
            $entries[$path] = [
                'sha256' => hash('sha256', $content),
                'bytes' => strlen($content),
            ];
        }

        ksort($entries);

        return $this->encode([
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            // O ÚNICO campo volátil do pacote inteiro. Está isolado aqui, e
            // nomeado, para que "compare tudo menos isto" seja uma instrução
            // que cabe numa frase.
            'generated_at' => $generatedAt ?? now()->toIso8601String(),
            'exporter' => [
                'name' => 'microHelium',
                'format' => self::FORMAT,
                'format_version' => self::FORMAT_VERSION,
            ],
            'contest' => $this->identity($contest),
            'files' => $entries,
            // Escrito para quem abre este arquivo num dia ruim, num shell,
            // sem o repositório aberto ao lado -- mesma razão do manifesto
            // do backup.
            'notes' => [
                'Gerado somente de prova FINALIZADA: nada aqui e provisorio.',
                'Reexporte a mesma prova e compare: tudo menos generated_at deve bater byte a byte.',
                'files[].sha256 e do CONTEUDO de cada arquivo deste pacote; confira antes de confiar.',
                'contest.uuid identifica a prova entre instalacoes; contests.id e local e colide.',
                'Contem icpc_id de equipe, que e identificador pessoal e e a chave de agregacao.',
                'NAO contem data de nascimento, e-mail nem qualquer campo de privacidade de perfil.',
            ],
        ]);
    }

    /**
     * Escreve o ZIP em `$target`.
     */
    public function zip(Contest $contest, string $target, ?string $generatedAt = null): void
    {
        $files = $this->files($contest);
        $files['manifest.json'] = $this->manifest($contest, $files, $generatedAt);

        ksort($files);

        $zip = new ZipArchive;

        // OVERWRITE porque `tempnam()` já criou o arquivo e `CREATE` sozinho
        // recusa caminho existente -- mesma razão do backup e do webcast.
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new ResultsBundleNotReadyException("Nao foi possivel criar o pacote em {$target}.");
        }

        foreach ($files as $path => $content) {
            $zip->addFromString($path, $content);
            // Sem isto, duas exportações da mesma prova diferem no contêiner
            // só pela data das entradas.
            $zip->setMtimeName($path, self::ENTRY_MTIME);
        }

        if (! $zip->close()) {
            throw new ResultsBundleNotReadyException('Falha ao finalizar o pacote: '.$zip->getStatusString());
        }
    }

    public function filename(Contest $contest): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($contest->name ?? 'prova'));

        return 'resultados-'.trim((string) $slug, '-').'-'.$contest->uuid.'.zip';
    }

    /**
     * O portão. Nada sai daqui de prova que ainda pode mudar.
     */
    public function assertExportable(Contest $contest): void
    {
        if ($contest->is_practice) {
            // Issue #43 -- o contest técnico de treino não é um evento. Não
            // tem classificação, e não pode ser alcançado por uma exportação
            // com forma de evento.
            throw new ResultsBundleNotReadyException('O Treino Livre nao e um evento e nao tem resultados a exportar.');
        }

        if (! $contest->isFinalized()) {
            throw new ResultsBundleNotReadyException(
                'Esta prova ainda nao foi finalizada. Exportar resultado provisorio -- com rejulgamento pendente, '
                .'ou com o placar ainda congelado -- envenena o agregado nacional. Finalize a prova primeiro.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function identity(Contest $contest): array
    {
        return [
            'uuid' => (string) $contest->uuid,
            'local_id' => (int) $contest->id,
            'name' => (string) $contest->name,
            'edition' => $contest->edition ?: null,
            'phase' => $contest->phase ?: null,
            'start_time' => $contest->start_time?->toIso8601String(),
            'end_time' => $contest->end_time?->toIso8601String(),
            'duration_minutes' => (int) $contest->duration,
            'freeze_minutes' => (int) ($contest->getAttributes()['freeze_time'] ?? 0),
            'penalty_minutes' => (int) $contest->penalty,
            'finalized_at' => $contest->finalized_at?->toIso8601String(),
            'unfrozen_at' => $contest->unfrozen_at?->toIso8601String(),
            'sites' => $contest->sites()
                ->orderBy('id')
                ->get(['id', 'name'])
                ->map(fn ($site) => ['id' => (int) $site->id, 'name' => (string) $site->name])
                ->all(),
        ];
    }

    /**
     * O documento de classificação, em formato CLICS.
     *
     * `frozen: false` de propósito e sem alternativa: a prova está
     * finalizada, então o que sai aqui é a classificação final. Um pacote de
     * resultados congelado seria um pacote que esconde o resultado, o que
     * não é resultado nenhum.
     *
     * @param  Collection<int, User>  $teams
     * @return array<string, mixed>
     */
    private function standingsDocument(Contest $contest, Collection $teams): array
    {
        return [
            'contest' => $this->identity($contest),
            'problems' => $this->presenter->problems($contest),
            'teams' => $this->presenter->teams($teams),
            'scoreboard' => $this->standingsRows($contest),
            'awards' => $this->awards->forContest($contest)['awards'],
            // A mesma classificacao nas cinco colunas do BOCA, para o
            // consumidor que ja espera aquele arquivo nao ter que reprocessar
            // o JSON.
            'icpc_rows' => $this->standings->rows($contest),
            // Nao e enfeite: o filer precisa saber se o arquivo que ele vai
            // enviar esta incompleto, e a informacao some se so existir num
            // cabecalho HTTP.
            'teams_missing_icpc_id' => $this->standings->teamsMissingIcpcId($contest),
        ];
    }

    /**
     * A classificação, projetada campo a campo.
     *
     * NÃO é `Leaderboard::getScoreboard()` cru, e a diferença é privacidade,
     * não estilo. Aquele método devolve `'user' => $user` -- o **modelo
     * inteiro**. `$hidden` de `Helium\User` cobre `password`,
     * `remember_token` e `birthdate`, e **não cobre `email`**: serializar a
     * linha crua poria o e-mail de cada equipe num arquivo destinado a um
     * site nacional.
     *
     * Quem pegou isso foi o teste que varre o ZIP inteiro procurando campo
     * protegido, e não a leitura do código.
     *
     * Projetar também estabiliza o formato publicado: o pacote deixa de
     * mudar de forma quando a tela de placar muda de forma, e o receptor
     * nacional não é um consumidor que se possa quebrar de leve.
     *
     * A régua, aplicada aqui e visível numa olhada: o que o placar público
     * já mostra, mais `icpc_id`, que é a chave de agregação.
     *
     * @return list<array<string, mixed>>
     */
    private function standingsRows(Contest $contest): array
    {
        return collect(Leaderboard::getScoreboard($contest->id, false))
            ->map(function (array $row) {
                /** @var User $team */
                $team = $row['user'];

                return [
                    'rank' => (int) $row['rank'],
                    'team_id' => (string) $team->user_id,
                    'team_name' => $team->fullname ?? $team->username,
                    'icpc_id' => $team->icpc_id !== null && $team->icpc_id !== ''
                        ? (string) $team->icpc_id
                        : null,
                    'organization_id' => $team->organization_id !== null
                        ? (string) $team->organization_id
                        : null,
                    'site_id' => $team->site_id !== null ? (string) $team->site_id : null,
                    'problems_solved' => (int) $row['problems_solved'],
                    'total_time' => (int) $row['total_time'],
                    // Ordenado por rotulo: a ordem vinha de um `groupBy`,
                    // que nao a garante, e uma prova com os mesmos dados
                    // produziria dois pacotes diferentes.
                    'problems' => collect($row['problems'])
                        ->sortBy('short_name')
                        ->map(fn ($p) => [
                            'label' => (string) $p['short_name'],
                            'attempts' => (int) $p['attempts'],
                            'solved' => (bool) $p['is_solved'],
                            'first_to_solve' => (bool) $p['is_first_solver'],
                            'solved_time' => $p['solved_time'] !== null ? (int) $p['solved_time'] : null,
                            'penalty_time' => (int) $p['penalty_time'],
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, User>
     */
    private function teams(Contest $contest): Collection
    {
        return User::query()
            ->where('contest_id', $contest->id)
            ->where('user_type', User::TYPE_TEAM)
            ->orderBy('user_id')
            ->get();
    }

    /**
     * `JSON_PRETTY_PRINT` porque este arquivo é lido por pessoas quando algo
     * dá errado, e um diff de uma linha de dez mil caracteres não ajuda
     * ninguém a ver o que mudou entre duas exportações.
     *
     * @param  array<mixed>  $data
     */
    private function encode(array $data): string
    {
        return json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        )."\n";
    }
}
