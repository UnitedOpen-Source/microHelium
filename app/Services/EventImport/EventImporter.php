<?php

namespace App\Services\EventImport;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Site;
use App\Models\SiteJudgingRoute;
use App\Services\ProblemPackageService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Issue #147 -- turns a ParsedEvent into a plan (what would change) and
 * then, separately, executes it.
 *
 * The split is the point. plan() only reads; apply() only writes what
 * plan() decided. That is what makes `--apply`-less the default mode
 * possible, and it is also what makes the apply safe: the command
 * recomputes the plan inside the transaction and refuses to run apply() on
 * a plan that carries errors, so nothing gets half-written.
 *
 * IDENTITY -- what "the same thing" means, which is the whole idempotency
 * question. Each level is keyed by something the file itself contains,
 * never by a database id, because a file that is versioned in git and
 * applied to a fresh server cannot know any ids:
 *
 *  - contest: its `name`, unless --contest=<id> names one explicitly.
 *    There is no unique index on contests.name, so the match is done over
 *    non-practice, non-deleted contests and an ambiguous name is refused
 *    rather than guessed. The name is the only stable handle a contest has
 *    ("Maratona SBC 2026 -- Primeira Fase" is written on the file, the
 *    scoreboard and the certificates); everything below it is already
 *    scoped by contest_id, so this is the only level where identity had to
 *    be invented.
 *
 *  - site: (contest_id, name) -- which is literally the UNIQUE index in
 *    2025_11_25_000002_create_sites_table. Renaming a site in the file
 *    therefore creates a second site rather than renaming the first: the
 *    old one still owns users.site_id and its runs, and silently moving
 *    forty teams because a string changed is not a decision an importer
 *    gets to make. The preview shows the new site as "criar" and the old
 *    one as being outside the file, which is exactly the signal an
 *    organiser needs.
 *
 *  - language: (contest_id, extension). The table has TWO unique indexes,
 *    on name and on extension, and only one of them can be identity. It is
 *    the extension because that is the technical key -- Problem::
 *    getCompileScriptPath() builds a path out of it and SubmitController
 *    names the submitted file from it -- while the name is a label that
 *    gets prettified between editions ("C++17" -> "C++ (G++ 15)").
 *
 *  - problem: (contest_id, basename). Same reasoning, more sharply: the
 *    basename is the directory the package lives in
 *    (storage/app/problems/<contest>/<basename>), whereas short_name is
 *    the scoreboard letter and gets reassigned whenever the set is
 *    reordered by difficulty. Keying on the letter would turn a reorder
 *    into a duplicate problem set.
 *
 * Matching is case-insensitive on purpose: MySQL's default collation makes
 * the unique indexes case-insensitive, so treating "UFMG" and "ufmg" as
 * different rows would produce a plan that says "criar" and a driver that
 * says "Duplicate entry". Lookups also include soft-deleted rows, for the
 * same reason -- those indexes have no deleted_at condition, so a trashed
 * site still occupies its name (LanguageController makes the same point
 * about its own validation rules). A matched trashed row is restored.
 *
 * NOTHING IS EVER DELETED. Rows present in the contest and absent from the
 * file are listed under "fora do arquivo" and left alone.
 */
class EventImporter
{
    public function __construct(private readonly ProblemPackageService $packages) {}

