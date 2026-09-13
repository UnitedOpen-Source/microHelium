<?php

namespace App\Services\Practice;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Issue #43 -- resolves the single technical contest the practice library
 * lives inside (docs/specs/43-practice.md: "Criar um único concurso técnico
 * de prática, identificado por campo/tipo explícito e nunca por nome").
 *
 * It is a Contest row so that practice can reuse Problem/Run/Score and the
 * judging pipeline unchanged, and it is flagged `is_practice` so that
 * everything meaning "a competition" can exclude it -- Contest::competition().
 *
 * It is never active and never has a start_time, which is not an oversight:
 * isRunning() is therefore always false and getContestTime() always 0, so
 * practice can never be mistaken for a running event by code that asks the
 * contest rather than asking us. That is also why the submit path here does
 * not reuse SubmitController: "não reutilizar diretamente SubmitController
 * sem adaptar suas regras de horário e escopo."
 */
class PracticeContest
{
    public const CONTEST_NAME = 'Treino Livre';

    public const SITE_NAME = 'Treino Livre';

    public function contest(): Contest
    {
        $existing = $this->find();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () {
            // The SELECT ... FOR UPDATE is over an indexed range on
            // is_practice (see the migration), so on InnoDB it gap-locks and
            // a concurrent creator blocks here rather than inserting a
            // second practice contest. find()'s orderBy('id') keeps the
            // answer deterministic even on an engine that does not.
            $contest = Contest::query()->practice()->lockForUpdate()->orderBy('id')->first();

            if ($contest) {
                return $contest;
            }

            $contest = Contest::create([
                'name' => self::CONTEST_NAME,
                'description' => 'Contest tecnico do Treino Livre. Nao e uma competicao: '
                    .'nao aparece em selecao de contest ativo, placar de evento, '
                    .'tarefas ou exportacoes de evento.',
                'start_time' => null,
                'duration' => 0,
                'freeze_time' => 0,
                'penalty' => 0,
                'is_active' => false,
                'is_public' => false,
                'is_practice' => true,
            ]);

            $this->seedSite($contest);
            $this->seedLanguages($contest);
            $this->seedAnswers($contest);

            return $contest;
        });
    }

    /**
     * The practice contest if it exists, without creating one. Read paths
     * use this: a GET on an empty library must not have the side effect of
     * provisioning a contest.
     */
    public function find(): ?Contest
    {
        return Contest::query()->practice()->orderBy('id')->first();
    }

    public function site(Contest $contest): Site
    {
        return $contest->sites()->orderBy('id')->firstOrFail();
    }

    /**
     * The languages a practice submission may use. Snapshotting is at the
     * publication level for statement/limits/tests; languages come from the
     * practice contest's own set, so enabling a new language does not
     * require republishing every problem.
     */
    public function languages(Contest $contest): Collection
    {
        return $contest->languages()->where('is_active', true)->orderBy('name')->get();
    }

    private function seedSite(Contest $contest): void
    {
        Site::create([
            'contest_id' => $contest->id,
            'name' => self::SITE_NAME,
            'is_active' => true,
            'permit_logins' => false,
            'auto_judge' => true,
        ]);
    }

    private function seedLanguages(Contest $contest): void
    {
        foreach (Language::getDefaultLanguages() as $language) {
            if (! ($language['is_active'] ?? false)) {
                continue;
            }

            Language::create(array_merge($language, ['contest_id' => $contest->id]));
        }
    }

    private function seedAnswers(Contest $contest): void
    {
        foreach (Answer::getDefaultAnswers() as $answer) {
            Answer::create(array_merge($answer, ['contest_id' => $contest->id]));
        }
    }
}
