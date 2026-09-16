<?php

namespace App\Services;

use App\Jobs\RejudgeMemberJob;
use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\Rejudging;
use App\Models\RejudgingRun;
use App\Models\Run;
use App\Models\Score;
use Helium\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Issue #192 -- montar, prever, aplicar e cancelar um rejulgamento em lote.
 *
 * O cenario nao e rejulgar um envio. E: descobre-se no meio da prova que o
 * caso de teste 7 do problema C esta errado, conserta-se o pacote, e TODOS
 * os envios do problema C precisam voltar para a fila. Com
 * `POST /api/runs/{run}/rejudge` isso e abrir run por run.
 *
 * A previa e o que obriga a forma de conjunto. Nao ha como saber o que muda
 * sem julgar, entao julga-se ANTES de aplicar, guardando o resultado fora
 * do run -- e enquanto a organizacao decide, a equipe continua vendo o
 * veredito que ja via e a classificacao nao se mexe. Aplicar e uma segunda
 * decisao, e cancelar nao deixa rastro no placar.
 */
class RejudgingService
{
    /**
     * Monta o conjunto e manda julgar cada membro em segundo plano.
     *
     * @param  array<string, mixed>  $filters
     */
    public function create(Contest $contest, array $filters, string $reason, bool $includeAccepted, ?User $actor): Rejudging
    {
        $rejudging = Rejudging::create([
            'contest_id' => $contest->id,
            'reason' => $reason,
            'filters' => $filters,
            'include_accepted' => $includeAccepted,
            'status' => Rejudging::STATUS_PREPARING,
            'created_by' => $actor?->user_id,
        ]);

        $runs = $this->select($contest, $filters, $includeAccepted)->get();

        foreach ($runs as $run) {
            RejudgingRun::create([
                'rejudging_id' => $rejudging->id,
                'run_id' => $run->id,
                // O estado ANTES, gravado agora e nao na hora de aplicar: se
                // fosse lido no apply, um run julgado por outra pessoa no
                // meio da decisao entraria como se fosse o estado original,
                // e o "antes/depois" mentiria sobre o que o conjunto fez.
                'old_answer_id' => $run->answer_id,
                'old_status' => $run->status,
                'old_judged_time' => $run->judged_time,
                'old_verified_at' => $run->verified_at,
            ]);
        }

        ContestLog::warning($contest->id, "Rejulgamento em lote #{$rejudging->id} criado: {$runs->count()} envio(s)", [
            'event' => 'rejudging_created',
            'rejudging_id' => $rejudging->id,
            'reason' => $reason,
            'filters' => $filters,
            'include_accepted' => $includeAccepted,
            'run_count' => $runs->count(),
            'user_id' => $actor?->user_id,
        ]);

        // Um conjunto vazio ja nasce decidivel: nao ha nada para julgar, e
        // deixa-lo em `preparing` para sempre esconderia um filtro que nao
        // pegou nada atras de uma barra de progresso que nunca anda.
        if ($runs->isEmpty()) {
            $rejudging->update(['status' => Rejudging::STATUS_READY]);

            return $rejudging;
        }

        foreach ($rejudging->members as $member) {
            RejudgeMemberJob::dispatch($member->id);
        }

        return $rejudging;
    }

