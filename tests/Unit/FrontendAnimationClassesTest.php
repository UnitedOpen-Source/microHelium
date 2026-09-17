<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Issue #257 -- uma classe de animação escrita no componente tem que existir
 * no CSS que o navegador baixa.
 *
 * `tailwindcss-animate` estava declarado só em `tailwind.config.js`, e esse
 * arquivo não é lido: o projeto está em Tailwind v4, que só carrega config JS
 * se alguém pedir com `@config` -- e ninguém pedia. O plugin nunca carregava,
 * as classes dele nunca eram geradas, e `animate-in`, `fade-in-0` e
 * `zoom-in-95` ficavam no HTML do DialogContent como texto inerte.
 *
 * Diálogo e menu suspenso apareciam e sumiam sem animação nenhuma. Nada
 * quebrava, nada avisava: o navegador ignora classe que não existe.
 *
 * Checagem estática sobre a fonte e sobre o CSS construído, como
 * `FrontendIslandsTest`, e pela mesma razão: é barata e pega exatamente o
 * jeito como isto quebra.
 */
class FrontendAnimationClassesTest extends TestCase
{
    /**
     * As famílias de utilitários que vêm do plugin, e só delas.
     *
     * O resto de uma classe como `data-[state=open]:animate-in` -- a variante
     * -- é do núcleo do Tailwind e nunca esteve em jogo.
     */
    private const FAMILIAS = '(?:animate-in|animate-out|fade-in|fade-out|zoom-in|zoom-out|slide-in-from-[a-z]+|slide-out-to-[a-z]+)';

    private function raiz(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Os utilitários de animação citados nos componentes, sem as variantes.
     *
     * @return array<int, string>
     */
    private function utilitariosCitados(): array
    {
        $raiz = $this->raiz().'/resources/js/components';

        // Antes de iterar: um caminho errado estoura dentro do iterador, e o
        // teste morreria de exceção em vez de dizer o que houve.
        $this->assertDirectoryExists($raiz, 'o diretório de componentes mudou de lugar');

        $arquivos = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz, \FilesystemIterator::SKIP_DOTS));

        $fontes = [];
        foreach ($arquivos as $arquivo) {
            if ($arquivo->isFile() && preg_match('/\.(vue|ts|js)$/', $arquivo->getFilename())) {
                $fontes[] = (string) file_get_contents($arquivo->getPathname());
            }
        }

        // Controle positivo: uma varredura que não achasse arquivo nenhum
        // faria o teste passar por falta de trabalho, que é o jeito mais
        // silencioso de uma guarda parar de guardar.
        $this->assertGreaterThan(20, count($fontes), 'a varredura de componentes não achou arquivos: o caminho mudou');

        preg_match_all('/(?:^|[\s"\'`:\]])('.self::FAMILIAS.'(?:-[0-9]+(?:\/[0-9]+)?|-\[[^\]]+\])?)/m', implode("\n", $fontes), $m);

        $utilitarios = array_values(array_unique($m[1]));
        sort($utilitarios);

        return $utilitarios;
    }

    private function cssConstruido(): string
    {
        $manifesto = $this->raiz().'/public/build/manifest.json';
        $this->assertFileExists($manifesto, 'o build não existe: rode `npm run build`');

        /** @var array<string, array{file: string}> $entradas */
        $entradas = json_decode((string) file_get_contents($manifesto), true);
        $this->assertArrayHasKey('resources/css/app.css', $entradas, 'o manifesto não tem a entrada do CSS');

        $caminho = $this->raiz().'/public/build/'.$entradas['resources/css/app.css']['file'];
        $css = (string) file_get_contents($caminho);

        // Segundo controle positivo: um CSS vazio ou truncado faria toda
        // asserção de ausência passar.
        $this->assertGreaterThan(10000, strlen($css), 'o CSS construído está pequeno demais para ser o real');

        return $css;
    }

    public function test_every_animation_utility_used_by_a_component_exists_in_the_built_css(): void
    {
        $utilitarios = $this->utilitariosCitados();

        $this->assertNotEmpty($utilitarios, 'nenhum utilitário de animação encontrado: o regex ou os componentes mudaram');

        $css = $this->cssConstruido();

        // O Tailwind escapa no seletor todo caractere que o CSS trata como
        // especial -- `/`, `[`, `]`, `.`, `%`, `=`, `:`. Tirar as barras
        // invertidas do CSS e procurar o utilitário cru é mais robusto que
        // reproduzir a lista de escapes: errar um caractere aqui produziria
        // um falso negativo, que é uma guarda acusando defeito que não
        // existe -- e foi exatamente o que aconteceu na primeira versão
        // deste teste, com `slide-in-from-top-[48%]`.
        $cssCru = str_replace('\\', '', $css);

        $faltando = [];
        foreach ($utilitarios as $utilitario) {
            if (! str_contains($cssCru, $utilitario)) {
                $faltando[] = $utilitario;
            }
        }

        $this->assertSame([], $faltando, 'classes de animação usadas nos componentes não existem no CSS construído: '.implode(', ', $faltando));
    }
}
