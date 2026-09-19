<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Answer extends Model
{
    use HasFactory;

    protected $fillable = [
        'contest_id',
        'name',
        'short_name',
        'is_accepted',
        'counts_as_attempt',
        'is_fake',
        'sort_order',
    ];

    protected $casts = [
        'is_accepted' => 'boolean',
        'counts_as_attempt' => 'boolean',
        'is_fake' => 'boolean',
    ];

    /** @return BelongsTo<Contest, $this> */
    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    /** @return HasMany<Run, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /**
     * Issue #322 -- a politica do erro de compilacao, escrita.
     *
     * `CE` conta como tentativa e paga a penalidade inteira. Nao e uma regra
     * derivada da ICPC: o texto normativo fala em "previously rejected run"
     * e nao diz o que e um run rejeitado, e as duas escolhas existem em
     * regionais diferentes -- o DOMjudge torna isso configuravel
     * (`compile_penalty`) exatamente por isso. A escolha aqui e o BOCA, que
     * e o sistema em que a Maratona roda e de cujo esquema este projeto
     * herda a modelagem: `CE` e resposta normal e penaliza. Uma prova que
     * queira o contrario -- treino, seletiva interna -- desliga
     * `counts_as_attempt` na linha `CE` daquela prova, e nada mais muda.
     *
     * Issue #321 -- `CS` NAO conta.
     *
     * Ele e escrito por `AutoJudgeService::handleJudgingError()` e pelo
     * watchdog `runs:reconcile-stuck` (#45) quando o julgamento falha ou
     * estoura o prazo: e um veredito sobre a nossa infraestrutura, e nao
     * sobre o codigo da equipe. Os dois lugares ja diziam por escrito que
     * ele nao deveria custar tentativa, e a protecao que os dois usavam
     * (nao chamar `Score::updateScore()`) deixou de funcionar quando o #171
     * tornou a celula uma funcao pura dos runs que contam: o proximo
     * veredito da mesma equipe no mesmo problema recompunha a celula e o
     * `CS` entrava na conta. A exclusao agora esta em
     * `Run::scopeCountingTowardsScore()`, que e onde ela e verdade.
     */
    public static function getDefaultAnswers(): array
    {
        return [
            ['name' => 'Accepted', 'short_name' => 'AC', 'is_accepted' => true, 'counts_as_attempt' => true, 'sort_order' => 1],
            ['name' => 'Compilation Error', 'short_name' => 'CE', 'is_accepted' => false, 'counts_as_attempt' => true, 'sort_order' => 2],
            ['name' => 'Runtime Error', 'short_name' => 'RE', 'is_accepted' => false, 'counts_as_attempt' => true, 'sort_order' => 3],
            ['name' => 'Time Limit Exceeded', 'short_name' => 'TLE', 'is_accepted' => false, 'counts_as_attempt' => true, 'sort_order' => 4],
            ['name' => 'Memory Limit Exceeded', 'short_name' => 'MLE', 'is_accepted' => false, 'counts_as_attempt' => true, 'sort_order' => 5],
            ['name' => 'Wrong Answer', 'short_name' => 'WA', 'is_accepted' => false, 'counts_as_attempt' => true, 'sort_order' => 6],
            ['name' => 'Presentation Error', 'short_name' => 'PE', 'is_accepted' => false, 'counts_as_attempt' => true, 'sort_order' => 7],
            ['name' => 'Contact Staff', 'short_name' => 'CS', 'is_accepted' => false, 'counts_as_attempt' => false, 'sort_order' => 8],
        ];
    }
}