    public function plan(ParsedEvent $parsed, ?int $contestId = null): EventImportPlan
    {
        $plan = new EventImportPlan;
        $plan->errors = $parsed->errors;
        $plan->warnings = $parsed->warnings;

        if (! $this->planContest($parsed, $contestId, $plan)) {
            $plan->sortIssues();

            return $plan;
        }

        $contest = $plan->existingContest;

        $plan->sites = $this->planEntries(
            'site',
            $parsed->sites,
            $contest ? Site::withTrashed()->where('contest_id', $contest->id)->get() : new Collection,
            'name',
            null,
            $plan,
        );

        $plan->languages = $this->planEntries(
            'language',
            $parsed->languages,
            $contest ? Language::withTrashed()->where('contest_id', $contest->id)->get() : new Collection,
            'extension',
            'name',
            $plan,
        );

        $plan->problems = $this->planEntries(
            'problem',
            $parsed->problems,
            $contest ? Problem::withTrashed()->where('contest_id', $contest->id)->get() : new Collection,
            'basename',
            'short_name',
            $plan,
        );

        $this->checkJudgingRoutes($plan);
        $this->warnAboutPackagesOnExistingProblems($plan);
        $plan->sortIssues();

        return $plan;
    }

    /**
     * Resolves (or invents) the contest and records what would happen to
     * it. Returns false when the file cannot be tied to a contest at all,
     * in which case planning the rest would be meaningless -- every site
     * would be reported as "criar" against a contest that may already have
     * all of them.
     */
    private function planContest(ParsedEvent $parsed, ?int $contestId, EventImportPlan $plan): bool
    {
        $attributes = $parsed->contest;

        if (($attributes['name'] ?? null) === null) {
            // The name is both required and the identity; without it there
            // is nothing to match on and the parser has already said so.
            return false;
        }

        if ($contestId !== null) {
            $contest = Contest::find($contestId);

            if (! $contest) {
                $plan->addError('--contest', "contest {$contestId} nao encontrado.");

                return false;
            }

            if ($contest->is_practice) {
                $plan->addError('--contest', 'o contest tecnico do Treino Livre (#43) nao e uma competicao e nao recebe importacao.');

                return false;
            }

            if (mb_strtolower(trim((string) $contest->name)) !== mb_strtolower($attributes['name'])) {
                // Not fatal: --contest is an explicit instruction and
                // renaming a contest is legitimate. But applying
                // "Regional 2026" on top of "Regional 2025" because of a
                // mistyped id is not, so it is said out loud.
                $plan->addWarning('--contest', "o arquivo descreve \"{$attributes['name']}\" e o contest {$contestId} se chama \"{$contest->name}\": o nome sera alterado.");
            }
        } else {
            $matches = Contest::query()
                ->competition()
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($attributes['name'])])
                ->orderBy('id')
                ->get();

            if ($matches->count() > 1) {
                $plan->addError('contest.name', 'ha '.$matches->count()." competicoes chamadas \"{$attributes['name']}\" (ids: ".$matches->pluck('id')->implode(', ').'). Informe --contest=<id> para dizer qual delas o arquivo descreve.');

                return false;
            }

