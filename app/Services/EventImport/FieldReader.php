<?php

namespace App\Services\EventImport;

/**
 * Issue #147 -- reads one mapping out of a decoded event file (one site,
 * one problem, the contest block), reporting every bad field instead of
 * throwing on the first.
 *
 * This is deliberately not Laravel's Validator. Validator reports by
 * attribute name ("sites.3.max_judge_wait_time"), which is close to what is
 * wanted, but it has no notion of "this key is not a key at all" -- and an
 * unknown key is the failure this importer most needs to catch. `freeze: 30`
 * instead of `freeze_time: 30` under Validator passes silently and produces
 * a contest whose scoreboard freezes at the wrong minute, which is exactly
 * the class of typo the issue is about. Hence rejectUnknownKeys(), and hence
 * the every-read-is-tracked design that makes it possible.
 *
 * Types are checked strictly rather than coerced. YAML already turns `on`,
 * `yes` and `true` into booleans and `0900` into a string, so the only
 * coercion allowed here is the one JSON forces: an integer written as a
 * quoted string. Everything else is reported, because silently coercing
 * `max_judge_wait_time: "muitos"` to 0 would disable the very alarm the
 * field exists to raise.
 */
final class FieldReader
{
    /** @var array<string, true> */
    private array $read = [];

    /** @var list<array{location: string, message: string}> */
    private array $errors = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        private readonly array $data,
        private readonly string $location,
    ) {}

    /**
     * @return list<array{location: string, message: string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function failed(): bool
    {
        return $this->errors !== [];
    }

    public function string(string $key, ?string $default, int $max, bool $required = false, ?string $pattern = null, ?string $patternHint = null): ?string
    {
        $value = $this->take($key);

        if ($value === null) {
            if ($required) {
                $this->fail($key, 'campo obrigatorio ausente.');
            }

            return $default;
        }

        // Scalars that a human would have meant as text: YAML turns an
        // unquoted 2026 into an int and an unquoted "no" into false, and
        // refusing those would be pedantry ("chief_judge_name: 2026" is
        // absurd, but "color_name: 2" is not).
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            $this->fail($key, 'esperado texto, encontrado '.$this->typeName($value).'.');

            return $default;
        }

        $value = trim($value);

        if ($value === '') {
            if ($required) {
                $this->fail($key, 'campo obrigatorio vazio.');

                return $default;
            }

            return null;
        }

        if (mb_strlen($value) > $max) {
            $this->fail($key, "no maximo {$max} caracteres (tem ".mb_strlen($value).').');

            return $default;
        }

        if ($pattern !== null && preg_match($pattern, $value) !== 1) {
            $this->fail($key, $patternHint ?? "valor invalido: \"{$value}\".");

            return $default;
        }

        return $value;
    }

    public function int(string $key, ?int $default, ?int $min = null, ?int $max = null, bool $required = false, bool $nullable = false): ?int
    {
        if ($nullable && array_key_exists($key, $this->data) && $this->data[$key] === null) {
            $this->read[$key] = true;

            return null;
        }

        $value = $this->take($key);

        if ($value === null) {
            if ($required) {
                $this->fail($key, 'campo obrigatorio ausente.');
            }

            return $default;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            // JSON has no way to write a comment next to a number, so
            // people quote values they want to annotate elsewhere; a
            // string of digits is unambiguous and is accepted.
            $value = (int) trim($value);
        }

        if (! is_int($value)) {
            $this->fail($key, 'esperado numero inteiro, encontrado '.$this->typeName($value).'.');

            return $default;
        }

        if ($min !== null && $value < $min) {
            $this->fail($key, "deve ser no minimo {$min} (recebido {$value}).");

            return $default;
        }

        if ($max !== null && $value > $max) {
            $this->fail($key, "deve ser no maximo {$max} (recebido {$value}).");

            return $default;
        }

        return $value;
    }

    public function bool(string $key, ?bool $default): ?bool
    {
        $value = $this->take($key);

        if ($value === null) {
            return $default;
        }

        if (! is_bool($value)) {
            $this->fail($key, 'esperado true ou false, encontrado '.$this->typeName($value).'.');

            return $default;
        }

        return $value;
    }

    /**
     * @param  list<string>  $allowed
     */
    public function enum(string $key, array $allowed, ?string $default): ?string
    {
        $value = $this->take($key);

        if ($value === null) {
            return $default;
        }

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            $this->fail($key, 'valor invalido. Use um de: '.implode(', ', $allowed).'.');

            return $default;
        }

        return $value;
    }

    /**
     * A list of strings, e.g. the site names a site hosts judging for.
     * Returns null when the key is absent, which callers distinguish from
     * an empty list.
     *
     * @return list<string>|null
     */
    public function stringList(string $key, int $max): ?array
    {
        if (! array_key_exists($key, $this->data)) {
            return null;
        }

        $value = $this->take($key);

        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ($value !== [] && array_keys($value) !== range(0, count($value) - 1))) {
            $this->fail($key, 'esperada uma lista, encontrado '.$this->typeName($value).'.');

            return [];
        }

        $out = [];
        foreach ($value as $index => $item) {
            if (! is_string($item) || trim($item) === '') {
                $this->fail($key.'['.$index.']', 'esperado texto nao vazio.');

                continue;
            }

            if (mb_strlen(trim($item)) > $max) {
                $this->fail($key.'['.$index.']', "no maximo {$max} caracteres.");

                continue;
            }

            $out[] = trim($item);
        }

        return $out;
    }

    /**
     * Every key of the mapping that no reader above asked for. Called last;
     * see the class comment for why an unknown key is an error rather than
     * something to ignore.
     */
    public function rejectUnknownKeys(): void
    {
        foreach (array_keys($this->data) as $key) {
            if (! isset($this->read[(string) $key])) {
                $this->errors[] = [
                    'location' => $this->location.'.'.$key,
                    'message' => "campo desconhecido \"{$key}\". Remova-o ou corrija a grafia.",
                ];
            }
        }
    }

    /**
     * Marks a key as read without validating it. Used for keys the caller
     * rejects for its own reasons (contest.is_practice), so that
     * rejectUnknownKeys() does not report the same key a second time under
     * a misleading message.
     */
    public function ignore(string $key): void
    {
        $this->read[$key] = true;
    }

    private function take(string $key): mixed
    {
        $this->read[$key] = true;

        return $this->data[$key] ?? null;
    }

    private function fail(string $key, string $message): void
    {
        $this->errors[] = ['location' => $this->location.'.'.$key, 'message' => $message];
    }

    private function typeName(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'true/false',
            is_int($value), is_float($value) => 'numero',
            is_string($value) => 'texto',
            is_array($value) => array_is_list($value) ? 'lista' : 'mapa',
            default => get_debug_type($value),
        };
    }
}
