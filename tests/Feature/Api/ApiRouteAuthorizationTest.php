<?php

namespace Tests\Feature\Api;

use App\Models\Clarification;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use Helium\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #134 -- the authorization matrix of routes/api.php, checked against
 * the live route list rather than against a hand-written sample of calls.
 *
 * The vulnerability this guards was not "somebody forgot a check on
 * DELETE /problems/{id}". It was structural: routes/api.php was one
 * `auth:sanctum` group, `auth:sanctum` authenticates without authorizing,
 * and so every route that did not carry its own `role:` was reachable with
 * a competitor's token -- deleting the running contest included. A test
 * that spelled out six calls would pin those six and let the seventh route
 * someone adds next month be born open exactly the same way.
 *
 * So this walks Route::getRoutes(), keeps everything behind `auth:sanctum`,
 * and requires each of those to be named in one of the two lists below.
 * An unclassified route fails test_every_sanctum_api_route_is_classified
 * with its method and URI in the message: the author of the new route has
 * to state who it is for, and the other tests then hold them to it.
 *
 * The `auth:sanctum` filter is what scopes this to routes/api.php's
 * bearer-token contract. /api/frontend/* (web session + CSRF, see
 * docs/specs/README.md), /api/remote-judges/* (judgehost.auth) and
 * /api/webcast/* (webcast.auth) share the URI prefix only by accident and
 * have their own guards and their own tests.
 */
class ApiRouteAuthorizationTest extends TestCase
{
    /**
     * Reachable with any authenticated token, a team's included.
     *
     * Reads a competitor needs to play, plus the two writes that are a
     * team's own job. Row-level scoping inside these actions (own runs, own
     * clarifications plus broadcasts) is not this test's subject -- see
     * Tests\Integration\Api\RunControllerTest and
     * ClarificationControllerTest. All this asserts is that the route does
     * not answer 403, because locking one of these by mistake takes the
     * contest away from the people competing in it just as surely as
     * leaving a destructive one open.
     */
    private const TEAM_REACHABLE = [
        'GET api/user',
        // Issue #159 -- a caller's own API tokens. Every account has to be
        // able to see and withdraw the credentials issued in its name, so
        // these sit on the competitor surface; the scoping that keeps one
        // team out of another's tokens is ownership inside
        // Api\TokenController, and is pinned by
        // Tests\Feature\Api\ApiTokenIssuanceTest.
        'GET api/tokens',
        'DELETE api/tokens/current',
        'DELETE api/tokens/{token}',
        'GET api/contests',
        'GET api/contests/{contest}',
        'GET api/contests/{contest}/status',
        'GET api/contests/{contest}/scoreboard',
        'GET api/contests/{contest}/my-score',
        'GET api/problems',
        'GET api/problems/{problem}',
        'GET api/problems/{problem}/download',
        'GET api/clarifications',
        'POST api/clarifications',
        'GET api/clarifications/{clarification}',
        'GET api/runs',
        'POST api/runs',
        'GET api/runs/{run}',
        'GET api/runs/{run}/source',
    ];

    /**
     * TEAM_REACHABLE entries the cross-contest walk cannot ask its question
     * of, and why. Kept as a list rather than as an `if` inside the loop so
     * that skipping a route is a decision someone writes down.
     *
     * GET /user returns the caller's own account and no contest rows at all.
     * The same is true of /tokens (#159): a personal access token belongs to
     * a user, not to a contest, so "another contest's row" is not a
     * question that can be asked of it -- the boundary that matters there is
     * ownership, and ApiTokenIssuanceTest::
     * test_a_user_cannot_revoke_another_users_token asserts it.
     *
     * The two writes are checked by
     * test_a_team_cannot_write_into_a_contest_it_does_not_compete_in, which
     * has to build a valid payload to get past validation and reach the
     * boundary; test_every_sanctum_api_route_is_classified holds that list
     * to every non-GET route on the competitor surface that is not listed
     * here, so a new one cannot skip the axis by not being mentioned --
     * only by someone writing down, in this list, why the axis does not
     * exist for it.
     */
    private const NO_CONTEST_DIMENSION = [
        'GET api/user',
        'GET api/tokens',
        'DELETE api/tokens/current',
        'DELETE api/tokens/{token}',
    ];

