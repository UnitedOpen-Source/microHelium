<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Problem extends Model
{
    /**
     * Issue #271 -- a ordem em que os problemas de uma prova aparecem.
     *
     * `Contest::problems()` já aplica `orderBy('sort_order')`, e isso não
     * basta: `sort_order` **não é único**. Dois problemas com o mesmo valor
     * voltam na ordem que o banco escolher, e o `ordinal` da Contest API sai
     * do ÍNDICE da coleção -- então a posição publicada dos dois troca entre
     * duas leituras da mesma prova, sem nada ter mudado.
     *
     * É também a primeira coisa que quebraria a reprodutibilidade do pacote
     * de resultados (#271), que exige duas exportações idênticas byte a byte.
     *
     * Um escopo, e não três `orderBy` soltos no chamador, porque "a ordem
     * dos problemas de uma prova" é um conceito do domínio e precisa ter um
     * lugar só -- e porque assim a garantia é verificável: um teste pergunta
     * ao escopo qual SQL ele produz. Sem isso a mutação que remove os
     * desempates passa limpa no SQLite, onde o motor devolve a ordem certa
     * por coincidência. Medido.
     *
     * @param  Builder<Problem>  $query
     */
    public function scopeInContestOrder($query)
    {
        return $query
            ->orderBy('sort_order')
            ->orderBy('short_name')
            ->orderBy('id');
    }

    use HasFactory, SoftDeletes;

    protected $fillable = [
        'contest_id',
        'short_name',
        'name',
        'basename',
        'description',
        'description_file',
        'input_file',
        'input_file_hash',
        'color_name',
        'color_hex',
        'time_limit',
        'memory_limit',
        'output_limit',
        'auto_judge',
        'judging_paused_at',
        'judging_paused_by',
        'is_fake',
        'sort_order',
    ];

    protected $casts = [
        'judging_paused_at' => 'datetime',
        'auto_judge' => 'boolean',
        'is_fake' => 'boolean',
    ];

    /** @return BelongsTo<Contest, $this> */
    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    /** @return HasMany<TestCase, $this> */
    public function testCases(): HasMany
    {
        return $this->hasMany(TestCase::class)->orderBy('number');
    }

    /** @return HasMany<Run, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /** @return HasMany<Clarification, $this> */
    public function clarifications(): HasMany
    {
        return $this->hasMany(Clarification::class);
    }

    /** @return HasMany<Score, $this> */
    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }

    /** @return HasMany<ProblemLanguageLimit, $this> */
    public function languageLimits(): HasMany
    {
        return $this->hasMany(ProblemLanguageLimit::class);
    }

    /**
     * BOCA models problemtable.problemautojudge as a bitmask so autojudge
     * can be enabled per language, and each language in a problem package
     * carries its own limits/<lang> file. These three accessors give the
     * same per-problem-per-language granularity via problem_language_limits,
     * falling back to this problem's own defaults when no override exists.
     */
    public function getTimeLimitFor(Language $language): int
    {
        return $this->limitOverrideFor($language)?->time_limit ?? $this->time_limit;
    }

    public function getMemoryLimitFor(Language $language): int
    {
        return $this->limitOverrideFor($language)?->memory_limit ?? $this->memory_limit;
    }

    /**
     * Issue #193 -- is this problem's judging on hold?
     *
     * A paused problem still accepts submissions; they queue and wait. The
     * jury pauses when the problem itself is wrong -- bad expected output,
     * a test case that does not match the statement -- so that teams stop
     * collecting WRONG ANSWER, and twenty penalty minutes each, for a
     * defect that is not theirs.
     */
    public function isJudgingPaused(): bool
    {
        return $this->judging_paused_at !== null;
    }

    public function isAutoJudgeEnabledFor(Language $language): bool
    {
        $override = $this->limitOverrideFor($language)?->auto_judge_enabled;

        return $override ?? $this->auto_judge;
    }

    private function limitOverrideFor(Language $language): ?ProblemLanguageLimit
    {
        return $this->relationLoaded('languageLimits')
            ? $this->languageLimits->firstWhere('language_id', $language->id)
            : $this->languageLimits()->where('language_id', $language->id)->first();
    }

    public function getPackagePath(): string
    {
        return storage_path("app/problems/{$this->contest_id}/{$this->basename}");
    }

    /**
     * Issue #137 -- the absolute path of the statement file the BOCA package
     * carried in its description/ directory, or null when this problem has
     * none.
     *
     * description_file is whatever problem.info's `descfile` key said, i.e. a
     * string that came out of an uploaded (or GitHub-imported) package, so it
     * is untrusted input and must never be concatenated into a path as it
     * stands: `descfile=../../../../.env` would otherwise resolve to a file
     * well outside the package directory. basename() pins the result inside
     * description/ whatever the package asked for.
     */
    public function getDescriptionFilePath(): ?string
    {
        $file = basename(trim((string) $this->description_file));

        if ($file === '' || $file === '.' || $file === '..') {
            return null;
        }

        return $this->getPackagePath()."/description/{$file}";
    }

    /**
     * Whether that statement file is actually on disk. The column and the
     * disk can disagree -- a package imported on another machine, a database
     * restored next to an empty storage/ -- and a dead link to a document the
     * team cannot open is worse than no link at all, so callers ask this
     * before offering the statement.
     */
    public function hasDescriptionFile(): bool
    {
        $path = $this->getDescriptionFilePath();

        return $path !== null && is_file($path);
    }

    public function getCompileScriptPath(string $language): string
    {
        return $this->getPackagePath()."/compile/{$language}";
    }

    public function getRunScriptPath(string $language): string
    {
        return $this->getPackagePath()."/run/{$language}";
    }

    public function getCompareScriptPath(string $language): string
    {
        return $this->getPackagePath()."/compare/{$language}";
    }

    public function getLimitsScriptPath(string $language): string
    {
        return $this->getPackagePath()."/limits/{$language}";
    }

    public function getInputPath(int $testNumber): string
    {
        return $this->getPackagePath()."/input/{$testNumber}";
    }

    public function getOutputPath(int $testNumber): string
    {
        return $this->getPackagePath()."/output/{$testNumber}";
    }
}
