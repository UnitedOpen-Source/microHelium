<?php

namespace Tests\Unit;

use App\Models\Language;
use App\Services\Similarity\SimilarityLanguageMap;
use Tests\TestCase;

/**
 * Issue #305 -- toda linguagem ativa tem de ter uma POSIÇÃO declarada quanto
 * à detecção de plágio: ou ela é analisável pelo JPlag, ou está aqui na
 * lista de exceções com o motivo.
 *
 * Isto existe porque a ausência é silenciosa e cara. `SimilarityController`
 * filtra as linguagens por `SimilarityLanguageMap::isSupported()`, então uma
 * linguagem fora do mapa simplesmente **não aparece** na análise de
 * similaridade. Ninguém vê erro; a checagem de plágio só não acontece.
 *
 * E aconteceu: a entrada `java25` foi acrescentada ao catálogo sem entrar no
 * mapa. O JPlag analisa Java desde sempre e `java21`/`java17` já estavam
 * lá -- o resultado seria uma prova em que submissão em Java 21 é conferida
 * e em Java 25 não, sem nada indicando isso. Nenhum teste pegava, porque até
 * aqui não havia teste nenhum sobre este mapa.
 */
class SimilarityLanguageCoverageTest extends TestCase
{
    /**
     * Linguagens ativas que o JPlag 6.2.0 NÃO analisa, e por quê.
     *
     * Estar aqui é uma decisão registrada, não um esquecimento. Ao ativar
     * uma linguagem nova, ou ela entra no mapa, ou entra aqui com o motivo.
     */
    private const SEM_ANALISE_DE_PLAGIO = [
        // O JPlag traz um parser de C, mas marcado como `legacy`, e mirando
        // um padrão mais antigo do que o que estes comandos compilam
        // (`-std=c17`/`-std=c99`). A decisão de não usá-lo é anterior a este
        // teste e está explicada no docblock de SimilarityLanguageMap.
        'c_gcc13' => 'JPlag só tem parser de C legacy, para um padrão mais antigo',
        'c99_gcc' => 'JPlag só tem parser de C legacy, para um padrão mais antigo',
        'c_clang17' => 'JPlag só tem parser de C legacy, para um padrão mais antigo',

        // Sem parser no JPlag 6.2.0.
        'php' => 'sem parser no JPlag 6.2.0',
        'rb' => 'sem parser no JPlag 6.2.0',
        'pas_fpc' => 'sem parser no JPlag 6.2.0',
        'perl' => 'sem parser no JPlag 6.2.0',
        'sh' => 'sem parser no JPlag 6.2.0',
        'sed' => 'sem parser no JPlag 6.2.0',

        // O fonte é um ZIP com `project.json` dentro, e não texto. Comparar
        // o binário não produziria sinal; exigiria um parser que entendesse
        // a árvore de blocos do Scratch.
        'scratch' => 'fonte é um .sb3 (ZIP), não texto; exigiria parser de blocos',

        // Pseudocódigo em português da UNIVALI; não existe parser de Portugol
        // em nenhuma ferramenta de similaridade conhecida.
        'portugol_studio' => 'sem parser em nenhuma ferramenta de similaridade conhecida',
    ];

    public function test_every_active_language_has_a_declared_plagiarism_position()
    {
        $semPosicao = [];

        foreach (Language::getDefaultLanguages() as $lang) {
            if (! $lang['is_active']) {
                continue;
            }

            $ext = $lang['extension'];
            $mapeada = SimilarityLanguageMap::jplagLanguageFor(
                new Language(['extension' => $ext])
            ) !== null;

            if (! $mapeada && ! array_key_exists($ext, self::SEM_ANALISE_DE_PLAGIO)) {
                $semPosicao[] = $ext;
            }
        }

        $this->assertEmpty(
            $semPosicao,
            'linguagens ativas sem posição declarada quanto a plágio: '.implode(', ', $semPosicao)
            ."\nOu mapeie em SimilarityLanguageMap, ou declare a exceção com o motivo em "
            .self::class.'::SEM_ANALISE_DE_PLAGIO.'
        );
    }

    /**
     * O controle negativo: a lista de exceções não pode conter uma
     * linguagem que o JPlag *analisa*. Sem isto, alguém "resolveria" a
     * falha acima jogando a linguagem na lista de exceções e desligaria a
     * detecção de plágio sem perceber -- que é exatamente o defeito que
     * este teste foi escrito para impedir.
     */
    public function test_the_exception_list_never_hides_a_language_jplag_can_analyse()
    {
        $escondidas = [];

        foreach (array_keys(self::SEM_ANALISE_DE_PLAGIO) as $ext) {
            if (SimilarityLanguageMap::jplagLanguageFor(new Language(['extension' => $ext])) !== null) {
                $escondidas[] = $ext;
            }
        }

        $this->assertEmpty(
            $escondidas,
            'estão na lista de exceções mas o JPlag analisa: '.implode(', ', $escondidas)
        );
    }
}