    private const TEAM_WRITES = [
        'POST api/clarifications',
        'POST api/runs',
    ];

    /**
     * Judge/admin or admin only -- a team token must be refused outright.
     *
     * Everything that changes the shape of the event (contest lifecycle,
     * problem set, clarification queue, verdicts) or that discloses
     * material a competitor must not have: the problem package carries the
     * hidden input/ and output/ test data, and the scoreboard export and
     * statistics both read the standings with the freeze not applied.
     */
    private const STAFF_ONLY = [
        'POST api/contests',
        'PUT api/contests/{contest}',
        'PATCH api/contests/{contest}',
        'DELETE api/contests/{contest}',
        'POST api/contests/{contest}/activate',
        'POST api/contests/{contest}/deactivate',
        'GET api/contests/{contest}/scoreboard/export',
        'GET api/contests/{contest}/statistics',
        'GET api/clarifications/pending',
        'DELETE api/clarifications/{clarification}',
        'PUT api/clarifications/{clarification}/answer',
        'POST api/problems',
        'PUT api/problems/{problem}',
        'PATCH api/problems/{problem}',
        'DELETE api/problems/{problem}',
        'GET api/problems/{problem}/export',
        // Issue #153: the hidden cases' disk paths and sha256 digests --
        // an oracle a competitor can check a guessed input or output
        // against. Was part of GET /problems/{problem}'s body until it was
        // moved here.
        'GET api/problems/{problem}/test-cases',
        'POST api/runs/{run}/rejudge',
        'PUT api/runs/{run}/judge',
    ];

    public function test_every_sanctum_api_route_is_classified(): void
    {
        $classified = array_merge(self::TEAM_REACHABLE, self::STAFF_ONLY);
        $registered = $this->sanctumApiRoutes();

        $unclassified = array_diff($registered, $classified);

        $this->assertEmpty(
            $unclassified,
            'These routes/api.php routes are behind auth:sanctum but this test does not say who may call them. '
            .'auth:sanctum on its own authenticates without authorizing (issue #134), so an unlisted route is an '
            .'open one until proven otherwise: add each to TEAM_REACHABLE or to STAFF_ONLY, and gate it in '
            .'routes/api.php to match. Unclassified: '.implode(', ', $unclassified)
        );

        $stale = array_diff($classified, $registered);

        $this->assertEmpty(
            $stale,
            'This test classifies routes that no longer exist -- drop them from the lists: '.implode(', ', $stale)
        );

        $unlistedWrites = array_diff(
            array_filter(self::TEAM_REACHABLE, fn ($route) => ! str_starts_with($route, 'GET ')),
            self::TEAM_WRITES,
            self::NO_CONTEST_DIMENSION
        );

        $this->assertEmpty(
            $unlistedWrites,
            'A write on the competitor surface that the cross-contest test does not cover. Being allowed to call a '
            .'route is not being allowed to act on every contest it names, so add it to TEAM_WRITES and assert the '
            .'boundary -- or, if the route genuinely has no contest dimension, say so in NO_CONTEST_DIMENSION and '
            .'assert whatever boundary it does have: '.implode(', ', $unlistedWrites)
        );
    }

    public function test_a_team_token_is_forbidden_from_every_staff_only_route(): void
    {
        $team = $this->createTestUser(['user_type' => 'team']);

        foreach (self::STAFF_ONLY as $route) {
            [$method, $uri] = explode(' ', $route, 2);

            // Fresh fixtures per route: the list contains destructive calls
            // (DELETE /contests/{contest}) whose 403 must be proved against
            // a row that really exists -- a 404 from a contest an earlier
            // iteration deleted would pass nothing.
            Sanctum::actingAs($team);
            $response = $this->json($method, $this->resolve($uri));

            $response->assertStatus(403, "A team token reached {$route}, which is staff-only.");
        }
    }

