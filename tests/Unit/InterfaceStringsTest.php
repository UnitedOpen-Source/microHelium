<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #397 -- a area migrada nao volta a ter texto escrito direto no codigo.
 *
 * A interface nasceu com cada texto escrito no template. A #397 migra por
 * area, e este teste e o que faz uma area migrada continuar migrada: ele
 * varre SO os arquivos das listas abaixo -- a area cresce aumentando a lista
 * -- e reprova texto de interface fora de `__()` (Blade/PHP) ou `t()`
 * (Vue/JS). Tambem confere que toda chave usada existe em `es`, e que o
 * catalogo que vai ao navegador tem as chaves do Vue.
 *
 * O que fica fora da varredura, por desenho: comentarios, <svg>, <style>,
 * <script> dentro do Blade, e elementos com `translate="no"` -- o proprio
 * HTML dizendo "isto nao se traduz" (codigo de exemplo, siglas de veredito).
 */
class InterfaceStringsTest extends TestCase
{
    /** Blade da area do competidor, ja migrado. */
    private const BLADE = [
        'resources/views/layouts/app.blade.php',
        'resources/views/layouts/auth.blade.php',
        'resources/views/errors/layout.blade.php',
        'resources/views/auth/login.blade.php',
        'resources/views/home.blade.php',
        'resources/views/exercises/index.blade.php',
        'resources/views/exercises/show.blade.php',
        'resources/views/exercises/submit.blade.php',
        'resources/views/submissions.blade.php',
        'resources/views/submission-show.blade.php',
        'resources/views/scoreboard.blade.php',
        'resources/views/partials/brand.blade.php',
        'resources/views/partials/error-summary.blade.php',
        'resources/views/partials/table-filter.blade.php',
        'resources/views/partials/verdict.blade.php',
        'resources/views/partials/locale-switcher.blade.php',
        'resources/views/partials/i18n-catalog.blade.php',
    ];

    /** Vue e JS da area, ja migrados. */
    private const SCRIPTS = [
        'resources/js/components/ContestTimer.vue',
        'resources/js/components/ThemeToggle.vue',
        'resources/js/app.js',
    ];

    /**
     * PHP que manda mensagem para as telas da area. Nao passa pelo detector
     * de texto (um controlador tem literal demais que nao e interface), mas
     * as chaves que usa precisam existir em `es`.
     */
    private const PHP_KEYS_ONLY = [
        'routes/web.php',
        'app/Http/Controllers/SubmitController.php',
        'app/Http/Controllers/SubmissionController.php',
    ];

    /**
     * O que aparece na tela e nao se traduz, cada um com o porque.
     */
    private const UNTRANSLATABLE = [
        'MicroHelium',          // nome do produto
        'micro', 'helium', 'h', // a marca, partida em spans para o estilo
        'μ',                    // idem
        'S.O.S.',               // sigla universal, e o nome do canal na #139
        'AGPL-3.0-or-later',    // identificador SPDX da licenca
        'AC', 'WA', 'TLE', 'CE', 'RTE', 'MLE', // siglas de veredito da ICPC
        'ID', 's', 'MB', 'KB', 'min',          // unidades e siglas
    ];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function read(string $path): string
    {
        $full = $this->root().'/'.$path;
        $this->assertFileExists($full, "o arquivo da area migrada sumiu: {$path}");

        return (string) file_get_contents($full);
    }

    /**
     * @return array<string, string>
     */
    private function catalog(string $path): array
    {
        $decoded = json_decode($this->read($path), true);
        $this->assertIsArray($decoded, "{$path} nao e um JSON de objeto");

        return $decoded;
    }

    /** Remove o conteudo que nao e interface, antes de qualquer varredura. */
    private function stripNonInterface(string $source): string
    {
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
        $source = (string) preg_replace('/<!--.*?-->/s', '', $source);
        foreach (['svg', 'style', 'script'] as $tag) {
            $source = (string) preg_replace('/<'.$tag.'\b.*?<\/'.$tag.'>/si', '', $source);
        }
        // `translate="no"`: o elemento inteiro, ate o fechamento da mesma tag.
        $source = (string) preg_replace('/<(\w+)\b[^>]*\btranslate="no"[^>]*>.*?<\/\1>/s', '', $source);

        return $source;
    }

    /** Remove `__('...')`/`t('...')` com o primeiro argumento literal. */
    private function stripTranslated(string $code): string
    {
        return (string) preg_replace('/\b(?:__|t)\(\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/s', '__(', $code);
    }

