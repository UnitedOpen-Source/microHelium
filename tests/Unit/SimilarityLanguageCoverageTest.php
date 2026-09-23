<?php

namespace Tests\Unit;

use App\Models\Language;
use App\Services\Similarity\SimilarityLanguageMap;
use Tests\TestCase;

/**
 * Issue #305 -- uma variante nova de uma linguagem que o JPlag JÁ analisa
 * não pode ficar fora da detecção de plágio.
 *
 * O defeito que motivou isto: a entrada `java25` foi acrescentada ao
 * catálogo ao lado de `java21`, e não entrou no `SimilarityLanguageMap`. O
 * JPlag analisa Java desde sempre e `java21`/`java17` já estavam mapeadas.
 *
 * A falha é silenciosa, que é o pior formato. `SimilarityController` filtra
 * as linguagens por `SimilarityLanguageMap::isSupported()`, então uma
 * linguagem fora do mapa simplesmente **não aparece** na análise. Não há
 * erro nem aviso: numa prova, submissão em Java 21 seria conferida e em Java
 * 25 não, e ninguém saberia.
 *
 * ## Por que a regra é "irmãs por extensão de arquivo", e não uma lista
 *
 * A tentação era manter uma lista das linguagens sem parser. Ela teria
 * **54 entradas** hoje -- Haskell, Tcl, Ada, Forth, Brainfuck... -- e uma
 * lista desse tamanho vira burocracia que se preenche no automático, o que
 * é o oposto de uma rede de proteção.
 *
 * A regra abaixo não precisa de lista nenhuma: **se alguma linguagem com o
 * mesmo `file_ext` está mapeada, todas as ativas com aquele `file_ext` têm
 * de estar.** `java25` e `java21` compartilham `java`; `cpp_clang` e
 * `cpp_gpp13` compartilham `cpp`. Linguagem sem irmã mapeada (Haskell, Tcl)
 * fica fora do escopo -- corretamente, porque o JPlag não tem parser para
 * ela e não há decisão a cobrar.
 *
 * Isso mira o defeito de maior probabilidade real: acrescentar a versão
 * nova ao lado da antiga e esquecer do mapa.
 */
class SimilarityLanguageCoverageTest extends TestCase
{
    public function test_an_active_variant_of_an_analysable_language_is_never_left_out()
    {
        $catalogo = Language::getDefaultLanguages();

        // As extensões de arquivo para as quais o JPlag tem parser, deduzidas
        // do próprio mapa -- e não redigitadas, que duplicaria a fonte da
        // verdade e daria um teste que concorda consigo mesmo.
        $analisaveis = [];
        foreach ($catalogo as $l) {
            if (SimilarityLanguageMap::jplagLanguageFor(new Language(['extension' => $l['extension']])) !== null) {
                $analisaveis[$l['file_ext']] = true;
            }
        }

        $esquecidas = [];
        foreach ($catalogo as $l) {
            if (! $l['is_active'] || ! isset($analisaveis[$l['file_ext']])) {
                continue;
            }

            if (SimilarityLanguageMap::jplagLanguageFor(new Language(['extension' => $l['extension']])) === null) {
                $esquecidas[] = "{$l['extension']} ({$l['name']}, .{$l['file_ext']})";
            }
        }

        $this->assertEmpty(
            $esquecidas,
            "linguagens ativas que o JPlag consegue analisar e estão fora do mapa:\n  "
            .implode("\n  ", $esquecidas)
            ."\nOutra entrada com a mesma extensão de arquivo já está mapeada, então existe parser."
            ."\nSem o mapeamento, a detecção de plágio simplesmente não roda para elas -- em silêncio."
            .'\nCorrija em '.SimilarityLanguageMap::class.'::MAP.'
        );
    }

    /**
     * O controle positivo. Sem ele o teste acima passaria com o mapa VAZIO:
     * sem nenhuma linguagem mapeada não há `file_ext` analisável, e o laço
     * não teria o que reprovar.
     *
     * As três entradas de C entraram aqui em 23/09/2026. Elas são o furo que
     * a regra das irmãs não podia ver: como NENHUMA das três estava mapeada,
     * não havia irmã para cobrar, e C -- a linguagem mais usada numa maratona
     * -- ficava fora da detecção de plágio em silêncio.
     */
    public function test_the_map_actually_covers_the_languages_a_contest_runs_on()
    {
        $obrigatorias = [
            'java21', 'java25', 'cpp_gpp13', 'cpp17_gpp', 'py3', 'js_node24', 'kt',
            'c_gcc13', 'c99_gcc', 'c_clang17', 'go', 'scm',
        ];

        foreach ($obrigatorias as $ext) {
            $this->assertNotNull(
                SimilarityLanguageMap::jplagLanguageFor(new Language(['extension' => $ext])),
                "'{$ext}' é linguagem de maratona e o JPlag a analisa; não pode sair do mapa"
            );
        }
    }