    public function test_a_team_token_still_reaches_everything_a_competitor_needs(): void
    {
        $team = $this->createTestUser(['user_type' => 'team']);

        foreach (self::TEAM_REACHABLE as $route) {
            [$method, $uri] = explode(' ', $route, 2);

            Sanctum::actingAs($team);
            $response = $this->json($method, $this->resolve($uri, $team));

            // Not "asserts 200": POST /runs without a body is a legitimate
            // 422 and a source file that was never written to disk is a
            // legitimate 404. The claim is only that authorization is not
            // what turned the competitor away.
            $this->assertNotSame(
                403,
                $response->getStatusCode(),
                "A team was refused {$route}, which a competitor needs in order to compete."
            );
        }
    }

    public function test_an_admin_token_reaches_every_staff_only_route(): void
    {
        $admin = $this->createAdminUser();

        foreach (self::STAFF_ONLY as $route) {
            [$method, $uri] = explode(' ', $route, 2);

            Sanctum::actingAs($admin);
            $response = $this->json($method, $this->resolve($uri));

            $this->assertNotSame(
                403,
                $response->getStatusCode(),
                "An admin was refused {$route}. The gate is meant to keep competitors out, not staff."
            );
        }
    }

    /**
     * Issue #134, second axis: reading ANOTHER contest's rows through a
     * route a team is genuinely allowed to call.
     *
     * "Not 403" is the wrong question to stop at, and the earlier version of
     * this file stopped there -- GET /problems answered 200 while listing
     * every problem in the installation, and GET /contests/{other} answered
     * 200 with a private, unstarted event. Api\RunController was already
     * scoping rows to their owner; Api\ProblemController and
     * Api\ContestController scoped nothing at all.
     *
     * The treatment is derived from the URI rather than listed per route: a
     * URI with a placeholder gets pointed at the other contest's row and has
     * to refuse; a collection URI has to come back without that row in it,
     * and -- the half that keeps this honest -- WITH the team's own.
     */
    public function test_a_team_is_never_handed_another_contests_rows(): void
    {
        $mine = Contest::factory()->create(['is_public' => false]);
        $team = $this->createTestUser(['contest_id' => $mine->id]);
        $own = $this->fixturesFor($mine, $team);

        // Private and not running: the pre-contest case, where the problem
        // set exists and the statements are already on disk.
        $other = Contest::factory()->create(['is_public' => false, 'is_active' => false]);
        $otherTeam = $this->createTestUser(['contest_id' => $other->id]);
        $theirs = $this->fixturesFor($other, $otherTeam, broadcast: true);

        Sanctum::actingAs($team);

        foreach (self::TEAM_REACHABLE as $route) {
            if (in_array($route, self::NO_CONTEST_DIMENSION, true) || in_array($route, self::TEAM_WRITES, true)) {
                continue;
            }

            [$method, $uri] = explode(' ', $route, 2);

            if (str_contains($uri, '{')) {
                $status = $this->json($method, $this->fill($uri, $theirs))->getStatusCode();

                $this->assertContains(
                    $status,
                    [403, 404],
                    "{$route} answered {$status} for a row belonging to a contest the team is not in."
                );

                continue;
            }

            $ids = array_column($this->json($method, '/'.$uri)->json('data') ?? [], 'id');
            $resource = explode('/', $uri)[1];

            $this->assertNotContains(
                $theirs[$resource]->getKey(),
                $ids,
                "{$route} listed a row from another contest."
            );

            $this->assertContains(
                $own[$resource]->getKey(),
                $ids,
                "{$route} stopped listing the team's own row -- the scoping is too tight, not just tight."
            );
        }
    }

    /**
     * The write half of the same boundary: a team may submit and ask
     * questions, but only in the contest it competes in. Both actions used
     * to check that the contest was running and that the problem/language
     * belonged to it, and never that the caller did.
     */
    public function test_a_team_cannot_write_into_a_contest_it_does_not_compete_in(): void
    {
        $team = $this->createTestUser(['contest_id' => Contest::factory()->create()->id]);

        // Public and running, so that nothing except membership can be what
        // refuses these.
        $other = Contest::factory()->has(Site::factory())->create([
            'is_public' => true,
            'is_active' => true,
            'start_time' => now(),
        ]);
        $problem = Problem::factory()->create(['contest_id' => $other->id]);
        $language = Language::factory()->create(['contest_id' => $other->id, 'is_active' => true]);

        Sanctum::actingAs($team);

        $this->postJson('/api/clarifications', [
            'contest_id' => $other->id,
            'problem_id' => $problem->id,
            'question' => 'Qual e a entrada do caso 3?',
        ])->assertStatus(403);

        Storage::fake('local');

        $this->postJson('/api/runs', [
            'contest_id' => $other->id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'source_file' => UploadedFile::fake()->create('solution.cpp', 10, 'text/plain'),
        ])->assertStatus(403);
    }

