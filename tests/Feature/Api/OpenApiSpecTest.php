<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Issue #52: docs/api/openapi.yaml is hand-maintained, not generated -- this
 * test is the drift guard. It fails whenever a route is added to
 * routes/api.php without a matching entry being added to the spec, instead
 * of that silently going undocumented.
 */
class OpenApiSpecTest extends TestCase
{
    /**
     * Routes that intentionally have no OpenAPI entry: the spec file's own
     * serving route, and the root of the Contest API (#195) -- which the
     * prefix below does not cover, because that route has no trailing
     * slash. Listing it here rather than loosening the prefix to '/clics'
     * keeps the exclusion to exactly the paths that exist.
     */
    private const EXCLUDED_PATHS = ['/openapi.yaml', '/clics'];

    /**
     * Path prefixes that intentionally have no OpenAPI entry: the
     * /api/frontend/* endpoints (see routes/frontend_api_similarity.php,
     * routes/frontend_api_bank_governance.php,
     * routes/frontend_api_managed_accounts.php) are registered on the web
     * session guard + CSRF for the app's own Vue features, not
     * routes/api.php's auth:sanctum contract this spec documents (see the
     * file header above). They share the literal "api/" URI prefix only
     * incidentally; their contract is documented per-feature under
     * docs/specs/*.md instead.
     *
     * Issue #195 adds /api/clics/* for the same reason, one step stronger:
     * those routes implement an EXTERNAL, versioned specification -- the
     * ICPC Contest API at ccs-specs.icpc.io -- whose normative contract
     * lives there and not here. Re-describing it in this file would produce
     * a second, local copy of someone else's spec, and the failure mode of
     * a copy is that it drifts from the original while looking
     * authoritative. What IS documented here is the mapping from this
     * system's objects onto that vocabulary: docs/specs/195-contest-api.md.
     */
    private const EXCLUDED_PREFIXES = ['/frontend/', '/clics/'];

    public function test_spec_file_is_valid_yaml_with_a_paths_section()
    {
        $spec = Yaml::parseFile(base_path('docs/api/openapi.yaml'));

        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertArrayHasKey('paths', $spec);
        $this->assertNotEmpty($spec['paths']);
    }

    public function test_every_registered_api_route_is_documented_in_the_spec()
    {
        $spec = Yaml::parseFile(base_path('docs/api/openapi.yaml'));
        $documentedPaths = array_keys($spec['paths']);

        $registeredPaths = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
            ->map(fn ($route) => '/'.substr($route->uri(), strlen('api/')))
            ->unique()
            ->reject(fn ($path) => in_array($path, self::EXCLUDED_PATHS, true))
            ->reject(fn ($path) => collect(self::EXCLUDED_PREFIXES)->contains(fn ($prefix) => str_starts_with($path, $prefix)))
            ->values()
            ->all();

        $undocumented = array_diff($registeredPaths, $documentedPaths);

        $this->assertEmpty(
            $undocumented,
            'These API routes have no matching path in docs/api/openapi.yaml: '.implode(', ', $undocumented)
        );
    }

    public function test_the_spec_does_not_document_paths_that_no_longer_exist()
    {
        $spec = Yaml::parseFile(base_path('docs/api/openapi.yaml'));
        $documentedPaths = array_keys($spec['paths']);

        $registeredPaths = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
            ->map(fn ($route) => '/'.substr($route->uri(), strlen('api/')))
            ->unique()
            ->values()
            ->all();

        $stale = array_diff($documentedPaths, $registeredPaths);

        $this->assertEmpty(
            $stale,
            'docs/api/openapi.yaml documents paths that no longer have a matching route: '.implode(', ', $stale)
        );
    }

    public function test_openapi_yaml_route_serves_the_spec_file()
    {
        $response = $this->get('/api/openapi.yaml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/yaml');
        $this->assertStringContainsString('microHelium API', $response->getContent());
    }
}
