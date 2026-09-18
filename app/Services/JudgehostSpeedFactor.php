<?php

namespace App\Services;

use App\Models\Contest;
use App\Models\Run;
use Illuminate\Support\Collection;

/**
 * Issue #251 (#196, fase 2) -- o limite de tempo servido POR MÁQUINA.
 *
 * A fase 1 (#196/#230) mede e avisa: `JudgehostCalibration` diz se as
 * máquinas são comparáveis, e para por aí. O limite continuava digitado à
 * mão, um valor por problema, igual para todas — e com judgehosts de
 * velocidades diferentes, que é o caso quando instituições parceiras
 * emprestam o que têm (#53), a mesma solução recebe TLE numa máquina e AC
 * noutra. A fase 1 põe isso na tela; a equipe que competiu já levou o
 * veredito.
 *
 * ## A forma, decidida pelo mantenedor
 *
 * **Teto e piso em torno do valor digitado**, e não substituição:
 *
 *     efetivo = clamp(digitado * fator, digitado * piso, digitado * teto)
 *
 * O valor do problema continua sendo a referência. A medição só o ajusta
 * dentro de uma faixa, então nenhuma máquina fica livre para inventar o
 * próprio limite, e o #117/#130 é preservado em espírito: a divergência
 * passa a ser compensada, mas **limitada**.
 *
 * ## De onde sai o fator, e por que não de solução de referência
 *
 * A issue previa que o pacote passasse a carregar `sols/` e que a máquina
 * medisse a referência. Não é preciso: desde o #273 toda prova já produz a
 * medição, nos **envios aceitos de verdade** — a mesma solução, medida em
 * máquinas diferentes. É a mesma fonte que a fase 1 usa para avisar, e usá-la
 * aqui significa zero mudança de formato de pacote e zero execução nova.
 *
 * O fator de um host é a **mediana das razões** dele contra a mediana dos
 * hosts, por (problema, linguagem):
 *
 *     razão_do_host_no_grupo = mediana(host) / mediana(medianas dos hosts)
 *     fator = mediana das razões do host, em todos os grupos comparáveis
 *
 * Mediana e não média, duas vezes, pelo mesmo motivo escrito na fase 1: uma
 * máquina que engasgou uma vez arrasta a média e não arrasta a mediana, e o
 * que se quer saber é como ela se comporta em geral.
 *
 * Um host mais LENTO tem razão > 1 e ganha limite maior; um mais RÁPIDO tem
 * razão < 1 e recebe limite menor. As duas direções são o ponto: o que se
 * iguala é a DIFICULDADE, e não o número de segundos.
 *
 * ## Quem não tem medição
 *
 * Fator **1.0** — o valor digitado, sem ajuste. É a resposta segura, e é a
 * mesma para os três casos que produzem isso: máquina recém-registrada,
 * prova que ainda não teve envio aceito, e julgamento local sem
 * `judgehost_id`. A tela de calibração já avisa quando uma máquina está sem
 * medição (#273), então isto não fica invisível.
 */
class JudgehostSpeedFactor
{
    /** @var array<string, array<int, float>> */
    private array $cache = [];

    public const NEUTRO = 1.0;

    /**
     * O limite efetivo, em segundos, para esta máquina.
     *
     * Arredondado para cima e nunca menor que 1: um limite de zero segundos
     * reprovaria tudo, e um fracionário não cabe no `ulimit -t`, que conta em
     * segundos inteiros.
     */
    public function effectiveSeconds(?Contest $contest, int $typedSeconds, ?int $judgehostId): int
    {
        // Contest nulo quer dizer "quem esta perguntando nao tem banco", e a
        // resposta certa e NAO AJUSTAR.
        //
        // Existe porque o ajuste acontece UMA VEZ, na entrega do trabalho, e
        // nao no julgamento. Tentei aplicar tambem em
        // `AutoJudgeService::executeProgram()` e estava errado por tres
        // motivos, todos medidos:
        //
        //   1. o julgamento LOCAL nunca tem `judgehost_id` -- o daemon local
        //      chama `claimNextLocally(null)` e a coluna fica nula --, entao
        //      o fator seria sempre 1.0 e a linha, codigo morto;
        //   2. no judgehost REMOTO o limite ja chegou ajustado no payload, e
        //      ajustar de novo aplicaria o fator DUAS VEZES;
        //   3. ler `$run->contest` ali e uma consulta preguicosa, e o
        //      judgehost nao tem credencial de banco (#116) --
        //      `JudgeWithoutDatabaseTest` derrubou com "Judging reached the
        //      database".
        if ($contest === null) {
            return max(1, $typedSeconds);
        }

        $factor = $this->forHost($contest, $judgehostId);

        $floor = (float) config('judgehost.calibration.limit_floor', 0.5);
        $ceiling = (float) config('judgehost.calibration.limit_ceiling', 2.0);

        $adjusted = $typedSeconds * $factor;
        $bounded = min(max($adjusted, $typedSeconds * $floor), $typedSeconds * $ceiling);

        return max(1, (int) ceil($bounded));
    }

    /**
     * Quão lenta esta máquina é, comparada à mediana das máquinas.
     */
    public function forHost(?Contest $contest, ?int $judgehostId): float
    {
        if ($contest === null || $judgehostId === null) {
            return self::NEUTRO;
        }

        return $this->factors($contest)[$judgehostId] ?? self::NEUTRO;
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /**
     * @return array<int, float> judgehost_id => fator
     */
    private function factors(Contest $contest): array
    {
        $chave = (string) $contest->id;

        if (array_key_exists($chave, $this->cache)) {
            return $this->cache[$chave];
        }

        $runs = Run::query()
            ->where('contest_id', $contest->id)
            ->whereNotNull('judgehost_id')
            ->whereNotNull('measured_cpu_ms')
            // Só os ACEITOS, pela mesma razão da fase 1: um envio que
            // estourou o limite mede o LIMITE e não a máquina, e um que
            // quebrou no meio mede até onde chegou.
            ->whereHas('answer', fn ($query) => $query->where('is_accepted', true))
            ->get(['judgehost_id', 'problem_id', 'language_id', 'measured_cpu_ms']);

        /** @var array<int, list<float>> $razoes */
        $razoes = [];

        foreach ($runs->groupBy(fn (Run $run) => $run->problem_id.':'.$run->language_id) as $grupo) {
            $porHost = $grupo->groupBy('judgehost_id');

            // Uma máquina só não é comparação -- a razão dela contra si
            // mesma é 1.0, e encher a lista disso empurraria todo fator para
            // o neutro. Mesma recusa que `JudgehostCalibration::compare()`.
            if ($porHost->count() < 2) {
                continue;
            }

            $medianas = $porHost->map(fn (Collection $r) => $this->median(
                $r->pluck('measured_cpu_ms')->map(fn ($ms) => (float) $ms)->all()
            ));

            $baseline = $this->median($medianas->values()->all());

            if ($baseline <= 0.0) {
                continue;
            }

            foreach ($medianas as $hostId => $mediana) {
                $razoes[(int) $hostId][] = $mediana / $baseline;
            }
        }

        $fatores = [];

        foreach ($razoes as $hostId => $lista) {
            $fatores[$hostId] = $this->median($lista);
        }

        return $this->cache[$chave] = $fatores;
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $meio = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? $values[$meio]
            : ($values[$meio - 1] + $values[$meio]) / 2;
    }
}
