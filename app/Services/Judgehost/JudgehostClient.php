<?php

namespace App\Services\Judgehost;

use App\Http\Middleware\AuthenticateJudgehost;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Issue #116 -- the judge machine's side of the wire.
 *
 * Every call the agent makes to the server, and nothing else. No database,
 * no queue, no shared filesystem: a judgehost in a partner institution's
 * rack reaches the contest through exactly these requests, which is what
 * lets it sit behind their firewall with no inbound port.
 */
class JudgehostClient
{
    public function __construct(
        private string $server,
        private string $token,
        private int $timeout = 30,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            rtrim((string) config('judgehost.agent.server', ''), '/'),
            (string) config('judgehost.agent.token', ''),
            (int) config('judgehost.agent.request_timeout', 30),
        );
    }

    public function isConfigured(): bool
    {
        return $this->server !== '' && $this->token !== '';
    }

    public function server(): string
    {
        return $this->server;
    }

    /**
     * @return array{judgehost: array{id: int, name: string}, reclaimed: int, lease_seconds: int}
     */
    public function register(): array
    {
        return $this->request()->post($this->url('/register'))->throw()->json('data');
    }

    /**
     * The next run this host may judge, or null when there is nothing.
     *
     * 204 is the server saying "nothing to do" and is not an error -- the
     * agent's answer to it is to back off, not to re-register.
     *
     * @return array<string, mixed>|null
     */
    public function fetchWork(): ?array
    {
        $response = $this->request()->post($this->url('/fetch-work'));

        if ($response->status() === 204) {
            return null;
        }

        return $response->throw()->json('data');
    }

    /**
     * Issue #124 -- tell the server this run is still being worked on.
     *
     * Returns false when the server says the claim is over, which is the
     * agent's cue that finishing is pointless: the run has been reaped and
     * given to someone else.
     */
    public function heartbeat(int $runId): bool
    {
        $response = $this->request()->post($this->url("/runs/{$runId}/heartbeat"));

        if (in_array($response->status(), [403, 409], true)) {
            return false;
        }

        $response->throw();

        return true;
    }

    public function giveBack(int $runId): void
    {
        $this->request()->post($this->url("/runs/{$runId}/give-back"))->throw();
    }

    /**
     * Report a verdict.
     *
     * Returns false when the server refused it because this host no longer
     * holds the run -- 403 once the lease is gone, 409 while it is held but
     * the run is no longer open. Neither is a failure of the agent: a host
     * that stalled past its lease has already had the work handed to
     * someone else, and the right response is to drop the result and ask
     * for more work, not to retry.
     */
    public function reportResult(int $runId, array $verdict): bool
    {
        $response = $this->request()->post($this->url("/runs/{$runId}/result"), [
            'verdict' => $verdict['verdict'],
            'message' => $verdict['message'] ?? null,
            'stdout' => $this->clamp($verdict['stdout'] ?? null),
            'stderr' => $this->clamp($verdict['stderr'] ?? null),
        ]);

        if (in_array($response->status(), [403, 409], true)) {
            return false;
        }

        $response->throw();

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function testCases(int $runId): array
    {
        return $this->request()->get($this->url("/runs/{$runId}/testcases"))->throw()->json('data', []);
    }

    public function testCaseBytes(int $runId, int $testCaseId, string $kind): string
    {
        return $this->request()->get($this->url("/runs/{$runId}/testcases/{$testCaseId}/{$kind}"))->throw()->body();
    }

    public function sourceBytes(int $runId): string
    {
        return $this->request()->get($this->url("/runs/{$runId}/source"))->throw()->body();
    }

    /**
     * Issue #120 -- one of the problem's custom scripts. The agent must have
     * every hook the work payload declares before it judges anything.
     */
    public function packageScript(int $runId, string $kind): string
    {
        return $this->request()->get($this->url("/runs/{$runId}/package/{$kind}"))->throw()->body();
    }

    private function url(string $path): string
    {
        return $this->server.'/api/remote-judges/v1'.$path;
    }

    /**
     * Issue #123 -- the fencing token for the claim currently being worked.
     *
     * Held here rather than passed at every call site so no route that
     * names a run can forget it: without it the server cannot tell this
     * process from an older one of the same machine that never noticed it
     * was replaced.
     */
    private ?string $claimToken = null;

    public function useClaim(?string $claimToken): void
    {
        $this->claimToken = $claimToken;
    }

    private function request(): PendingRequest
    {
        $request = Http::withToken($this->token)
            ->timeout($this->timeout)
            ->acceptJson();

        if ($this->claimToken !== null && $this->claimToken !== '') {
            $request = $request->withHeaders([AuthenticateJudgehost::CLAIM_TOKEN_HEADER => $this->claimToken]);
        }

        return $request;
    }

    /**
     * The server's columns hold 65535 bytes; sending more only to have it
     * truncated wastes the partner's uplink on every failing submission.
     */
    private function clamp(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strcut($value, 0, 65535);
    }
}