    /** Literais de string com cara de texto de interface. */
    private function looksLikeInterfaceLiteral(string $literal): bool
    {
        return preg_match('/[À-ÖØ-öø-ÿ]/u', $literal) === 1
            || preg_match('/^[A-Z][a-z]+(?:[\s,.!?:]|$)/', $literal) === 1;
    }

    /**
     * @return list<string> o texto de interface encontrado fora de traducao
     */
    private function literalsInCode(string $code): array
    {
        $found = [];
        preg_match_all('/\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"/s', $this->stripTranslated($code), $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $literal = $match[1] !== '' ? $match[1] : ($match[2] ?? '');
            if ($this->looksLikeInterfaceLiteral($literal)) {
                $found[] = $literal;
            }
        }

        return $found;
    }

    /** O corpo de `{{ }}`, `{!! !!}` e das diretivas Blade com parenteses. */
    private function extractPhp(string $blade, string &$markup): string
    {
        $php = [];
        $markup = (string) preg_replace_callback('/\{!!(.*?)!!\}|\{\{(.*?)\}\}/s', function ($m) use (&$php) {
            $php[] = $m[1] !== '' ? $m[1] : ($m[2] ?? '');

            return '§';
        }, $blade);

        // @diretiva(...) com parenteses balanceados, e @php ... @endphp.
        $markup = (string) preg_replace_callback('/@php\b(.*?)@endphp/s', function ($m) use (&$php) {
            $php[] = $m[1];

            return '';
        }, $markup);

        $out = '';
        $length = strlen($markup);
        for ($i = 0; $i < $length; $i++) {
            if ($markup[$i] === '@' && preg_match('/\G@(\w+)\s*\(/A', $markup, $d, 0, $i)) {
                $depth = 0;
                $start = $i + strlen($d[0]) - 1;
                for ($j = $start; $j < $length; $j++) {
                    $depth += $markup[$j] === '(' ? 1 : ($markup[$j] === ')' ? -1 : 0);
                    if ($depth === 0) {
                        break;
                    }
                }
                $php[] = substr($markup, $start, $j - $start + 1);
                $i = $j;

                continue;
            }
            if ($markup[$i] === '@' && preg_match('/\G@\w+/A', $markup, $d, 0, $i)) {
                $i += strlen($d[0]) - 1;

                continue;
            }
            $out .= $markup[$i];
        }
        $markup = $out;

        return implode("\n", $php);
    }

