<?php

namespace App\Services\Icpc;

use App\Models\Contest;
use App\Models\Problem;
use App\Models\TestCase;
use Illuminate\Support\Str;

/**
 * Issue #200 -- traduz um pacote da ICPC/Kattis para o que o sistema usa.
 *
 * IMPORTAR, E NAO ADOTAR. O formato interno continua sendo o do BOCA
 * (`input/`, `output/`, `description/`, `compare/`); este importador escreve
 * nele. Trocar o formato interno seria outra issue, muito maior, e nao e o
 * que falta: o que falta e conseguir receber o que o resto do mundo produz.
 */
class IcpcPackageImporter
{
    public function __construct(private IcpcPackageReader $reader) {}

    /**
     * @throws IcpcPackageException
     */
    public function import(Contest $contest, string $extractedPath, array $overrides = []): Problem
    {
        $package = $this->reader->read($extractedPath);

        $basename = $overrides['basename'] ?? Str::slug($package['name']) ?: 'problema';
        $basename = $this->uniqueBasename($contest, $basename);

        $problem = Problem::create([
            'contest_id' => $contest->id,
            'short_name' => $overrides['short_name'] ?? $this->nextShortName($contest),
            'name' => $overrides['name'] ?? $package['name'],
            'basename' => $basename,
            'time_limit' => $package['time_limit'],
            'memory_limit' => $package['memory_limit'],
            'output_limit' => $package['output_limit'] * 1024,
            'auto_judge' => true,
            'sort_order' => (int) Problem::where('contest_id', $contest->id)->max('sort_order') + 1,
        ]);

        $target = storage_path("app/problems/{$contest->id}/{$basename}");
        $this->layOut($package, $problem, $target);

        return $problem->fresh();
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function layOut(array $package, Problem $problem, string $target): void
    {
        foreach (['input', 'output', 'description', 'compare', 'submissions'] as $dir) {
            @mkdir("{$target}/{$dir}", 0o755, true);
        }

        $number = 1;

        // Os de amostra primeiro, e numerados junto com os demais: o campo
        // `is_sample` e o que distingue, e nao a numeracao. Numerar em
        // sequencias separadas faria dois casos com o mesmo numero no mesmo
        // problema, que a coluna unica recusa.
        foreach ([[$package['samples'], true], [$package['secret'], false]] as [$cases, $isSample]) {
            foreach ($cases as $case) {
                $name = sprintf('%03d', $number);

                copy($case['in'], "{$target}/input/{$name}");
                copy($case['ans'], "{$target}/output/{$name}");

                TestCase::create([
                    'problem_id' => $problem->id,
                    'number' => $number,
                    'input_file' => "problems/{$problem->contest_id}/{$problem->basename}/input/{$name}",
                    'output_file' => "problems/{$problem->contest_id}/{$problem->basename}/output/{$name}",
                    'input_hash' => hash_file('sha256', $case['in']),
                    'output_hash' => hash_file('sha256', $case['ans']),
                    // Issue #200 -- `data/sample/` e exatamente o que
                    // `is_sample` ja significa aqui. O importador do BOCA
                    // chutava "os dois primeiros sao amostra"; o formato da
                    // ICPC diz qual e qual.
                    'is_sample' => $isSample,
                ]);

                $number++;
            }
        }

        if ($package['statement'] !== null) {
            $file = basename($package['statement']);
            copy($package['statement'], "{$target}/description/{$file}");
            $problem->update(['description_file' => $file]);
        }

        foreach ($package['accepted_submissions'] as $solution) {
            @mkdir("{$target}/submissions/accepted", 0o755, true);
            copy($solution, "{$target}/submissions/accepted/".basename($solution));
        }

        $this->writeValidatorShim($package, $target);
    }

    /**
     * O tradutor entre os dois contratos de validador.
     *
     * Eles nao se parecem, e e por isso que a issue diz "traduzir, nao
     * assumir":
     *
     *   ICPC    validator <entrada> <resposta> <dir_feedback> < saida_da_equipe
     *           saida 42 = aceito, 43 = errado, outra coisa = validador
     *           quebrado
     *
     *   aqui    bash compare/<ext> <entrada> <esperado> <obtido>
     *           saida 0 = aceito, qualquer outra = errado
     *
     * Tres diferencas, e cada uma sozinha basta para julgar errado: a saida
     * da equipe vai por STDIN e nao por argumento; o terceiro argumento e um
     * DIRETORIO e nao um arquivo; e 43 (errado) seria lido como "errado"
     * aqui por acidente, mas 42 (ACEITO) tambem seria -- porque aqui
     * qualquer coisa diferente de zero e errado. Um validador da ICPC ligado
     * direto reprovaria toda submissao correta.
     *
     * O shim e escrito para cada linguagem que o problema aceita? Nao: um so,
     * porque o contrato daqui e por extensao mas o validador e do PROBLEMA e
     * nao da linguagem. O arquivo `compare/default` cobre o caso; ver
     * Problem::getCompareScriptPath().
     *
     * @param  array<string, mixed>  $package
     */
    private function writeValidatorShim(array $package, string $target): void
    {
        if ($package['output_validators'] === []) {
            return;
        }

        $validatorDir = $package['output_validators'][0];
        $this->copyTree($validatorDir, "{$target}/compare/validator");

        $shim = <<<'SH'
#!/usr/bin/env bash
# Issue #200 -- traduz o contrato do validador da ICPC para o daqui.
#
#   ICPC: validator <entrada> <resposta> <dir_feedback> < saida_da_equipe
#         42 = aceito, 43 = errado
#   aqui: compare <entrada> <esperado> <obtido>, 0 = aceito
#
# A traducao e obrigatoria e nao cosmetica: aqui QUALQUER saida diferente de
# zero e "errado", entao um validador da ICPC ligado direto reprovaria toda
# submissao correta -- 42 nao e zero.
set -u

entrada="$1"
esperado="$2"
obtido="$3"

feedback="$(mktemp -d)"
trap 'rm -rf "$feedback"' EXIT

validador="$(dirname "$0")/validator/run"
if [ ! -x "$validador" ]; then
    # Sem executavel nao ha validacao: sair diferente de zero reprovaria
    # todo mundo, e sair zero aprovaria todo mundo. 2 e "nao consegui
    # decidir", e o julgamento trata como erro em vez de veredito.
    echo "validador de saida ausente ou sem permissao de execucao" >&2
    exit 2
fi

"$validador" "$entrada" "$esperado" "$feedback" < "$obtido"
codigo=$?

case "$codigo" in
    42) exit 0 ;;
    43) exit 1 ;;
    # Qualquer outra coisa e o validador quebrado, e nao um veredito sobre a
    # equipe. Distinguir importa: reprovar a equipe por um defeito nosso e a
    # mesma injustica que o #45 evita ao nao pontuar o CS que ele proprio
    # cria.
    *)  echo "validador de saida terminou com codigo inesperado: $codigo" >&2
        exit 2 ;;
esac
SH;

        file_put_contents("{$target}/compare/default", $shim);
        chmod("{$target}/compare/default", 0o755);
    }

    private function copyTree(string $source, string $destination): void
    {
        @mkdir($destination, 0o755, true);

        foreach (glob($source.'/*') ?: [] as $path) {
            $leaf = $destination.'/'.basename($path);

            if (is_dir($path)) {
                $this->copyTree($path, $leaf);

                continue;
            }

            copy($path, $leaf);

            // O bit de execucao do validador tem que sobreviver a copia: um
            // `run` sem ele faz o shim sair 2 em todo caso de teste, e o
            // problema inteiro vira erro de julgamento.
            if (is_executable($path)) {
                chmod($leaf, 0o755);
            }
        }
    }

    private function uniqueBasename(Contest $contest, string $basename): string
    {
        $candidate = $basename;
        $suffix = 2;

        while (Problem::where('contest_id', $contest->id)->where('basename', $candidate)->exists()) {
            $candidate = $basename.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function nextShortName(Contest $contest): string
    {
        $count = Problem::where('contest_id', $contest->id)->count();
        $name = '';
        $index = $count;

        do {
            $name = chr(65 + $index % 26).$name;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $name;
    }
}