    /**
     * Os identificadores que o JPlag 6.2.0 aceita de verdade, lidos do
     * `--help` do jar fixado (aarch64, 23/09/2026) e não da tabela do README.
     *
     * @see https://github.com/jplag/JPlag/releases/tag/v6.2.0
     */
    private const IDENTIFICADORES_DO_JPLAG = [
        'c', 'cpp', 'csharp', 'emf', 'emf-model', 'go', 'java', 'javascript',
        'kotlin', 'llvmir', 'multi', 'python3', 'rlang', 'rust', 'scala',
        'scheme', 'scxml', 'swift', 'text', 'typescript',
    ];

    /**
     * O teste que faltava, e que teria evitado Go quebrado desde o #74 (11/09/2026).
     *
     * O mapa trazia `'go' => 'golang'`. `golang` é como o README do JPlag
     * CHAMA a linguagem na tabela de suporte; o identificador que a CLI aceita
     * é `go`. A diferença não aparecia em teste nenhum porque nada aqui
     * confrontava o mapa com o jar: `isSupported('go')` respondia `true`, a
     * interface oferecia Go, o job era despachado, e só então o JPlag saía
     * com erro -- `Language golang does not exists`. Medido em 23/09/2026 com
     * o jar fixado.
     *
     * A lista acima é o contrato, e ela é da VERSÃO: por isso o teste reprova
     * quando o pino muda sem que alguém releia o `--help`. Um identificador
     * novo (ou renomeado) numa release nova é exatamente o tipo de mudança que
     * este repositório não quer descobrir durante uma prova.
     */
    public function test_todo_identificador_usado_no_mapa_existe_no_jplag_fixado()
    {
        $this->assertSame(
            '6.2.0',
            config('similarity.jplag.version'),
            "o pino do JPlag mudou, e a lista de identificadores deste teste foi conferida na 6.2.0.\n"
            ."Rode `java -jar <jar> --help` na versão nova, copie a seção `Languages:` para\n"
            .'IDENTIFICADORES_DO_JPLAG e só então atualize esta asserção.'
        );

        $desconhecidos = [];

        foreach (Language::getDefaultLanguages() as $l) {
            $identificador = SimilarityLanguageMap::jplagLanguageFor(new Language(['extension' => $l['extension']]));

            if ($identificador !== null && ! in_array($identificador, self::IDENTIFICADORES_DO_JPLAG, true)) {
                $desconhecidos[] = "{$l['extension']} => '{$identificador}'";
            }
        }

        $this->assertSame(
            [],
            $desconhecidos,
            "o mapa aponta para identificadores que o JPlag 6.2.0 não tem:\n  "
            .implode("\n  ", $desconhecidos)
            ."\nA CLI recusa a execução inteira nesse caso (`Language X does not exists`),\n"
            .'então a checagem não fica sem resposta: ela FALHA, com a equipe já na tela.'
        );
    }

    /**
     * Racket fica fora do mapa DE PROPÓSITO, e isto é o que impede que alguém
     * "conserte" a ausência dele e quebre toda checagem de Racket.
     *
     * Medido em 23/09/2026, JPlag 6.2.0:
     *
     *   - o parser `scheme` só lê arquivos terminados em `.scm`/`.ss`, e o
     *     `JplagSimilarityEngine` grava cada envio como `submission.{file_ext}`
     *     -- `submission.rkt` aqui. Resultado: `Nothing to parse for submission`
     *     em todos, e a execução termina em `Not enough valid submissions!`;
     *   - renomear também não salva: `#lang racket` na linha 1 é erro léxico
     *     para esse parser (`Encountered: "l" after "#"`).
     *
     * Ou seja, mapear `rkt` trocaria "não analisado em silêncio" por "falha na
     * cara do operador", que é pior. Guile (`scm`) não tem esse problema e
     * está mapeado.
     */
    public function test_racket_continua_fora_do_mapa_e_o_motivo_esta_medido()
    {
        $this->assertNull(
            SimilarityLanguageMap::jplagLanguageFor(new Language(['extension' => 'rkt'])),
            "`rkt` não pode ser mapeado: o parser `scheme` do JPlag 6.2.0 ignora arquivos .rkt\n"
            ."(`Nothing to parse`), e a execução inteira falha com `Not enough valid submissions!`.\n"
            .'Ver o docblock deste teste para a medição.'
        );
    }
}
