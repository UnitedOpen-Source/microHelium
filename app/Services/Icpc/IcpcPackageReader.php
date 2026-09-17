<?php

namespace App\Services\Icpc;

use Symfony\Component\Yaml\Yaml;

/**
 * Issue #200 -- le um pacote no formato de problema da ICPC/Kattis.
 *
 * O importador que existia le SO o formato do BOCA (`description/`,
 * `input/`, `output/`, `limits/`, `compare/`). Conferido: `problem.yaml`,
 * `domjudge-problem.ini` e `testdata.yaml` nao apareciam em lugar nenhum de
 * app/.
 *
 * O formato da ICPC/Kattis e o que o resto do mundo produz e consome -- o
 * ICPC Problem Archive publica nele, o Polygon exporta para ele, e
 * problemtools, BAPCtools, Kattis, DOMjudge, PC^2, CMS e Omogenjudge leem
 * ele. Os requisitos de CCS o exigem como piso: "The CCS MUST support the
 * ICPC subset". Hoje um problema preparado no Polygon precisa ser convertido
 * a mao.
 *
 * Esta classe SO LE. Traduzir para o que o sistema usa e o trabalho do
 * IcpcPackageImporter -- separados porque validar um pacote e uma operacao
 * que vale sozinha (dizer o que esta errado antes de escrever qualquer
 * coisa no banco).
 */
class IcpcPackageReader
{
    /**
     * As versoes que este leitor entende.
     *
     * `legacy-icpc` e o piso obrigatorio para CCS, e e por onde comecar. A
     * `2025-09` RENOMEIA DIRETORIOS (problem_statement/ -> statement/,
     * output_validators/ -> output_validator/) e remove chaves do
     * problem.yaml -- ler um pacote 2025-09 com as regras do legacy nao da
     * erro, da um problema sem enunciado e sem validador, em silencio. Por
     * isso a versao e DETECTADA e a desconhecida e recusada, em vez de
     * adivinhada pela estrutura.
     */
    public const SUPPORTED_VERSIONS = ['legacy', 'legacy-icpc'];

    /**
     * @return array<string, mixed>
     *
     * @throws IcpcPackageException
     */
    public function read(string $root): array
    {
        $root = rtrim($root, '/');
        $base = $this->locateRoot($root);
        $yamlPath = $base.'/problem.yaml';

        if (! is_file($yamlPath)) {
            throw new IcpcPackageException('Este pacote nao tem problem.yaml -- nao e um pacote no formato da ICPC/Kattis.');
        }

        $meta = Yaml::parseFile($yamlPath);
        $meta = is_array($meta) ? $meta : [];

        $version = (string) ($meta['problem_format_version'] ?? 'legacy');

        if (! in_array($version, self::SUPPORTED_VERSIONS, true)) {
            throw new IcpcPackageException(
                "Versao de formato \"{$version}\" nao suportada. Este importador le ".
                implode(' e ', self::SUPPORTED_VERSIONS).
                '; a 2025-09 renomeia diretorios e seria lida errada em silencio.'
            );
        }

        $type = (string) ($meta['type'] ?? 'pass-fail');

        // Fora de escopo nesta fase, e recusado em voz alta em vez de
        // importado pela metade: um problema interativo importado como
        // pass-fail vira um problema que julga errado, e o sintoma aparece
        // na prova.
        if ($type !== 'pass-fail') {
            throw new IcpcPackageException("Tipo de problema \"{$type}\" fora de escopo. Esta fase importa apenas pass-fail.");
        }

        $cases = $this->testCases($base);

        if ($cases['secret'] === []) {
            throw new IcpcPackageException('O pacote nao tem nenhum caso de teste em data/secret/.');
        }

        return [
            'root' => $base,
            'version' => $version,
            'name' => $this->name($meta),
            'uuid' => $meta['uuid'] ?? null,
            'license' => $meta['license'] ?? 'unknown',
            'time_limit' => $this->timeLimit($base, $meta),
            // `limits.memory` vem em MiB.
            'memory_limit' => (int) ($meta['limits']['memory'] ?? 256),
            'output_limit' => (int) ($meta['limits']['output'] ?? 8),
            'validation' => (string) ($meta['validation'] ?? 'default'),
            'samples' => $cases['sample'],
            'secret' => $cases['secret'],
            'output_validators' => $this->outputValidators($base),
            'statement' => $this->statement($base),
            'accepted_submissions' => $this->acceptedSubmissions($base),
        ];
    }