            $contest = $matches->first();
        }

        $plan->existingContest = $contest;

        if (! $contest) {
            $plan->contest = new PlannedChange('contest', 'contest', $attributes['name'], PlannedChange::CREATE, $attributes);

            return true;
        }

        $changes = $this->diff($attributes, $contest);

        $plan->contest = new PlannedChange(
            'contest',
            'contest',
            $attributes['name'],
            // A trashed row counts as a change even when every field
            // already matches: restoring it IS the write. Without this the
            // plan says "inalterado", the command sees nothing to do and
            // the contest stays deleted while the file says it exists.
            $changes === [] && ! $contest->trashed() ? PlannedChange::UNCHANGED : PlannedChange::UPDATE,
            $attributes,
            $changes,
            $contest->id,
            $contest->trashed() ? 'restaura competicao removida' : '',
        );

        return true;
    }

    /**
     * @param  list<EventEntry>  $entries
     * @param  Collection<int, Site|Language|Problem>  $existing
     * @return list<PlannedChange>
     */
    private function planEntries(string $kind, array $entries, Collection $existing, string $identityKey, ?string $secondaryKey, EventImportPlan $plan): array
    {
        $byIdentity = $existing->keyBy(fn (Model $m) => $this->key($m->getAttribute($identityKey)));
        $bySecondary = $secondaryKey === null
            ? new Collection
            : $existing->keyBy(fn (Model $m) => $this->key($m->getAttribute($secondaryKey)));

        $fileIdentities = [];
        foreach ($entries as $entry) {
            $fileIdentities[$this->key($entry->attributes[$identityKey])] = true;
        }

        $seenIdentity = [];
        $seenSecondary = [];
        $touched = [];
        $changes = [];

        foreach ($entries as $entry) {
            $identity = $this->key($entry->attributes[$identityKey]);
            $secondary = $secondaryKey === null ? null : $this->key($entry->attributes[$secondaryKey]);

            // Duplicates inside the file are caught before the database is
            // consulted. Without this the second copy reaches the unique
            // index and aborts the transaction after the preview said it
            // was fine -- and the operator gets a driver message instead of
            // a location.
            if (isset($seenIdentity[$identity])) {
                $changes[] = $this->rejected($kind, $entry, $plan, sprintf(
                    '%s repetido no proprio arquivo (ja aparece em %s)',
                    $identityKey,
                    $seenIdentity[$identity],
                ));

                continue;
            }

            if ($secondary !== null && isset($seenSecondary[$secondary])) {
                $changes[] = $this->rejected($kind, $entry, $plan, sprintf(
                    '%s "%s" repetido no proprio arquivo (ja aparece em %s)',
                    $secondaryKey,
                    $entry->attributes[$secondaryKey],
                    $seenSecondary[$secondary],
                ));

                continue;
            }

            $match = $byIdentity->get($identity);

            // The other unique index. A row already holding this secondary
            // value is only acceptable when it is the very row being
            // updated, or when the file also describes it (and therefore
            // moves it out of the way -- see parkSecondaryKeys).
            if ($secondary !== null) {
                $holder = $bySecondary->get($secondary);

                if ($holder && (! $match || $holder->getKey() !== $match->getKey())) {
                    $holderIdentity = $this->key($holder->getAttribute($identityKey));

                    if (! isset($fileIdentities[$holderIdentity])) {
                        $changes[] = $this->rejected($kind, $entry, $plan, sprintf(
                            '%s "%s" ja pertence a %s "%s" nesta competicao',
                            $secondaryKey,
                            $entry->attributes[$secondaryKey],
                            $identityKey,
                            $holder->getAttribute($identityKey),
                        ));

                        continue;
                    }
                }
            }

            $seenIdentity[$identity] = $entry->location;
            if ($secondary !== null) {
                $seenSecondary[$secondary] = $entry->location;
            }

            if (! $match) {
                $changes[] = new PlannedChange($kind, $entry->location, $entry->label, PlannedChange::CREATE, $entry->attributes, [], null, '', $entry->extras);

                continue;
            }

            $touched[$match->getKey()] = true;
            $diff = $this->diff($entry->attributes, $match);

            $changes[] = new PlannedChange(
                $kind,
                $entry->location,
                $entry->label,
                // See planContest(): a restore is a write even with an
                // empty diff.
                $diff === [] && ! $match->trashed() ? PlannedChange::UNCHANGED : PlannedChange::UPDATE,
                $entry->attributes,
                $diff,
                (int) $match->getKey(),
                $match->trashed() ? 'restaura registro removido' : '',
                $entry->extras,
            );
        }

        foreach ($existing as $model) {
            if (! isset($touched[$model->getKey()]) && ! $model->trashed()) {
                $plan->orphans[$kind][] = (string) $model->getAttribute($identityKey);
            }
        }

        return $changes;
    }

    private function rejected(string $kind, EventEntry $entry, EventImportPlan $plan, string $detail): PlannedChange
    {
        $plan->addError($entry->location, $detail.'.');

        return new PlannedChange($kind, $entry->location, $entry->label, PlannedChange::ERROR, $entry->attributes, [], null, $detail);
    }

    /**
     * judges_for names sites by name, so the names have to exist -- either
     * in the file or already in the contest. A site routing to itself is
     * dropped with a warning rather than refused, matching what the site
     * form does with the same input (routedJudgingSiteIds() always includes
     * the host's own id, so the row would be a no-op).
     */
    private function checkJudgingRoutes(EventImportPlan $plan): void
    {
        $known = [];
        foreach ($plan->sites as $change) {
            if ($change->status !== PlannedChange::ERROR) {
                $known[$this->key($change->label)] = true;
            }
        }
        foreach ($plan->orphans['site'] as $name) {
            $known[$this->key($name)] = true;
        }

        foreach ($plan->sites as $change) {
            if (! array_key_exists('judges_for', $change->extras)) {
                continue;
            }

            foreach ($change->extras['judges_for'] as $name) {
                if ($this->key($name) === $this->key($change->label)) {
                    $plan->addWarning($change->location.'.judges_for', "\"{$name}\" e a propria sede; a entrada foi ignorada (uma sede sempre julga os proprios runs).");

                    continue;
                }

                if (! isset($known[$this->key($name)])) {
                    $plan->addError($change->location.'.judges_for', "sede \"{$name}\" nao existe no arquivo nem nesta competicao.");
                }
            }
        }
    }

    /**
     * A package is imported once, when the problem is created. Re-importing
     * it over an existing problem would append a second copy of every test
     * case (ProblemPackageService::importTestCases only ever inserts), so
     * the file's package key is ignored for problems that already exist --
     * out loud, because an organiser who swapped the zip expects something
     * to happen.
     */
    private function warnAboutPackagesOnExistingProblems(EventImportPlan $plan): void
    {
        foreach ($plan->problems as $change) {
            if ($change->status === PlannedChange::CREATE || $change->status === PlannedChange::ERROR) {
                continue;
            }

            if (isset($change->extras['package'])) {
                $plan->addWarning($change->location.'.package', "o problema \"{$change->label}\" ja existe: o pacote nao sera reimportado. Use a tela de problemas para substituir os casos de teste.");
            }
        }
    }

    // ---------------------------------------------------------------- apply

    /**
     * Executes a plan. Must be called inside a transaction and only with a
     * plan that has no errors; EventImportCommand guarantees both.
     */
    public function apply(EventImportPlan $plan): Contest
    {
        $contest = $this->applyContest($plan);
        $siteIds = $this->applySites($plan, $contest);
        $this->applyJudgingRoutes($plan, $siteIds);
        $this->applyLanguages($plan, $contest);
        $this->applyProblems($plan, $contest);

        return $contest;
    }

    private function applyContest(EventImportPlan $plan): Contest
    {
        $change = $plan->contest;

        if ($change->status === PlannedChange::CREATE) {
            $contest = Contest::create($change->attributes);

            // Every verdict AutoJudgeService can emit needs a row in
            // `answers`, or a judged run is saved with a null answer_id and
            // reads as "nothing happened" (tests/Feature/DefaultAnswersTest).
            // Read from the one canonical list, like the wizard, the API and
            // the practice contest do -- a fifth hand-written copy is exactly
            // what that test exists to prevent.
            foreach (Answer::getDefaultAnswers() as $answer) {
                Answer::create(array_merge($answer, ['contest_id' => $contest->id]));
            }

            return $contest;
        }

        $contest = Contest::withTrashed()->findOrFail($change->modelId);

        if ($contest->trashed()) {
            $contest->restore();
        }

        if ($change->status === PlannedChange::UPDATE) {
            $contest->update($change->attributes);
        }

        return $contest;
    }

    /**
     * @return array<string, int> lower-cased site name => id, for judges_for
     */
    private function applySites(EventImportPlan $plan, Contest $contest): array
    {
        $ids = [];

        foreach (Site::where('contest_id', $contest->id)->get() as $site) {
            $ids[$this->key($site->name)] = $site->id;
        }

        foreach ($plan->sites as $change) {
            $site = $this->write(Site::class, $change, $contest);

            if ($site) {
                $ids[$this->key($site->name)] = $site->id;
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $siteIds
     */
    private function applyJudgingRoutes(EventImportPlan $plan, array $siteIds): void
    {
        foreach ($plan->sites as $change) {
            if ($change->status === PlannedChange::ERROR || ! array_key_exists('judges_for', $change->extras)) {
                // Absent key means "this file says nothing about routing
                // for this site", and routing configured by hand in the
                // site form is left alone. An empty list, on the other
                // hand, is a statement: it erases the routes.
                continue;
            }

            $hostId = $siteIds[$this->key($change->label)] ?? null;

            if ($hostId === null) {
                continue;
            }

            SiteJudgingRoute::where('host_site_id', $hostId)->delete();

            foreach ($change->extras['judges_for'] as $name) {
                $sourceId = $siteIds[$this->key($name)] ?? null;

                if ($sourceId === null || $sourceId === $hostId) {
                    continue;
                }

                SiteJudgingRoute::firstOrCreate(['host_site_id' => $hostId, 'source_site_id' => $sourceId]);
            }
        }
    }

    private function applyLanguages(EventImportPlan $plan, Contest $contest): void
    {
        $this->parkSecondaryKeys(Language::class, $plan->languages, 'name');

        foreach ($plan->languages as $change) {
            $this->write(Language::class, $change, $contest);
        }
    }

    private function applyProblems(EventImportPlan $plan, Contest $contest): void
    {
        $this->parkSecondaryKeys(Problem::class, $plan->problems, 'short_name');

        foreach ($plan->problems as $change) {
            if ($change->status !== PlannedChange::CREATE || ! isset($change->extras['package'])) {
                $this->write(Problem::class, $change, $contest);

                continue;
            }

            // Delegated rather than reimplemented: ProblemPackageService is
            // the code path the problem-import screen uses, and it is what
            // knows how to read problem.info, move the package into
            // storage/app/problems/<contest>/<basename> and register the
            // test cases. It creates the Problem row itself, so the file's
            // values go in as overrides -- including `fullname`, which is
            // the key problem.info uses and which would otherwise win over
            // the name written in the event file.
            //
            // Its filesystem work is not transactional: if a later entry
            // aborts the import, the database rolls back but the extracted
            // package stays on disk. Harmless (the next apply overwrites
            // it, and an orphan directory judges nothing), and preferable
            // to holding a half-extracted package in memory.
            $uploaded = new UploadedFile($change->extras['package'], basename($change->extras['package']), null, null, true);

            try {
                $problem = $this->packages->importFromZip(
                    $contest,
                    $uploaded,
                    array_merge($change->attributes, ['fullname' => $change->attributes['name']]),
                );
            } catch (\Throwable $e) {
                // A bad zip is reported with the location that named it, so
                // the operator is told which line of the file to fix rather
                // than being handed "problem.info file not found".
                throw new \RuntimeException(
                    "{$change->location}.package ({$change->extras['package']}): ".$e->getMessage(),
                    previous: $e,
                );
            }

            // The file is the source of truth for everything the package
            // does not carry (description, is_fake) and for sort_order,
            // which importFromZip computes as "one past the last problem".
            $problem->update($change->attributes);
        }
    }

    /**
     * Creates or updates one row of $modelClass from a planned change.
     *
     * The union is the whole set this is ever called with (Site, Language,
     * Problem), and naming it rather than `class-string<Model>` is what
     * lets the soft-delete calls below typecheck: withTrashed(), trashed()
     * and restore() come from the SoftDeletes trait, which the base Model
     * does not have. The wider annotation was not more general, it was just
     * less true.
     *
     * @param  class-string<Site|Language|Problem>  $modelClass
     */
    private function write(string $modelClass, PlannedChange $change, Contest $contest): Site|Language|Problem|null
    {
        if ($change->status === PlannedChange::ERROR) {
            return null;
        }

        if ($change->status === PlannedChange::CREATE) {
            return $modelClass::create(array_merge(['contest_id' => $contest->id], $change->attributes));
        }

        $model = $modelClass::withTrashed()->findOrFail($change->modelId);

        if ($model->trashed()) {
            // The file says this exists, and the unique index says its name
            // is still taken by the trashed row. Restoring is the only
            // outcome that leaves the contest looking like the file.
            $model->restore();
        }

        if ($change->status === PlannedChange::UPDATE) {
            $model->update($change->attributes);
        }

        return $model;
    }

    /**
     * Parks every secondary unique value that is about to change on a
     * placeholder before any of the real values are written.
     *
     * Reassigning problem letters between editions (A and B swap) is an
     * ordinary edit to the file, but both MySQL and SQLite check a unique
     * index per statement, so writing A->B first collides with the B that
     * has not become C yet. "~<id>" cannot collide with a real value: the
     * parser rejects "~" in short_name and in language names, and the id
     * makes each placeholder distinct.
     *
     * @param  class-string<Site|Language|Problem>  $modelClass
     * @param  list<PlannedChange>  $changes
     */
    private function parkSecondaryKeys(string $modelClass, array $changes, string $field): void
    {
        foreach ($changes as $change) {
            if ($change->status !== PlannedChange::UPDATE || $change->modelId === null || ! isset($change->changes[$field])) {
                continue;
            }

            $modelClass::withTrashed()->whereKey($change->modelId)->update([$field => '~'.$change->modelId]);
        }
    }

    // ----------------------------------------------------------- comparison

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function diff(array $attributes, Model $model): array
    {
        $changes = [];

        foreach ($attributes as $field => $value) {
            // The stored column, deliberately not $model->$field.
            // Contest::getFreezeTimeAttribute() is an accessor that shadows
            // the column of the same name and returns the *instant* the
            // scoreboard freezes rather than the minutes stored in it; read
            // through the accessor, every re-run of an unchanged file
            // reported "freeze_time: 19/09/2026 20:00 -> 60" and offered to
            // rewrite it. Raw values also sidestep drivers without a boolean
            // type, which is handled below.
            $current = $model->getRawOriginal($field);

            if ($this->comparable($current) !== $this->comparable($value)) {
                $changes[$field] = [$this->display($this->alignType($current, $value), $value), $this->display($value, $value)];
            }
        }

        return $changes;
    }

    /**
     * Raw column values arrive as strings ("0", "2026-09-19 16:00:00")
     * whatever the file wrote. Rendering "is_active: 0 -> false" for what
     * is one flip of one flag makes the preview harder to read than it has
     * to be, so the stored value is shown in the same shape as the value
     * replacing it.
     */
    private function alignType(mixed $current, mixed $target): mixed
    {
        return match (true) {
            $current === null => null,
            is_bool($target) => (bool) $current,
            is_int($target) => (int) $current,
            default => $current,
        };
    }

    /**
     * Values come from two worlds -- a decoded file and a hydrated model --
     * and differ in shape without differing in meaning: 300 vs "300" from a
     * JSON string, true vs 1 from a driver without a boolean type, a Carbon
     * vs a Carbon in another timezone. Comparing the rendered scalar keeps
     * the plan from reporting changes nobody made, which matters: a preview
     * full of phantom diffs is a preview nobody reads.
     */
    private function comparable(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_bool($value) => $value ? '1' : '0',
            default => trim((string) $value),
        };
    }

    private function display(mixed $value, mixed $target): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('d/m/Y H:i');
        }

        if ($target instanceof \DateTimeInterface && is_string($value)) {
            try {
                return Carbon::parse($value)->format('d/m/Y H:i');
            } catch (\Throwable) {
                return $value;
            }
        }

        return $value;
    }

    private function key(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
