<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Issue #166 -- the frontend must not need runtime template compilation.
 *
 * Every page in the application had dead JavaScript from #90 until #144,
 * because the Vue island loop handed each element's outerHTML to
 * createApp() as a `template` string. Vue compiles a string template with
 * `new Function`, and the CSP has script-src 'self' plus a nonce and no
 * 'unsafe-eval'. The EvalError fired at module top level, so nothing after
 * it ran, on every page load.
 *
 * These are static checks on source, not a browser. That is a deliberate
 * trade: a real browser smoke test would be the honest guard and needs a
 * browser in CI, which does not exist yet (#166 says so). These cost
 * nothing and catch the specific way this broke.
 *
 * They exist because the obvious guard turned out not to be one. Removing
 * the esm-bundler alias was supposed to make a reintroduced template "fail
 * loudly". Measured instead of assumed: the build still succeeds, and in a
 * production build Vue emits neither an error nor a warning -- the
 * component simply renders nothing. Better than taking the page down with
 * it, and still silent.
 */
class FrontendCspCompatibilityTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 2).'/'.$relative;

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_vite_does_not_alias_vue_to_the_compiler_build(): void
    {
        // vue/dist/vue.esm-bundler.js carries the runtime template compiler,
        // which is the thing that calls new Function.
        // Comments naming the build we must NOT use are fine -- there is one
        // there explaining exactly this -- so only real code is checked.
        $config = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $this->read('vite.config.js')) ?? '';

        $this->assertStringNotContainsString(
            'vue.esm-bundler',
            $config,
            'Aliasing vue to the compiler build reintroduces runtime template compilation, '
            .'which the CSP refuses -- see issue #166.'
        );
    }

    public function test_no_entrypoint_hands_a_template_string_to_create_app(): void
    {
        foreach (['resources/js/app.js', 'resources/js/features/mount.js'] as $file) {
            $source = $this->read($file);

            // Comments discussing the bug are fine; an actual option is not.
            $withoutComments = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source) ?? $source;

            $this->assertDoesNotMatchRegularExpression(
                '/\btemplate\s*:/',
                $withoutComments,
                "{$file} passes a `template` option. A string template is compiled at runtime with "
                .'new Function, which the CSP refuses; in a production build this fails silently and '
                .'the component renders nothing -- see issue #166.'
            );
        }
    }
}