    /**
     * O diretorio que realmente contem o pacote.
     *
     * Um ZIP do Polygon costuma ter tudo dentro de uma pasta com o nome do
     * problema, e um do ICPC Problem Archive costuma nao ter. Descer um
     * nivel quando o problem.yaml nao esta na raiz evita que a diferenca
     * entre duas ferramentas legitimas vire "pacote invalido".
     */
    private function locateRoot(string $root): string
    {
        if (is_file($root.'/problem.yaml')) {
            return $root;
        }

        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $candidate) {
            if (is_file($candidate.'/problem.yaml')) {
                return $candidate;
            }
        }

        return $root;
    }

    /**
     * O nome do problema.
     *
     * `name` pode ser uma string ou um mapa de idioma para nome (o formato
     * permite os dois). Preferimos portugues, depois ingles, depois o
     * primeiro que houver -- e nao "o primeiro" direto, porque a ordem de um
     * mapa YAML nao e uma escolha de quem escreveu.
     */
    private function name(array $meta): string
    {
        $name = $meta['name'] ?? null;

        if (is_string($name) && $name !== '') {
            return $name;
        }

        if (is_array($name) && $name !== []) {
            foreach (['pt_BR', 'pt', 'en'] as $language) {
                if (isset($name[$language]) && is_string($name[$language])) {
                    return $name[$language];
                }
            }

            $first = reset($name);

            if (is_string($first)) {
                return $first;
            }
        }

        return 'Problema importado';
    }

    /**
     * @return array{sample: list<array{in: string, ans: string, name: string}>, secret: list<array{in: string, ans: string, name: string}>}
     */
    private function testCases(string $base): array
    {
        return [
            'sample' => $this->casesIn($base.'/data/sample'),
            'secret' => $this->casesIn($base.'/data/secret'),
        ];
    }

    /**
     * Os casos de um diretorio, inclusive em subpastas.
     *
     * `data/secret/<grupo>/` e como o formato organiza grupos de teste.
     * Pontuacao parcial por grupo esta fora de escopo (e modelo de IOI, nao
     * de ICPC), mas os ARQUIVOS precisam ser lidos de qualquer forma -- um
     * pacote agrupado importado sem descer nas subpastas viraria um problema
     * com zero casos de teste, e um problema com zero casos aceita tudo.
     *
     * @return list<array{in: string, ans: string, name: string}>
     */
    private function casesIn(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'in') {
                continue;
            }

            $answer = substr($file->getPathname(), 0, -3).'.ans';

            // Um `.in` sem `.ans` nao e um caso de teste; e metade de um. O
            // formato exige o par.
            if (! is_file($answer)) {
                continue;
            }

            $found[] = [
                'in' => $file->getPathname(),
                'ans' => $answer,
                'name' => trim(str_replace($dir, '', substr($file->getPathname(), 0, -3)), '/'),
            ];
        }

        // Ordem estavel: o numero do caso de teste acaba no banco, e um
        // pacote importado duas vezes tem que dar a mesma numeracao.
        usort($found, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return $found;
    }

    /**
     * @return list<string>
     */
    private function outputValidators(string $base): array
    {
        $dir = $base.'/output_validators';

        if (! is_dir($dir)) {
            return [];
        }

        return array_values(array_filter(glob($dir.'/*') ?: [], 'is_dir'));
    }

    private function statement(string $base): ?string
    {
        foreach (['pt_BR', 'pt', 'en'] as $language) {
            $path = $base."/problem_statement/problem.{$language}.pdf";

            if (is_file($path)) {
                return $path;
            }
        }

        $any = glob($base.'/problem_statement/*.pdf') ?: [];

        return $any === [] ? null : $any[0];
    }

    /**
     * De onde sai o limite de tempo de um pacote LEGACY -- issue #251.
     *
     * Aqui estava o mesmo erro que o docblock desta classe se preocupa em
     * evitar, so que na direcao contraria. A 2025-09 e recusada porque ler um
     * pacote de uma versao com as regras de outra "nao da erro, da um problema
     * sem enunciado e sem validador, em silencio" -- e este leitor lia
     * `limits.time_limit`, que e chave DA 2025-09, com `?? 1` quando ausente.
     *
     * No formato legacy essa chave nao existe: a especificacao tem
     * `time_multiplier` (padrao 5) e `time_safety_margin` (padrao 2), e o
     * limite e DERIVADO das solucoes de referencia. Entao todo pacote legacy
     * de verdade caia no `?? 1`: um segundo, em silencio, para qualquer
     * problema. Um segundo reprova por tempo quase toda solucao correta, e o
     * defeito nao aparece na importacao -- aparece na prova.
     *
     * Onde o limite mora de verdade, em ordem:
     *
     *  1. `domjudge-problem.ini`, chave `timelimit`. A documentacao do
     *     DOMjudge descreve: "timelimit - time limit in seconds per test
     *     case". Vem primeiro porque o DOMjudge le o `.timelimit` "if it
     *     exists and is not overwritten in domjudge-problem.ini".
     *  2. o arquivo `.timelimit` na raiz -- convencao do problemtools, nao
     *     oficial, mas produzida por boa parte das ferramentas.
     *  3. `limits.time_limit`, tolerado. Nao e do legacy, mas exportadores
     *     emitem mesmo assim, e recusar um pacote que DIZ o limite seria
     *     trocar um silencio por uma teimosia.
     *
     * Sem nenhum dos tres o limite teria que ser derivado, e derivar e a fase
     * 2 do #196, que nao existe -- ver
     * docs/specs/251-limite-de-tempo-derivado.md. Entao a importacao e
     * recusada em voz alta, como ja acontece com problema interativo e com
     * versao de formato nao suportada. Escolher um numero aqui e escolher
     * vereditos.
     *
     * @param  array<string, mixed>  $meta
     *
     * @throws IcpcPackageException
     */
    private function timeLimit(string $base, array $meta): int
    {
        $candidatos = [
            $this->iniTimeLimit($base),
            $this->dotTimeLimit($base),
            $meta['limits']['time_limit'] ?? null,
        ];

        foreach ($candidatos as $bruto) {
            if ($bruto === null || ! is_numeric($bruto) || (float) $bruto <= 0) {
                continue;
            }

            // Para CIMA, sempre. A coluna e inteira, e arredondar para baixo
            // aperta o limite: reprovaria por tempo uma solucao que o proprio
            // pacote considera correta.
            return (int) ceil((float) $bruto);
        }

        throw new IcpcPackageException(
            'Este pacote nao diz o limite de tempo. O formato legacy o deriva das solucoes de '.
            'referencia, e derivar ainda nao existe aqui (issue #251). Ponha `timelimit` em '.
            'domjudge-problem.ini, ou o valor em segundos num arquivo .timelimit na raiz, ou '.
            '`limits.time_limit` no problem.yaml.'
        );
    }

    /**
     * `chave = valor` por linha, sem secoes -- nao e INI de verdade, entao
     * `parse_ini_file` nao serve: ele engasga com valor sem aspas que tenha
     * caractere reservado, e o arquivo vem de ferramenta de terceiro.
     */
    private function iniTimeLimit(string $base): ?string
    {
        $caminho = $base.'/domjudge-problem.ini';

        if (! is_file($caminho)) {
            return null;
        }

        if (preg_match('/^[ \t]*timelimit[ \t]*=[ \t]*([^\r\n]+)/mi', (string) file_get_contents($caminho), $m) !== 1) {
            return null;
        }

        $valor = trim($m[1], " \t\"'");

        return $valor === '' ? null : $valor;
    }

    private function dotTimeLimit(string $base): ?string
    {
        $caminho = $base.'/.timelimit';

        if (! is_file($caminho)) {
            return null;
        }

        $conteudo = trim((string) file_get_contents($caminho));

        return $conteudo === '' ? null : $conteudo;
    }

    /**
     * As solucoes de referencia aceitas.
     *
     * Guardadas mesmo sem uso imediato: sao o pre-requisito do #196, que
     * quer medir o limite de tempo em vez de aceitar um numero digitado. Sem
     * elas, aquela issue nao tem o que medir.
     *
     * @return list<string>
     */
    private function acceptedSubmissions(string $base): array
    {
        $dir = $base.'/submissions/accepted';

        if (! is_dir($dir)) {
            return [];
        }

        return array_values(array_filter(glob($dir.'/*') ?: [], 'is_file'));
    }
}
