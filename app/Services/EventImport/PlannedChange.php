<?php

namespace App\Services\EventImport;

/**
 * Issue #147 -- what the importer intends to do with one row, decided
 * without writing anything.
 *
 * This is the unit the dry run prints. Its four statuses are the answer to
 * the requirement "mostre o que sera criado, alterado e ignorado":
 * CREATE/UPDATE/UNCHANGED/ERROR, plus, for an update, the field-by-field
 * before/after that lets an organiser see `max_judge_wait_time: 900 -> 600`
 * instead of the word "alterar".
 */
final class PlannedChange
{
    public const CREATE = 'criar';

    public const UPDATE = 'alterar';

    public const UNCHANGED = 'inalterado';

    public const ERROR = 'erro';

    /**
     * @param  string  $kind  contest|site|language|problem
     * @param  array<string, mixed>  $attributes  what would be written
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes  field => [antes, depois]
     * @param  array<string, mixed>  $extras  non-column values carried from the file (judges_for, package)
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $location,
        public readonly string $label,
        public readonly string $status,
        public readonly array $attributes = [],
        public readonly array $changes = [],
        public readonly ?int $modelId = null,
        public readonly string $detail = '',
        public readonly array $extras = [],
    ) {}

    public function isWrite(): bool
    {
        return $this->status === self::CREATE || $this->status === self::UPDATE;
    }

    /**
     * "duration: 300 -> 240, freeze_time: 60 -> 45" -- the cell an organiser
     * actually reads before saying yes.
     */
    public function changeSummary(): string
    {
        $parts = [];

        foreach ($this->changes as $field => [$from, $to]) {
            $parts[] = $field.': '.$this->render($from).' -> '.$this->render($to);
        }

        return implode(', ', $parts);
    }

    private function render(mixed $value): string
    {
        return match (true) {
            $value === null => '(vazio)',
            $value === true => 'true',
            $value === false => 'false',
            is_string($value) && mb_strlen($value) > 40 => mb_substr($value, 0, 37).'...',
            default => (string) $value,
        };
    }
}
