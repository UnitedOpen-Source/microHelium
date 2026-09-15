<?php

namespace App\Services\JudgingAlerts;

use App\Models\Contest;
use App\Models\ContestLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Issue #199 -- decides whether a condition is worth waking someone over,
 * and then does the waking.
 *
 * The hard part of alerting is not detecting the problem; it is not crying
 * wolf. Two mechanisms, and both are load-bearing:
 *
 * - **Histerese.** A condition has to hold for its dwell before it becomes
 *   an alert. Restarting a judgehost during the lunch break must not page
 *   anyone, and a queue that spikes for ninety seconds while a batch
 *   compiles is not a queue that is stuck.
 *
 * - **Cooldown.** While a condition keeps holding, it is repeated at most
 *   once per `repeat_after_minutes`. An alert that fires every five minutes
 *   for three hours is an alert somebody mutes, and a muted channel does
 *   not carry the next problem either.
 *
 * State lives in the cache, keyed per contest and per alert key, because
 * this is operational liveness and not contest data: losing it on a cache
 * flush costs one duplicated alert, which is the right way round. It is
 * exactly the reasoning ReconcileStuckRunsCommand::LAST_RUN_KEY already
 * follows.
 */
class JudgingAlertDispatcher
{
    /**
     * A day, not forever: when a contest ends its entries should stop
     * existing on their own rather than accumulating one set per event for
     * the life of the installation.
     */
    private const STATE_TTL_HOURS = 24;

    /**
     * @param  list<JudgingAlert>  $alerts
     * @return list<array{event: string, alert: JudgingAlert|string}> what was actually announced
     */
    public function dispatch(Contest $contest, array $alerts): array
    {
        $announced = [];
        $firing = [];

        foreach ($alerts as $alert) {
            $firing[] = $alert->key;
            $event = $this->consider($contest, $alert);

            if ($event !== null) {
                $announced[] = ['event' => $event, 'alert' => $alert];
            }
        }

        foreach ($this->resolveDeparted($contest, $firing) as $key) {
            $announced[] = ['event' => 'resolved', 'alert' => $key];
        }

        return $announced;
    }

    /**
     * @return string|null 'triggered', 'reminder', or null for "not yet, or not again"
     */
    private function consider(Contest $contest, JudgingAlert $alert): ?string
    {
        $state = $this->readState($contest, $alert->key);
        $now = now();

        if ($state === null) {
            // First tick in this state. The clock starts; nothing is said
            // yet, which is the whole point of the dwell.
            $this->writeState($contest, $alert->key, ['since' => $now->toISOString(), 'notified_at' => null]);
            $this->track($contest, $alert->key);

            if ($alert->dwellMinutes > 0) {
                return null;
            }

            $state = ['since' => $now->toISOString(), 'notified_at' => null];
        }

        $since = Carbon::parse($state['since']);

        if ($since->copy()->addMinutes($alert->dwellMinutes)->gt($now)) {
            return null;
        }

        $notifiedAt = isset($state['notified_at']) && is_string($state['notified_at'])
            ? Carbon::parse($state['notified_at'])
            : null;

        if ($notifiedAt === null) {
            $this->announce($contest, $alert, 'triggered');
            $this->writeState($contest, $alert->key, ['since' => $state['since'], 'notified_at' => $now->toISOString()]);

            return 'triggered';
        }

        if (! $alert->repeatable) {
            return null;
        }

        $repeatAfter = (int) config('judging.alerts.repeat_after_minutes', 60);

        if ($repeatAfter <= 0 || $notifiedAt->copy()->addMinutes($repeatAfter)->gt($now)) {
            return null;
        }

        $this->announce($contest, $alert, 'reminder');
        $this->writeState($contest, $alert->key, ['since' => $state['since'], 'notified_at' => $now->toISOString()]);

        return 'reminder';
    }

