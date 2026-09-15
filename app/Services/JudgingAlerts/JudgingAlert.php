<?php

namespace App\Services\JudgingAlerts;

/**
 * Issue #199 -- one thing that is wrong with the judging right now.
 *
 * A value object and not an array because three different places have to
 * agree on its shape: the contest log, the webhook body, and the hysteresis
 * key. `key` is what identifies the condition across invocations -- it must
 * be stable while the same problem persists and different when it is a
 * different problem, because that string is the whole of the state machine.
 */
class JudgingAlert
{
    public function __construct(
        public readonly string $condition,
        public readonly string $key,
        public readonly string $title,
        public readonly string $detail,
        public readonly int $dwellMinutes,
        /**
         * Whether this alert is a STATE or an EVENT, which is the
         * difference between "still broken" and "happened".
         *
         * A state (no machine answering, a backlog) can persist, and
         * saying so again after the cooldown is useful -- an hour later
         * it is news that it is still true. An event (the watchdog threw
         * a submission away) cannot un-happen: the row that records it
         * never changes back, so a repeatable event would announce
         * itself for the rest of the contest and teach the organisation
         * to mute the channel. Announce it once, per key, and stop.
         */
        public readonly bool $repeatable = true,
        /** @var array<string, mixed> */
        public readonly array $context = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'condition' => $this->condition,
            'key' => $this->key,
            'title' => $this->title,
            'detail' => $this->detail,
            'repeatable' => $this->repeatable,
            'context' => $this->context,
        ];
    }
}