    private function isUntranslatable(string $text): bool
    {
        $words = preg_split('/\s+/u', trim((string) preg_replace('/&[a-z]+;|&#\d+;|§/u', ' ', $text))) ?: [];
        foreach ($words as $word) {
            // O termo inteiro primeiro (`AGPL-3.0-or-later` tem hifen), e
            // depois sem a pontuacao em volta (`MicroHelium,`).
            $bare = trim($word, '·•—-–→←↗✓✕×!|/:?#+().,;');
            if (in_array($word, self::UNTRANSLATABLE, true) || in_array($bare, self::UNTRANSLATABLE, true)) {
                continue;
            }
            if (preg_match('/\pL/u', $bare) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function hardcodedInMarkup(string $markup): array
    {
        $found = [];
        preg_match_all('/\s(?:title|aria-label|placeholder|alt)="([^"]*)"/', $markup, $attrs);
        foreach ($attrs[1] as $value) {
            if (! $this->isUntranslatable($value)) {
                $found[] = $value;
            }
        }
        foreach (preg_split('/<[^>]*>/', $markup) ?: [] as $text) {
            if (! $this->isUntranslatable($text)) {
                $found[] = trim($text);
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function hardcodedInBlade(string $blade): array
    {
        $markup = '';
        $php = $this->extractPhp($this->stripNonInterface($blade), $markup);

        return array_merge($this->hardcodedInMarkup($markup), $this->literalsInCode($php));
    }

    /**
     * @return list<string>
     */
    private function hardcodedInScript(string $path, string $source): array
    {
        $found = [];
        $script = $source;
        if (str_ends_with($path, '.vue')) {
            preg_match('/<template>(.*)<\/template>/s', $source, $tpl);
            $template = $this->stripNonInterface($tpl[1] ?? '');
            // Atributo com ligacao (`:x="..."`, `@x="..."`) e expressao, nao texto.
            $expressions = [];
            $template = (string) preg_replace_callback('/\s[:@][\w-]+="([^"]*)"/', function ($m) use (&$expressions) {
                $expressions[] = $m[1];

                return '';
            }, $template);
            $template = (string) preg_replace_callback('/\{\{(.*?)\}\}/s', function ($m) use (&$expressions) {
                $expressions[] = $m[1];

                return '§';
            }, $template);
            $found = array_merge($this->hardcodedInMarkup($template), $this->literalsInCode(implode("\n", $expressions)));
            preg_match_all('/<script\b[^>]*>(.*?)<\/script>/s', $source, $scripts);
            $script = implode("\n", $scripts[1]);
        }
        $script = (string) preg_replace('/\/\*.*?\*\//s', '', $script);
        $script = (string) preg_replace('/(^|[^:\'"])\/\/.*$/m', '$1', $script);

        return array_merge($found, $this->literalsInCode($script));
    }

    /**
     * @return list<string> as chaves de `__()` no arquivo
     */
    private function phpKeys(string $source): array
    {
        preg_match_all('/\b__\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/s', $source, $m, PREG_SET_ORDER);

        return array_map(fn (array $k) => isset($k[2]) && $k[2] !== ''
            ? stripcslashes($k[2])
            : str_replace(['\\\'', '\\\\'], ['\'', '\\'], $k[1]), $m);
    }

    /**
     * @return list<string> as chaves de `t()` no arquivo
     */
    private function jsKeys(string $source): array
    {
        preg_match_all('/\bt\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $source, $m);

        return array_map(fn (string $k) => str_replace(['\\\'', '\\\\'], ['\'', '\\'], $k), $m[1]);
    }

    public function test_the_detector_finds_hardcoded_text_it_exists_to_find(): void
    {
        // Controle positivo: um detector que nao acha nada passaria para
        // sempre, e seria o jeito mais silencioso de a guarda parar de guardar.
        $blade = <<<'BLADE'
            @section('title', 'Placar')
            <h2>Enviar solução</h2>
            <input placeholder="Sua senha">
            <p>{{ $ok ? 'Aceito' : __('Na fila') }}</p>
            <p>{{ __('Traduzido') }}</p>
            <pre translate="no">int main() { return 0; }</pre>
            <span>MicroHelium · S.O.S.</span>
            BLADE;

        $found = $this->hardcodedInBlade($blade);
        sort($found);

        $this->assertSame(['Aceito', 'Enviar solução', 'Placar', 'Sua senha'], $found);

        $vue = "<template><span>Carregando</span><b>{{ t('Ok') }}</b><i :title=\"x ? 'Modo Claro' : t('Escuro')\"></i></template>\n<script>\nconst a = 'Tempo restante'; // Comentário\nconst b = t('Começa em');\n</script>";
        $found = $this->hardcodedInScript('x.vue', $vue);
        sort($found);

        $this->assertSame(['Carregando', 'Modo Claro', 'Tempo restante'], $found);
    }

    public function test_migrated_blade_has_no_hardcoded_interface_text(): void
    {
        $offenders = [];
        foreach (self::BLADE as $path) {
            foreach ($this->hardcodedInBlade($this->read($path)) as $text) {
                $offenders[] = "{$path}: {$text}";
            }
        }

        $this->assertSame([], $offenders, "texto de interface escrito direto num arquivo ja migrado; use __('...') e acrescente a traducao em resources/lang/es.json");
    }

    public function test_migrated_vue_and_js_have_no_hardcoded_interface_text(): void
    {
        $offenders = [];
        foreach (self::SCRIPTS as $path) {
            foreach ($this->hardcodedInScript($path, $this->read($path)) as $text) {
                $offenders[] = "{$path}: {$text}";
            }
        }

        $this->assertSame([], $offenders, "texto de interface escrito direto num componente ja migrado; use t('...') de resources/js/i18n.js e acrescente a traducao em resources/lang/frontend/es.json");
    }

    public function test_every_key_used_in_the_migrated_area_is_translated_to_spanish(): void
    {
        $server = $this->catalog('resources/lang/es.json');
        $browser = $this->catalog('resources/lang/frontend/es.json');

        $phpKeys = [];
        foreach (array_merge(self::BLADE, self::PHP_KEYS_ONLY) as $path) {
            $phpKeys = array_merge($phpKeys, $this->phpKeys($this->read($path)));
        }
        $jsKeys = [];
        foreach (self::SCRIPTS as $path) {
            $jsKeys = array_merge($jsKeys, $this->jsKeys($this->read($path)));
        }

        $this->assertGreaterThan(150, count(array_unique($phpKeys)), 'a extracao de chaves do Blade achou pouco: o regex ou os arquivos mudaram');
        $this->assertGreaterThan(8, count(array_unique($jsKeys)), 'a extracao de chaves do Vue achou pouco');

        // As chaves do Vue precisam estar no catalogo que VAI ao navegador;
        // estar so em es.json seria invisivel para o t().
        $this->assertSame([], array_values(array_diff(array_unique($jsKeys), array_keys($browser))), 'chave usada no Vue sem traducao em resources/lang/frontend/es.json');
        $this->assertSame([], array_values(array_diff(array_unique($phpKeys), array_keys($server + $browser))), 'chave usada no Blade/PHP sem traducao em resources/lang/es.json');

        // Chave orfa e traducao que ninguem mantem; e a mesma chave nos dois
        // arquivos e duas traducoes que podem divergir.
        $this->assertSame([], array_values(array_diff(array_keys($server), $phpKeys)), 'chave em resources/lang/es.json que nenhum arquivo migrado usa');
        $this->assertSame([], array_values(array_diff(array_keys($browser), $jsKeys, $phpKeys)), 'chave em resources/lang/frontend/es.json que nenhum arquivo migrado usa');
        $this->assertSame([], array_values(array_intersect(array_keys($server), array_keys($browser))), 'chave repetida em es.json e frontend/es.json');

        foreach ($server + $browser as $key => $value) {
            $this->assertIsString($value, "traducao de '{$key}' nao e texto");
            $this->assertNotSame('', trim($value), "traducao de '{$key}' esta vazia");
            preg_match_all('/:(\w+)/', $key, $want);
            preg_match_all('/:(\w+)/', $value, $have);
            sort($want[1]);
            sort($have[1]);
            $this->assertSame($want[1], $have[1], "a traducao de '{$key}' perdeu ou inventou um marcador :nome");
        }
    }

    public function test_supported_locales_are_the_ones_that_have_translations(): void
    {
        $config = $this->read('config/app.php');
        preg_match("/'supported_locales' => \[(.*?)\]/s", $config, $m);
        preg_match_all("/'(\w+)' =>/", $m[1] ?? '', $codes);

        $this->assertSame(['pt_BR', 'es'], $codes[1], 'o seletor so oferece idioma com a area migrada traduzida');
        $this->assertFileDoesNotExist($this->root().'/resources/lang/pt_BR.json', 'pt_BR e o idioma-fonte: a chave ja e o texto');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function frameworkFiles(): iterable
    {
        foreach (['pt_BR', 'es'] as $locale) {
            foreach (['auth', 'pagination', 'passwords', 'validation'] as $file) {
                yield "{$locale}/{$file}" => [$locale, $file];
            }
        }
    }

    /**
     * Ate a #397, resources/lang/pt_BR/ era uma copia em ingles de en/, e de
     * uma versao antiga do Laravel. Uma regra nova do framework sem traducao
     * cai no ingles sem ninguem ver -- e isto que reprova.
     */
    #[DataProvider('frameworkFiles')]
    public function test_framework_messages_cover_every_key_of_the_installed_laravel(string $locale, string $file): void
    {
        $english = require $this->root()."/vendor/laravel/framework/src/Illuminate/Translation/lang/en/{$file}.php";
        $translated = require $this->root()."/resources/lang/{$locale}/{$file}.php";

        $flatten = function (array $lines, string $prefix = '') use (&$flatten): array {
            $out = [];
            foreach ($lines as $key => $value) {
                if ($prefix === '' && in_array($key, ['custom', 'attributes'], true)) {
                    continue;
                }
                $out = is_array($value) ? array_merge($out, $flatten($value, "{$prefix}{$key}.")) : array_merge($out, ["{$prefix}{$key}" => $value]);
            }

            return $out;
        };

        $english = $flatten($english);
        $translated = $flatten($translated);

        $this->assertSame([], array_values(array_diff(array_keys($english), array_keys($translated))), "{$locale}/{$file}.php nao tem estas mensagens do framework");

        foreach ($translated as $key => $value) {
            $this->assertNotSame($english[$key] ?? null, $value, "{$locale}/{$file}.php '{$key}' ainda esta em ingles");
        }
    }
}
