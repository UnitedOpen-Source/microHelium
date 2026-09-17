<?php

namespace App\Services;

use App\Models\Contest;
use App\Models\Judgehost;
use App\Models\Run;
use Illuminate\Support\Collection;

/**
 * Issue #196, fase 1 -- "as minhas maquinas sao comparaveis?".
 *
 * O #117/#130 decidiu, de proposito, que hardware heterogeneo e resolvido
 * por politica (maquinas iguais) e que divergencia e AVISADA e nao
 * compensada. A decisao e honesta e deixa um buraco: com maquinas de
 * velocidades diferentes -- o caso quando instituicoes parceiras emprestam o
 * que tem (#53) -- o mesmo limite de tempo e generoso numa e apertado
 * noutra, e a equipe recebe TLE ou AC dependendo de qual maquina pegou o
 * run. Nada no sistema dizia isso.
 *
 * Isto nao muda veredito nenhum. So para de ser invisivel.
 *
 * O MOJ resolve o problema inteiro medindo solucoes de referencia e servindo
 * o MAXIMO entre as maquinas, de forma que a mais lenta define o limite e
 * ninguem e punido por sorteio de fila. Esse e o passo 2 da issue, e ele
 * depende de os pacotes carregarem solucoes de referencia -- o formato BOCA
 * que importamos nao carrega. Enquanto isso, os envios ACEITOS de verdade
 * servem de referencia: sao a mesma solucao, medida em maquinas diferentes,
 * e sao dados que toda prova ja produz.
 */
class JudgehostCalibration
{
    /**
     * Quanto as maquinas divergem entre si, por (problema, linguagem).
     *
     * @return array<string, mixed>
     */
    public function forContest(Contest $contest): array
    {
        $runs = Run::query()
            ->where('contest_id', $contest->id)
            ->whereNotNull('judgehost_id')
            ->whereNotNull('measured_cpu_ms')
            // So os ACEITOS. Um envio que estourou o limite mede o limite e
            // nao a maquina, e um que quebrou no meio mede ate onde chegou
            // -- os dois contaminariam a comparacao com numeros que nao sao
            // sobre velocidade.
            ->whereHas('answer', fn ($query) => $query->where('is_accepted', true))
            ->with(['problem:id,short_name', 'language:id,name'])
            ->get();

        $groups = $runs->groupBy(fn (Run $run) => $run->problem_id.':'.$run->language_id);
        $rows = [];

        foreach ($groups as $group) {
            $row = $this->compare($group);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        // O pior primeiro: quem abre esta tela quer saber se ha um problema,
        // e nao ler uma lista em ordem de id.
        usort($rows, fn (array $a, array $b) => $b['divergence'] <=> $a['divergence']);

        return [
            'threshold' => (float) config('judgehost.calibration.divergence_threshold', 1.5),
            'items' => $rows,
            'machines' => Judgehost::orderBy('name')->get(['id', 'name', 'enabled'])->all(),
            // Issue #273 -- quantas runs entraram na comparacao.
            //
            // Uma tabela vazia responde "nao ha divergencia" e "nao ha
            // medicao" com o mesmo silencio, e as duas pedem reacoes
            // opostas: a primeira e boa noticia, a segunda quer dizer que a
            // comparacao esta cega. Estes numeros sao o que separa as duas.
            //
            // O caso que motivou: por muito tempo o judgehost remoto nao
            // enviava os tempos medidos, entao `medidos` era zero enquanto
            // `aceitos` crescia -- e a tela parecia saudavel.
            'coverage' => [
                'accepted' => $this->acceptedCount($contest),
                'measured' => $runs->count(),
                'hosts_measured' => $runs->pluck('judgehost_id')->unique()->count(),
                'groups' => $groups->count(),
                'groups_comparable' => count($rows),
            ],
        ];
    }

    /**
     * Envios ACEITOS da prova, medidos ou nao.
     *
     * Consulta propria, e de contagem, para nao trazer para a memoria as
     * runs sem medicao: elas nao entram em comparacao nenhuma, so no
     * denominador.
     */
    private function acceptedCount(Contest $contest): int
    {
        return Run::query()
            ->where('contest_id', $contest->id)
            ->whereHas('answer', fn ($query) => $query->where('is_accepted', true))
            ->count();
    }

    /**
     * @param  Collection<int, Run>  $group
     * @return array<string, mixed>|null
     */
    private function compare(Collection $group): ?array
    {
        $byHost = $group->groupBy('judgehost_id');

        // Uma maquina so nao e uma comparacao. Devolver uma linha com
        // divergencia 1.0 aqui encheria a tela de ruido tranquilizador:
        // "nenhuma divergencia" quando o que houve foi nenhuma medicao de
        // comparacao.
        if ($byHost->count() < 2) {
            return null;
        }

        $medians = $byHost->map(fn (Collection $runs) => $this->median(
            $runs->pluck('measured_cpu_ms')->map(fn ($ms) => (int) $ms)->all()
        ));

        $fastest = $medians->min();
        $slowest = $medians->max();

        $first = $group->first();

        return [
            'problem_id' => $first->problem_id,
            'problem' => $first->problem?->short_name,
            'language_id' => $first->language_id,
            'language' => $first->language?->name,
            'samples' => $group->count(),
            // Mediana e nao media: uma maquina que engasgou uma vez arrasta a
            // media e nao arrasta a mediana, e o que se quer saber e como ela
            // se comporta em geral.
            'per_host' => $medians->map(fn (int $ms, $hostId) => [
                'judgehost_id' => (int) $hostId,
                'median_cpu_ms' => $ms,
            ])->values()->all(),
            'fastest_ms' => $fastest,
            'slowest_ms' => $slowest,
            // Quantas vezes a mais lenta demora em relacao a mais rapida. E
            // este numero que responde a pergunta: 1.0 e maquinas iguais,
            // 3.0 e uma equipe recebendo TLE por sorteio de fila.
            'divergence' => $fastest > 0 ? round($slowest / $fastest, 2) : 1.0,
        ];
    }

    /**
     * @param  list<int>  $values
     */
    private function median(array $values): int
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }
}
