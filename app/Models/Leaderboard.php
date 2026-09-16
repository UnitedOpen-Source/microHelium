<?php

namespace App\Models;

use App\Services\FrozenScoreboard;
use App\Services\ScoreboardRanking;
use App\Services\ScoreboardTeams;
use Helium\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Leaderboard extends Model
{
    use HasFactory;

    protected $table = 'leaderboard';

    protected $fillable = [
        'contest_id',
        'user_id',
        'problems_solved',
        'total_time',
        'rank',
    ];

    /** @return BelongsTo<Contest, $this> */
    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public static function updateForUser(int $contestId, int $userId): void
    {
        $stats = Score::where('contest_id', $contestId)
            ->where('user_id', $userId)
            ->where('is_solved', true)
            ->selectRaw('COUNT(*) as solved, SUM(solved_time + penalty_time) as total_time')
            ->first();

        self::updateOrCreate(
            ['contest_id' => $contestId, 'user_id' => $userId],
            [
                'problems_solved' => $stats->solved ?? 0,
                'total_time' => $stats->total_time ?? 0,
            ]
        );

        self::recalculateRanks($contestId);
    }

    public static function recalculateRanks(int $contestId): void
    {
        $entries = self::where('contest_id', $contestId)
            ->orderByDesc('problems_solved')
            ->orderBy('total_time')
            ->get();

        // Issue #212 -- a mesma funcao que o placar usa para ordenar.
        //
        // A regra estava escrita aqui e, depois do #211, tambem no placar
        // congelado; com o placar ao vivo passando a calcular posicao,
        // seriam tres copias. Tres formulacoes que por acaso concordam sao
        // como a proxima pessoa muda uma e esquece as outras.
        $ranked = ScoreboardRanking::apply(
            $entries->map(fn (self $entry) => [
                'id' => $entry->id,
                'problems_solved' => (int) $entry->problems_solved,
                'total_time' => (int) $entry->total_time,
            ])->all()
        );

        $byId = $entries->keyBy('id');

        foreach ($ranked as $row) {
            $entry = $byId->get($row['id']);
            $entry->rank = $row['rank'];
            $entry->save();
        }
    }

    /**
     * Issue #211 -- `$frozen` agora e lido.
     *
     * Este parametro existia desde antes e era descartado: o corpo do metodo
     * nao o mencionava depois da assinatura. Api\ScoreboardController
     * calculava o congelamento com cuidado, inclusive isentando admin, e
     * passava adiante para ser jogado fora -- a resposta dizia
     * `is_frozen: true` e entregava, na mesma carga, o solve feito depois do
     * congelamento. Nao era uma regressao: o congelamento nunca escondeu
     * nada em lugar nenhum. A unica ocorrencia de "congelado" nas views e um
     * FAQ explicando ao publico o que um congelamento significaria.
     *
     * As celulas congeladas nao podem vir de `scores`: aquelas linhas sao
     * reescritas a cada veredito. Vem dos runs, com corte por contest_time.
     * Ver App\Services\FrozenScoreboard.
     */
    public static function getScoreboard(int $contestId, bool $frozen = false): array
    {
        $contest = Contest::findOrFail($contestId);

        if ($frozen) {
            return FrozenScoreboard::rows($contest);
        }

        // Issue #212 -- as equipes vem do contest, e nao das linhas de
        // `leaderboard`.
        //
        // Uma linha de `leaderboard` so nasce em Score::recomputeFor(), que
        // so roda para run JULGADO, entao iterar essas linhas queria dizer
        // "quem ainda nao teve nada julgado nao existe para a tabela".
        // Nos primeiros minutos a tela ficava praticamente vazia, e uma
        // equipe que submeteu e esperava julgamento nao se encontrava nela.
        //
        // A posicao passa a ser calculada aqui em vez de lida de
        // `leaderboard.rank`, pelo mesmo motivo: a guardada so cobre quem
        // tem linha. A regra e a mesma, compartilhada com o placar
        // congelado e com recalculateRanks() -- ver ScoreboardRanking.
        $entries = self::where('contest_id', $contestId)->get()->keyBy('user_id');

        // Uma consulta de `scores` para o contest inteiro, e nao uma por
        // linha. Era uma por linha, e ate aqui "linha" queria dizer "quem ja
        // pontuou"; agora quer dizer "toda equipe inscrita", entao a mesma
        // consulta dentro do laco passaria de dezenas para milhares numa
        // prova grande. O conserto de uma coisa nao pode pagar com a outra.
        $allScores = Score::where('contest_id', $contestId)->with('problem')->get()->groupBy('user_id');

        $result = [];

        foreach (ScoreboardTeams::forContest($contest) as $userId => $user) {
            $entry = $entries->get($userId);
            $scores = $allScores->get($userId, collect())->keyBy('problem_id');

            $result[] = [
                'rank' => 0,
                'user' => $user,
                'problems_solved' => (int) ($entry->problems_solved ?? 0),
                'total_time' => (int) ($entry->total_time ?? 0),
                'problems' => $scores->map(fn ($s) => [
                    'problem_id' => $s->problem_id,
                    'short_name' => $s->problem->short_name,
                    'attempts' => $s->attempts,
                    'is_solved' => $s->is_solved,
                    'is_first_solver' => $s->is_first_solver,
                    'solved_time' => $s->solved_time,
                    'penalty_time' => $s->penalty_time,
                    // Sempre presente, mesmo valendo zero: o placar
                    // congelado traz aqui quantas tentativas estao em
                    // aberto, e um template que so encontrasse a chave as
                    // vezes teria que adivinhar qual placar esta lendo.
                    'pending' => 0,
                ])->values(),
            ];
        }

        return ScoreboardRanking::apply($result);
    }
}