    /**
     * The judge queue is the case that made the route order load-bearing:
     * /clarifications/pending has to be matched before the {clarification}
     * placeholder of clarifications.show, which lives in the other group
     * now. Without the whereNumber() constraint in routes/api.php this
     * answers 404 to the judge whose queue it is.
     */
    public function test_a_judge_reaches_the_clarification_queue(): void
    {
        $contest = Contest::factory()->create();
        $judge = $this->createTestUser(['user_type' => 'judge', 'contest_id' => $contest->id]);

        Sanctum::actingAs($judge);

        $this->getJson('/api/clarifications/pending')->assertStatus(200);
    }

    /**
     * 'METHOD api/uri' for every route in routes/api.php's auth:sanctum
     * group, HEAD dropped (Laravel registers it alongside every GET).
     *
     * @return list<string>
     */
    private function sanctumApiRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = $method.' '.$route->uri();
            }
        }

        sort($routes);

        return array_values(array_unique($routes));
    }

    /**
     * One row of each contest-owned resource, keyed by the URI segment that
     * names it, so the walk above can look up "the other contest's
     * {problems} row" straight from the route it is testing.
     *
     * The foreign clarification is a broadcast on purpose: a private one is
     * refused by the "not yours" check that was already there, and would
     * prove nothing about the contest boundary.
     *
     * @return array<string, Model>
     */
    private function fixturesFor(Contest $contest, User $owner, bool $broadcast = false): array
    {
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);

        return [
            'contests' => $contest,
            'problems' => $problem,
            'runs' => Run::factory()->create([
                'contest_id' => $contest->id,
                'problem_id' => $problem->id,
                'user_id' => $owner->user_id,
            ]),
            'clarifications' => Clarification::factory()->create([
                'contest_id' => $contest->id,
                'problem_id' => $problem->id,
                'user_id' => $owner->user_id,
                'status' => $broadcast ? 'broadcast_all' : 'pending',
            ]),
        ];
    }

    /**
     * @param  array<string, Model>  $rows
     */
    private function fill(string $uri, array $rows): string
    {
        return '/'.str_replace(
            ['{contest}', '{problem}', '{run}', '{clarification}'],
            [
                $rows['contests']->getKey(),
                $rows['problems']->getKey(),
                $rows['runs']->getKey(),
                $rows['clarifications']->getKey(),
            ],
            $uri
        );
    }

    /**
     * Substitute real ids into a route URI. The rows belong to $owner when
     * one is given, so that the competitor-surface assertions measure the
     * route's gate and not Api\RunController's separate "this run is not
     * yours" check, which has its own tests.
     */
    private function resolve(string $uri, ?User $owner = null): string
    {
        $contest = Contest::factory()->create();
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);

        $ownerUser = $owner ?? $this->createTestUser();
        $ownerId = $ownerUser->user_id;

        // Issue #159 -- a real row, so that DELETE /tokens/{token} is
        // measured rather than answered 404 by a URI that still has a
        // literal placeholder in it. whereNumber('token') would turn
        // "{token}" into a 404, and a 404 is "not 403": the walk would pass
        // while never reaching the route.
        $tokenId = $ownerUser->createToken('walk')->accessToken->getKey();

        $run = Run::factory()->create([
            'contest_id' => $contest->id,
            'problem_id' => $problem->id,
            'user_id' => $ownerId,
        ]);

        $clarification = Clarification::factory()->create([
            'contest_id' => $contest->id,
            'problem_id' => $problem->id,
            'user_id' => $ownerId,
        ]);

        return '/'.str_replace(
            ['{contest}', '{problem}', '{run}', '{clarification}', '{token}'],
            [$contest->id, $problem->id, $run->id, $clarification->id, $tokenId],
            $uri
        );
    }
}