    /**
     * Os runs que o criterio seleciona.
     *
     * Os campos sao os que os requisitos de CCS nomeiam -- "submission,
     * problem, language, team, time range, judgement, and judging machine"
     * -- mais a sede, que existe neste esquema e e como uma organizacao
     * multi-sede pensa. `judgehost_id` e do #53 e ja estava la.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Run>
     */
    public function select(Contest $contest, array $filters, bool $includeAccepted): Builder
    {
        $query = Run::query()->where('contest_id', $contest->id);

        $simple = [
            'problem_id' => 'problem_id',
            'language_id' => 'language_id',
            'user_id' => 'user_id',
            'site_id' => 'site_id',
            'judgehost_id' => 'judgehost_id',
            'answer_id' => 'answer_id',
        ];

        foreach ($simple as $key => $column) {
            if (! empty($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }

        if (! empty($filters['run_ids']) && is_array($filters['run_ids'])) {
            $query->whereIn('id', $filters['run_ids']);
        }

        // Intervalo em tempo de prova, em segundos -- a mesma unidade de
        // runs.contest_time. Em horario de parede seria outra coisa: dois
        // envios com o mesmo contest_time podem ter chegado em dias
        // diferentes depois de um rejulgamento ou de uma importacao.
        if (isset($filters['contest_time_from'])) {
            $query->where('contest_time', '>=', (int) $filters['contest_time_from']);
        }

        if (isset($filters['contest_time_to'])) {
            $query->where('contest_time', '<=', (int) $filters['contest_time_to']);
        }

        // Issue #192, item 4. O DOMjudge exige marca explicita de admin para
        // incluir aceitos, porque tirar um AC de uma equipe no meio da prova
        // e a coisa mais cara que um rejulgamento faz -- e quase nunca e o
        // que se queria quando se pediu "rejulgue o problema C".
        if (! $includeAccepted) {
            $query->whereDoesntHave('answer', fn ($q) => $q->where('is_accepted', true));
        }

        return $query;
    }

    /**
     * O que aconteceria se este conjunto fosse aplicado.
     *
     * @return array<string, mixed>
     */
    public function preview(Rejudging $rejudging): array
    {
        $members = $rejudging->members()->with(['run:id,run_number,user_id,problem_id', 'oldAnswer:id,short_name', 'newAnswer:id,short_name'])->get();

        return [
            'total' => $members->count(),
            // So os que produziram veredito. Um membro que falhou
            // terminou, mas nao foi julgado -- conta-lo aqui faria a previa
            // dizer que 300 de 300 estao prontos quando 40 sao erros.
            'judged' => $members->filter(fn (RejudgingRun $m) => $m->isJudged() && $m->error === null)->count(),
            'errors' => $members->whereNotNull('error')->count(),
            'changes' => $members->filter(fn (RejudgingRun $m) => $m->changesVerdict())->count(),
            'items' => $members->map(fn (RejudgingRun $m) => [
                'run_id' => $m->run_id,
                'run_number' => $m->run?->run_number,
                'from' => $m->oldAnswer?->short_name,
                'to' => $m->newAnswer?->short_name,
                'changes' => $m->changesVerdict(),
                'error' => $m->error,
            ])->values()->all(),
        ];
    }

    /**
     * Grava os vereditos novos.
     *
     * Numa transacao porque "aplicavel como conjunto" e a promessa: metade
     * dos runs com veredito novo e metade com o antigo nao e um estado que
     * alguem possa interpretar depois, e seria o estado que sobra se algo
     * falhasse no meio.
     *
     * Os vereditos antigos NAO sao apagados -- ficam nas linhas de
     * rejudging_runs, que e o ponto todo. Hoje o rejulgamento de um run so
     * escreve `answer_id = null` por cima, e nao ha antes/depois nenhum.
     */
    public function apply(Rejudging $rejudging, ?User $actor): void
    {
        DB::transaction(function () use ($rejudging, $actor) {
            $members = $rejudging->members()->with('run')->get();

            foreach ($members as $member) {
                // Um membro que falhou fica como estava. Trocar um veredito
                // real por uma falha de infraestrutura seria a equipe pagando
                // por um defeito nosso -- a mesma razao pela qual
                // ReconcileStuckRunsCommand nao pontua o CS que ele proprio
                // cria.
                if ($member->error !== null || ! $member->isJudged() || ! $member->run) {
                    continue;
                }

                $member->run->update([
                    'status' => 'judged',
                    'answer_id' => $member->new_answer_id,
                    'auto_judge_result' => $member->new_message,
                    'judged_time' => $rejudging->contest?->getContestTime() ?? $member->old_judged_time,
                    // O veredito novo nunca chega pre-aprovado. A assinatura
                    // que liberou o anterior foi dada sobre OUTRO
                    // julgamento, possivelmente contra outros casos de teste
                    // -- e a mesma razao escrita em
                    // Api\RunController::rejudge().
                    'verified_at' => null,
                    'verified_by' => null,
                    'verify_comment' => null,
                ]);
            }

            $rejudging->update([
                'status' => Rejudging::STATUS_APPLIED,
                'applied_at' => now(),
                'applied_by' => $actor?->user_id,
            ]);

            // Recomputar DEPOIS de gravar todos, e nao a cada run.
            //
            // Score::recomputeFor() e uma funcao pura dos runs que contam
            // (#171), entao recompor celula por celula no meio do laco
            // produziria estados intermediarios -- uma equipe sem o AC que
            // vai voltar duas linhas adiante -- e cada um deles dispara
            // balao e recalculo de classificacao. Uma celula por vez, no
            // fim, da o mesmo resultado sem os fantasmas.
            $this->recomputeAffectedCells($members);
        });

        $changed = $rejudging->members()->get()->filter(fn (RejudgingRun $m) => $m->changesVerdict())->count();

        ContestLog::warning($rejudging->contest_id, "Rejulgamento em lote #{$rejudging->id} aplicado: {$changed} veredito(s) alterado(s)", [
            'event' => 'rejudging_applied',
            'rejudging_id' => $rejudging->id,
            'reason' => $rejudging->reason,
            'changed' => $changed,
            'user_id' => $actor?->user_id,
        ]);
    }

    /**
     * Joga o conjunto fora sem tocar em run nenhum.
     *
     * Nada para desfazer, e esse e o desenho: enquanto o conjunto nao foi
     * aplicado, os runs nunca sairam do estado em que estavam.
     */
    public function cancel(Rejudging $rejudging, ?User $actor): void
    {
        $rejudging->update([
            'status' => Rejudging::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $actor?->user_id,
        ]);

        ContestLog::info($rejudging->contest_id, "Rejulgamento em lote #{$rejudging->id} cancelado", [
            'event' => 'rejudging_cancelled',
            'rejudging_id' => $rejudging->id,
            'user_id' => $actor?->user_id,
        ]);
    }

    /**
     * Marca o conjunto como decidivel quando o ultimo membro terminar.
     */
    public function refreshReadiness(Rejudging $rejudging): void
    {
        if ($rejudging->status !== Rejudging::STATUS_PREPARING) {
            return;
        }

        $pending = $rejudging->members()->whereNull('judged_at')->whereNull('error')->count();

        if ($pending === 0) {
            $rejudging->update(['status' => Rejudging::STATUS_READY]);
        }
    }

    /**
     * Uma recomposicao por celula (contest, equipe, problema) tocada.
     *
     * @param  Collection<int, RejudgingRun>  $members
     */
    private function recomputeAffectedCells($members): void
    {
        $seen = [];

        foreach ($members as $member) {
            $run = $member->run;

            if (! $run) {
                continue;
            }

            $key = $run->user_id.':'.$run->problem_id;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            Score::updateScore($run->fresh());
        }
    }
}