    /**
     * Conditions that were being tracked and are no longer firing.
     *
     * Saying that judging came back is not a courtesy: without it the
     * organisation has to keep checking a screen to find out whether the
     * thing they were told about is still true, which is the situation this
     * issue exists to end. Only announced for conditions that were actually
     * announced -- a condition that never got past its dwell was never
     * anybody's problem.
     *
     * @param  list<string>  $firing
     * @return list<string> keys whose recovery was announced
     */
    private function resolveDeparted(Contest $contest, array $firing): array
    {
        $resolved = [];
        $tracked = $this->tracked($contest);
        $remaining = [];

        foreach ($tracked as $key) {
            if (in_array($key, $firing, true)) {
                $remaining[] = $key;

                continue;
            }

            $state = $this->readState($contest, $key);
            Cache::forget($this->stateKey($contest, $key));

            // A state whose entry expired is dropped silently: announcing
            // the recovery of something we can no longer prove we reported
            // would be inventing the good news as well as the bad.
            if ($state !== null && ! empty($state['notified_at'])) {
                ContestLog::info($contest->id, "Alerta de julgamento resolvido: {$key}", [
                    'event' => 'judging_alert',
                    'status' => 'resolved',
                    'key' => $key,
                ]);

                $this->webhook([
                    'event' => 'resolved',
                    'generated_at' => now()->toISOString(),
                    'contest' => ['id' => $contest->id, 'name' => $contest->name],
                    'alert' => ['key' => $key],
                ]);

                $resolved[] = $key;
            }
        }

        $this->writeTracked($contest, $remaining);

        return $resolved;
    }

    private function announce(Contest $contest, JudgingAlert $alert, string $event): void
    {
        ContestLog::warning($contest->id, $alert->title, array_merge([
            'event' => 'judging_alert',
            'status' => $event,
            'key' => $alert->key,
            'condition' => $alert->condition,
            'detail' => $alert->detail,
        ], $alert->context));

        $this->webhook([
            'event' => $event,
            'generated_at' => now()->toISOString(),
            'contest' => ['id' => $contest->id, 'name' => $contest->name],
            'alert' => $alert->toArray(),
        ]);
    }

    /**
     * Deliver to whatever the organisation plugged in on the other side.
     *
     * Never throws. This runs inside the scheduler, alongside the watchdog
     * that recovers stuck runs; an unreachable chat server must not be able
     * to take that down. A failed delivery is logged and the contest log
     * entry above already happened, so the information is not lost either
     * way -- it is just slower to reach anyone.
     *
     * @param  array<string, mixed>  $payload
     */
    private function webhook(array $payload): void
    {
        $url = (string) config('judging.alerts.webhook.url', '');

        if ($url === '') {
            return;
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $secret = (string) config('judging.alerts.webhook.secret', '');

        $headers = ['Content-Type' => 'application/json'];

        if ($secret !== '' && is_string($body)) {
            $headers['X-Microhelium-Signature'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        try {
            Http::withHeaders($headers)
                ->timeout((int) config('judging.alerts.webhook.timeout', 5))
                ->withBody((string) $body, 'application/json')
                ->post($url);
        } catch (\Throwable $e) {
            Log::warning('judging alert webhook failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array{since: string, notified_at: string|null}|null
     */
    private function readState(Contest $contest, string $key): ?array
    {
        $state = Cache::get($this->stateKey($contest, $key));

        return is_array($state) && isset($state['since']) ? $state : null;
    }

    /**
     * @param  array{since: string, notified_at: string|null}  $state
     */
    private function writeState(Contest $contest, string $key, array $state): void
    {
        Cache::put($this->stateKey($contest, $key), $state, now()->addHours(self::STATE_TTL_HOURS));
    }

    private function stateKey(Contest $contest, string $key): string
    {
        return "judging.alerts.state.{$contest->id}.{$key}";
    }

    /**
     * The cache cannot be enumerated, so which keys are being tracked has
     * to be written down. Without it, a condition that stops firing is
     * simply forgotten and nobody is ever told it recovered.
     *
     * @return list<string>
     */
    private function tracked(Contest $contest): array
    {
        $tracked = Cache::get("judging.alerts.tracked.{$contest->id}");

        return is_array($tracked) ? array_values(array_filter($tracked, 'is_string')) : [];
    }

    private function track(Contest $contest, string $key): void
    {
        $tracked = $this->tracked($contest);

        if (! in_array($key, $tracked, true)) {
            $tracked[] = $key;
            $this->writeTracked($contest, $tracked);
        }
    }

    /**
     * @param  list<string>  $keys
     */
    private function writeTracked(Contest $contest, array $keys): void
    {
        Cache::put("judging.alerts.tracked.{$contest->id}", array_values($keys), now()->addHours(self::STATE_TTL_HOURS));
    }
}
