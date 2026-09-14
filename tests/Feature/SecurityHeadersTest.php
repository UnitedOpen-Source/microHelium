<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Issue #90 -- the headers a ZAP baseline scan found missing when it hit the
 * application directly instead of through docker/nginx/default.conf.
 */
class SecurityHeadersTest extends TestCase
{
    public function test_every_response_carries_the_security_headers(): void
    {
        $response = $this->get('/login')->assertOk();

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $response->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');

        $this->assertStringContainsString('camera=()', $response->headers->get('Permissions-Policy'));
    }

    public function test_the_policy_refuses_inline_script_and_objects(): void
    {
        $csp = $this->get('/login')->assertOk()->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);
        // The whole point: an injected <script> without the nonce does not run.
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        // 'self' matches the X-Frame-Options: SAMEORIGIN this app already
        // sent; CSP wins over that header where both are understood, so the
        // two must agree.
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
    }

    /**
     * Issue #143 -- style-src used to carry 'unsafe-inline', justified in a
     * comment by "Vue's scoped styles and the components' inline style
     * bindings". That did not survive checking: resources/js has no :style
     * bindings and no SFC <style> blocks, and Vite extracts component CSS to
     * a file at build time. The only inline styles were three Blade style=
     * attributes, and they are gone.
     *
     * Without this test the directive can drift back silently, because
     * nothing about a page looks different when it does.
     */
    public function test_the_policy_refuses_inline_style(): void
    {
        $csp = $this->get('/login')->assertOk()->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);
        $this->assertStringNotContainsString("'unsafe-inline'", $csp);
        // Not merely absent: style-src has to still permit the one nonced
        // block the dynamic contest colours are rendered into, or those
        // colours silently stop applying.
        $this->assertMatchesRegularExpression("/style-src 'self' 'nonce-[A-Za-z0-9]+'/", $csp);
    }

    public function test_the_origin_is_cross_origin_isolated(): void
    {
        // COOP and CORP were already set; COEP is the one that makes the
        // trio mean anything. It costs nothing while every resource is
        // same-origin, and refuses the first third-party embed -- which is
        // the point of having it before anyone adds one.
        $this->get('/login')->assertOk()
            ->assertHeader('Cross-Origin-Embedder-Policy', 'require-corp');
    }

    /**
     * The nonce the header advertises must be the nonce the views received,
     * or every nonced block is dead markup that the browser drops.
     */
    public function test_views_receive_the_same_nonce_the_header_advertises(): void
    {
        $response = $this->get('/login')->assertOk();

        preg_match("/'nonce-([A-Za-z0-9]+)'/", (string) $response->headers->get('Content-Security-Policy'), $header);
        $this->assertNotEmpty($header[1] ?? null, 'the policy must carry a nonce');

        $this->assertSame($header[1], view()->shared('cspNonce'));
    }

    public function test_the_rendered_inline_script_carries_the_nonce_from_the_header(): void
    {
        $response = $this->get('/login')->assertOk();

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertSame(1, preg_match("/'nonce-([A-Za-z0-9]+)'/", $csp, $matches), $csp);
        $nonce = $matches[1];

        // partials/theme-init.blade.php runs before paint on every page. If
        // the directive and the header ever drift apart, the theme flash
        // comes back and nobody connects it to a CSP change -- so this is
        // asserted rather than assumed.
        $response->assertSee('nonce="'.$nonce.'"', false);
    }

    public function test_the_nonce_is_not_reused_between_requests(): void
    {
        $first = $this->get('/login')->headers->get('Content-Security-Policy');
        $second = $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertNotSame($first, $second, 'a nonce reused across responses is not a nonce');
    }

    public function test_hsts_is_sent_only_over_tls(): void
    {
        // Plain HTTP: browsers ignore it anyway, and sending it from a dev
        // server would pin localhost to https for the developer.
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_a_controllers_own_cache_control_is_not_overwritten(): void
    {
        // The practice API sets its own; the middleware must not clobber a
        // header a controller chose deliberately.
        $this->getJson('/api/frontend/practice/problems')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }
}
