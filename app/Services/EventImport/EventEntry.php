<?php

namespace App\Services\EventImport;

/**
 * Issue #147 -- one site, language or problem read out of an event file,
 * already normalised into the column => value shape the model wants.
 *
 * `location` is the path of the entry inside the file ("sites[3]"), and
 * `label` is the value an operator would search the file for ("UFMG",
 * "cpp_gpp13", "abrigo"). Both travel with the entry all the way to the
 * console so that a message about the 37th site of a regional says *which*
 * site, in a format that works for YAML and JSON alike -- neither of which
 * gives a parser a usable line number for a nested node the way a
 * line-oriented format does (see EventFileParser for what is done about
 * syntax errors, where a line number *is* available).
 */
final class EventEntry
{
    /**
     * @param  array<string, mixed>  $attributes  column => value, ready for Model::create()/update()
     * @param  array<string, mixed>  $extras  values that are not columns: judging_routes, package
     */
    public function __construct(
        public readonly string $location,
        public readonly string $label,
        public readonly array $attributes,
        public readonly array $extras = [],
    ) {}

    public function extra(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->extras) ? $this->extras[$key] : $default;
    }

    /**
     * Whether the file said anything at all about this non-column key.
     * Distinguishes "absent" from "present and empty", which matters for
     * judging_routes: an empty list means "this site hosts nobody else's
     * runs" and has to erase existing routes.
     */
    public function hasExtra(string $key): bool
    {
        return array_key_exists($key, $this->extras);
    }
}
