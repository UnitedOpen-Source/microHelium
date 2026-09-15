<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

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
