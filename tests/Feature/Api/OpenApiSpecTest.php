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
     * serving route.
     */
    private const EXCLUDED_PATHS = ['/openapi.yaml'];

    /**
     * `/api/frontend/*` (see routes/frontend_api_similarity.php and
     * docs/specs/README.md) shares the "api/" URI prefix with this file's
     * subject but is a completely different contract: web session + CSRF
     * for the app's own Vue features, documented per-feature under
     * docs/specs/*.md, not the bearer-token Sanctum surface this
     * hand-maintained spec describes. Matched by prefix so future
     * /api/frontend/* features don't each need a new literal entry here.
     */
    private const EXCLUDED_PREFIXES = ['/frontend/'];

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
            ->map(fn ($route) => '/' . substr($route->uri(), strlen('api/')))
            ->unique()
            ->reject(fn ($path) => in_array($path, self::EXCLUDED_PATHS, true)
                || collect(self::EXCLUDED_PREFIXES)->contains(fn ($prefix) => str_starts_with($path, $prefix)))
            ->values()
            ->all();

        $undocumented = array_diff($registeredPaths, $documentedPaths);

        $this->assertEmpty(
            $undocumented,
            'These API routes have no matching path in docs/api/openapi.yaml: ' . implode(', ', $undocumented)
        );
    }

    public function test_the_spec_does_not_document_paths_that_no_longer_exist()
    {
        $spec = Yaml::parseFile(base_path('docs/api/openapi.yaml'));
        $documentedPaths = array_keys($spec['paths']);

        $registeredPaths = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'))
            ->map(fn ($route) => '/' . substr($route->uri(), strlen('api/')))
            ->unique()
            ->values()
            ->all();

        $stale = array_diff($documentedPaths, $registeredPaths);

        $this->assertEmpty(
            $stale,
            'docs/api/openapi.yaml documents paths that no longer have a matching route: ' . implode(', ', $stale)
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
