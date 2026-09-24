<?php

namespace App\Services;

use App\Models\Contest;
use App\Models\Leaderboard;
use Helium\User;
use Illuminate\Support\Collection;

/**
 * Issue #212 -- quem aparece no placar.
 *
 * Antes disto a resposta era "quem ja teve um run julgado", e ninguem tinha
 * decidido isso: a linha de `leaderboard` nasce em exatamente um lugar,
 * Leaderboard::updateForUser(), chamado no fim de Score::recomputeFor() --
 * que so roda a partir de Score::updateScore(), que comeca com
 * `if (! $run->isJudged()) return;`. E getScoreboard() iterava as linhas de
 * `leaderboard`.
 *
 * O efeito numa prova: nos primeiros minutos a tela esta praticamente
 * vazia, e uma equipe que submeteu e esta esperando julgamento nao se
 * encontra nela. Do ponto de vista dela a submissao sumiu -- o mesmo
 * sintoma que o #199 descreve, na tela onde ela iria procurar confirmacao.
 *
 * O conjunto e a UNIAO de duas coisas, e a uniao e deliberada:
 *
 *  - as equipes do contest, que e a resposta certa;
 *  - quem ja tem linha de `leaderboard`, que e a resposta antiga.
 *
 * So a primeira metade seria uma mudanca que REMOVE gente da tela, e
 * ninguem pediu isso: uma conta cujas colunas de vinculo estao estranhas,
 * mas que tem pontuacao registrada, esta visivelmente participando. Tirar
 * uma linha do placar e uma decisao com consequencia; acrescentar as que
 * faltavam nao e.
 */
class ScoreboardTeams
{
    /**
     * @return Collection<int, User> indexada por user_id
     */
    public static function forContest(Contest $contest): Collection
    {
        $competitors = self::competitors($contest);

        $scored = User::query()
            ->whereIn('user_id', Leaderboard::where('contest_id', $contest->id)->pluck('user_id'))
            ->get()
            ->keyBy('user_id');

        return $competitors->union($scored);
    }

    /**
     * As equipes deste contest.
     *
     * Os dois caminhos de vinculo, e nao um: no esquema do BOCA uma conta
     * chega a um contest pela propria coluna ou pela sede, e
     * Contest::memberContestIds() ja enfrentou exatamente esta pergunta --
     * do outro lado, partindo do usuario -- e respondeu "qualquer um dos
     * dois", com o motivo escrito. Ler so `users.contest_id` deixaria de
     * fora a equipe cuja inscricao preencheu a sede.
     *
     * Perfis que nao competem ficam de fora: um juiz no placar seria uma
     * linha que nunca pontua, e um admin ali seria pior -- a tela e publica.
     * Conta desabilitada tambem nao entra: ela nao pode nem submeter.
     *
     * @return Collection<int, User>
     */
    private static function competitors(Contest $contest): Collection
    {
        $siteIds = $contest->sites()->pluck('id');

        return User::query()
            ->where('user_type', User::TYPE_TEAM)
            // Issue #395 -- conta anonimizada e desabilitada, mas competiu:
            // tira-la daqui tiraria do placar a equipe sem envio julgado
            // (que nao tem linha em `leaderboard` para voltar pela uniao).
            // Atender um pedido de exclusao nao pode mudar a classificacao.
            ->where(fn ($query) => $query->where('is_enabled', true)->orWhereNotNull('anonymized_at'))
            ->where(function ($query) use ($contest, $siteIds) {
                $query->where('contest_id', $contest->id)
                    ->when($siteIds->isNotEmpty(), fn ($q) => $q->orWhereIn('site_id', $siteIds));
            })
            ->get()
            ->keyBy('user_id');
    }
}
