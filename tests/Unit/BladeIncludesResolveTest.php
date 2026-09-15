<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every @extends and @include in every Blade file names a template that
 * exists.
 *
 * A missing include is a 500 at render time and nothing before then: the
 * Blade compiler does not resolve the name until the page is actually
 * requested, so a typo -- or a partial deleted from under a template that
 * still names it -- sits there until a person opens that page. Nothing else
 * in this suite would notice, for the same reason nothing noticed that
 * Backup::user() pointed at a class that did not exist: the code was never
 * executed.
 *
 * It had a real finding when it was written. Five templates named four
 * targets that were not there: resources/views/products/* extended a
 * `layout` this application has never had and included
 * `products.partials.errors`/`form` that do not exist, and
 * users/create.blade.php extended `layout.app` (the real one is
 * `layouts.app`). All of it was Laravel-5-era scaffolding -- Spanish
 * tutorial copy, `Form::open()` from a package that is not installed,
 * `route('products.index')` for a route that does not exist -- reachable
 * from nothing, and it was deleted rather than repaired.
 *
 * Deliberately NOT a reachability test. A first attempt tried to prove
 * which templates are unused by walking from every `view()` call, and it
 * was wrong: it reported the whole of features/* as dead, when those are
 * reached by `Route::view()` in routes/frontend.php, and the error pages
 * and vendor/mail overrides that Laravel resolves by convention. "Is this
 * template referenced" is a question with too many answers to test.
 * "Does the template this one names exist" has exactly one.
 */
class BladeIncludesResolveTest extends TestCase
{
    private const VIEW_ROOT = __DIR__.'/../../resources/views';

    /**
     * The one template allowed to name a route that does not exist.
     *
     * auth/passwords/reset.blade.php is 70 lines of this project's own
     * styled UI posting to `password.update`, a route nobody ever wrote --
     * see issue #183. Deleting somebody's finished screen to satisfy a
     * guard would be the wrong trade, and so would weakening the guard for
     * everyone. It is excluded by name, with the issue attached, so the
     * exclusion is a decision someone wrote down rather than a hole.
     */
    private const ROUTE_EXCEPTIONS = ['resources/views/auth/passwords/reset.blade.php'];

    public function test_every_blade_include_and_extends_names_a_template_that_exists(): void
    {
        $broken = [];
        $checked = 0;

        foreach ($this->bladeFiles() as $file) {
            $contents = (string) file_get_contents($file);

            preg_match_all(
                '/@(?:include|includeIf|includeWhen|includeFirst|extends|each)\(\s*[\'"]([a-zA-Z0-9_.\-]+)[\'"]/',
                $contents,
                $matches
            );

            foreach ($matches[1] as $target) {
                $checked++;
                $path = self::VIEW_ROOT.'/'.str_replace('.', '/', $target).'.blade.php';

                if (! is_file($path)) {
                    $broken[] = sprintf('%s names "%s", which does not exist', $this->relative($file), $target);
                }
            }
        }

        // Guards the guard: if the pattern stops matching -- Blade syntax
        // changes, or the views move -- this would pass while measuring
        // nothing, which is the failure it exists to prevent elsewhere.
        $this->assertGreaterThan(
            50,
            $checked,
            'Only '.$checked.' include/extends targets were found, which is fewer than this application has. '
            .'The scan is broken, so this test is passing without measuring anything.'
        );

        $this->assertSame(
            [],
            $broken,
            "These Blade templates name a template that does not exist, and would fail at render time:\n  "
            .implode("\n  ", $broken)
        );
    }

    /**
     * Issue #183 -- the same failure one layer along: `route('foo')` in a
     * Blade throws RouteNotFoundException when `foo` is not registered, and
     * again nothing knows until the page is requested.
     *
     * The regex excludes `->route(` deliberately, and that exclusion is the
     * difference between a useful test and a misleading one: the feature
     * pages call `request()->route('problemId')` to read a route PARAMETER,
     * which has nothing to do with named routes. Without the guard on the
     * preceding character this reported eight nonexistent defects across
     * resources/views/features/ on its first run.
     */
    public function test_every_route_name_used_in_a_blade_is_registered(): void
    {
        $registered = [];

        foreach (Route::getRoutes() as $route) {
            if ($name = $route->getName()) {
                $registered[$name] = true;
            }
        }

        $this->assertNotEmpty($registered, 'No named routes were found at all; the route list did not load.');

        $broken = [];
        $checked = 0;

        foreach ($this->bladeFiles() as $file) {
            if (in_array($this->relative($file), self::ROUTE_EXCEPTIONS, true)) {
                continue;
            }

            preg_match_all(
                '/(?<![>$\w])route\(\s*[\'"]([a-zA-Z0-9_.\-]+)[\'"]/',
                (string) file_get_contents($file),
                $matches
            );

            foreach ($matches[1] as $name) {
                $checked++;

                if (! isset($registered[$name])) {
                    $broken[] = sprintf('%s calls route("%s"), which is not registered', $this->relative($file), $name);
                }
            }
        }

        $this->assertGreaterThan(
            30,
            $checked,
            'Only '.$checked.' route() calls were found in Blade files, which is fewer than this application has.'
        );

        $this->assertSame(
            [],
            $broken,
            "These Blade templates name a route that does not exist, and would throw at render time:\n  "
            .implode("\n  ", $broken)
        );
    }

    /**
     * @return list<string>
     */
    private function bladeFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::VIEW_ROOT, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile() && str_ends_with($entry->getFilename(), '.blade.php')) {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        $root = realpath(self::VIEW_ROOT) ?: self::VIEW_ROOT;

        return 'resources/views'.substr(realpath($path) ?: $path, strlen($root));
    }
}
