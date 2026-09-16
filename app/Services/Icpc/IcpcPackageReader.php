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
            // `limits.time_limit` vem em SEGUNDOS e ja e o limite final --
            // nao o tempo da solucao de referencia. O #196 e quem vai medir.
            'time_limit' => (int) ceil((float) ($meta['limits']['time_limit'] ?? 1)),
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
