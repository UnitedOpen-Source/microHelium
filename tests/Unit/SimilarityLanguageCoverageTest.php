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
     */
    public function test_the_map_actually_covers_the_languages_a_contest_runs_on()
    {
        $obrigatorias = ['java21', 'java25', 'cpp_gpp13', 'cpp17_gpp', 'py3', 'js_node24', 'kt'];

        foreach ($obrigatorias as $ext) {
            $this->assertNotNull(
                SimilarityLanguageMap::jplagLanguageFor(new Language(['extension' => $ext])),
                "'{$ext}' é linguagem de maratona e o JPlag a analisa; não pode sair do mapa"
            );
        }
    }
}
