<?php

namespace App\Services\EventImport;

use App\Models\Contest;

/**
 * Issue #147 -- everything the importer would do, before it does any of it.
 *
 * The plan is the artefact the whole command is built around: it is computed
 * by reading, printed by the dry run, and then executed. A half-applied
 * event (ten sites in, the eleventh rejected by a unique index) is worse
 * than a refused one, so nothing is written while a single error is in
 * here -- see EventImportCommand, which recomputes the plan inside the
 * transaction and aborts before the first INSERT if it comes back dirty.
 */
final class EventImportPlan
{
    /** @var list<array{location: string, message: string}> */
    public array $errors = [];

    /** @var list<array{location: string, message: string}> */
    public array $warnings = [];

    /**
     * Rows that exist in this contest and are not mentioned in the file.
     * Reported, never deleted: dropping a site cascades to its teams and
     * their runs, and "the file no longer mentions it" is nowhere near
     * enough evidence for that.
     *
     * @var array<string, list<string>>
     */
    public array $orphans = ['site' => [], 'language' => [], 'problem' => []];

    public ?PlannedChange $contest = null;

    /** @var list<PlannedChange> */
    public array $sites = [];

    /** @var list<PlannedChange> */
    public array $languages = [];

    /** @var list<PlannedChange> */
    public array $problems = [];

    /** The contest this file resolved to, when it already exists. */
    public ?Contest $existingContest = null;

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return list<PlannedChange>
     */
    public function all(): array
    {
        return array_values(array_filter(array_merge(
            $this->contest ? [$this->contest] : [],
            $this->sites,
            $this->languages,
            $this->problems,
        )));
    }

    /**
     * @return array{criar: int, alterar: int, inalterado: int}
     */
    public function counts(): array
    {
        $counts = [PlannedChange::CREATE => 0, PlannedChange::UPDATE => 0, PlannedChange::UNCHANGED => 0];

        foreach ($this->all() as $change) {
            if (isset($counts[$change->status])) {
                $counts[$change->status]++;
            }
        }

        return $counts;
    }

    public function hasWrites(): bool
    {
        foreach ($this->all() as $change) {
            if ($change->isWrite()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Puts errors and warnings back in file order.
     *
     * They are produced in two passes -- the parser's field checks first,
     * then the identity/conflict checks that needed the database -- so
     * without this the operator reads "sites[3].ip_address" above
     * "contest.penalty" above "sites[2] duplicada" and has to jump around
     * the file. One list, top to bottom, is the whole point of reporting
     * everything at once (same reasoning as TeamImportCommand sorting by
     * line number).
     */
    public function sortIssues(): void
    {
        $order = fn (array $a, array $b) => $this->rank($a['location']) <=> $this->rank($b['location']);

        // PHP's sort has been stable since 8.0, so entries sharing a rank
        // keep the order they were reported in.
        usort($this->errors, $order);
        usort($this->warnings, $order);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function rank(string $location): array
    {
        $blocks = ['arquivo' => 0, '--contest' => 0, 'version' => 1, 'contest' => 2, 'sites' => 3, 'languages' => 4, 'problems' => 5];

        preg_match('/^([^.\[]+)(?:\[(\d+)\])?/', $location, $matches);

        return [$blocks[$matches[1] ?? ''] ?? 9, isset($matches[2]) ? (int) $matches[2] : -1];
    }

    public function addError(string $location, string $message): void
    {
        $this->errors[] = ['location' => $location, 'message' => $message];
    }

    public function addWarning(string $location, string $message): void
    {
        $this->warnings[] = ['location' => $location, 'message' => $message];
    }
}
