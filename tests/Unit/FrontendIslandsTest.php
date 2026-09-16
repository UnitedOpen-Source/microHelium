<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Issue #246 -- uma ilha registrada tem que ser uma ilha usada.
 *
 * Quatro das seis eram código morto: `scoreboard`, `run-list`, `submit-form`
 * e `clarification-list` não apareciam em view nenhuma. E não era só peso --
 * cada uma declara `contestId` como prop obrigatória e o laço de montagem
 * monta SEM PROPS, então escrever `<scoreboard></scoreboard>` numa view
 * estouraria dentro do componente, o `try/catch` engoliria o erro e
 * removeria o host: página sem o painel e sem dizer nada.
 *
 * A ilha parecia disponível e não estava. Este teste é o que impede a
 * próxima aparecer.
 *
 * Checagem estática sobre a fonte, como `FrontendCspCompatibilityTest`, e
 * pela mesma razão: é barata e pega exatamente o jeito como isto quebra.
 */
class FrontendIslandsTest extends TestCase
{
    /**
     * As chaves da tabela `islands` de resources/js/app.js.
     *
     * @return array<int, string>
     */
    private function islands(): array
    {
        $fonte = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/app.js');

        $this->assertMatchesRegularExpression('/const islands = \{/', $fonte, 'a tabela de ilhas mudou de forma');

        $tabela = substr($fonte, strpos($fonte, 'const islands = {'));
        $tabela = substr($tabela, 0, strpos($tabela, '};'));

        preg_match_all("/^\s*'?([a-z][a-z0-9-]*)'?\s*:/m", $tabela, $m);

        return $m[1];
    }

    /**
     * @return array<int, string>
     */
    private function views(): array
    {
        $raiz = dirname(__DIR__, 2).'/resources/views';
        $arquivos = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz, \FilesystemIterator::SKIP_DOTS));
        $conteudos = [];

        foreach ($arquivos as $arquivo) {
            if ($arquivo->isFile() && str_ends_with($arquivo->getFilename(), '.blade.php')) {
                $conteudos[] = (string) file_get_contents($arquivo->getPathname());
            }
        }

        // Controle positivo: uma varredura que não achasse view nenhuma
        // faria o teste seguinte reprovar tudo, e um erro de caminho aqui
        // pareceria um defeito no código de produção.
        $this->assertGreaterThan(20, count($conteudos), 'a varredura de views não encontrou quase nada');

        return $conteudos;
    }

    public function test_every_registered_island_is_used_by_a_view(): void
    {
        $ilhas = $this->islands();

        // Controle positivo: se a extração devolvesse vazio, o laço abaixo
        // não afirmaria nada e o teste passaria por falta de trabalho.
        $this->assertNotEmpty($ilhas, 'nenhuma ilha foi extraída de app.js');

        $views = $this->views();

        foreach ($ilhas as $ilha) {
            $usada = false;

            foreach ($views as $view) {
                if (str_contains($view, "<{$ilha}")) {
                    $usada = true;
                    break;
                }
            }

            $this->assertTrue(
                $usada,
                "A ilha <{$ilha}> está registrada em resources/js/app.js e nenhuma view a usa. ".
                'O laço de montagem não passa props, então ela quebraria em silêncio se alguém tentasse. '.
                'Telas novas usam [data-feature-page] e features/mount.js, que passa props de verdade.'
            );
        }
    }

    /**
     * O outro lado: uma ilha usada numa view e ausente da tabela renderiza
     * um elemento desconhecido, que o navegador trata como `<span>` vazio.
     * Também silencioso, e também precisa falhar aqui.
     */
    public function test_no_view_uses_an_island_that_is_not_registered(): void
    {
        $ilhas = $this->islands();

        foreach ($this->views() as $view) {
            preg_match_all('/<(contest-timer|theme-toggle|scoreboard|run-list|submit-form|clarification-list)\b/', $view, $m);

            foreach (array_unique($m[1]) as $usada) {
                $this->assertContains($usada, $ilhas, "A view usa <{$usada}> e resources/js/app.js não registra essa ilha.");
            }
        }
    }
}
