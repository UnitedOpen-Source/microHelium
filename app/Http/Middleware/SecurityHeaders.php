<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issue #90 -- response security headers, set by the application rather
 * than by one deployment's web server.
 *
 * docker/nginx/default.conf already sent X-Frame-Options,
 * X-Content-Type-Options and Referrer-Policy, but that only covers a
 * deployment running that exact nginx config. A ZAP baseline scan against
 * the app directly found no security headers at all -- which is also what
 * `php artisan serve` gives every developer, and what any other reverse
 * proxy in front of PHP-FPM would give. Headers that matter belong with the
 * response, not with one of the ways it can be served.
 *
 * The CSP uses a per-request nonce, not 'unsafe-inline'. The application has
 * eight inline <script> blocks (theme bootstrap, a few backend screens);
 * allowing inline script wholesale would leave the policy doing nothing
 * against the XSS it exists to contain, on an application that renders
 * user-controlled problem statements, team names and compiler output.
 */
class SecurityHeaders
{
    /**
     * Request attribute the Blade views read to stamp their inline scripts.
     * See resources/views/partials/theme-init.blade.php.
     */
    public const NONCE_ATTRIBUTE = 'csp_nonce';

    /**
     * Must match `server.port` in vite.config.js, which sets strictPort so
     * the dev server refuses to move off it.
     */
    private const VITE_DEV_PORT = 5173;

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(24);
        $request->attributes->set(self::NONCE_ATTRIBUTE, $nonce);

        // Issue #143: the nonce existed and nothing consumed it. Views need
        // it to emit the one <style> block that replaces the inline style
        // attributes -- which is what lets style-src drop 'unsafe-inline'.
        View::share('cspNonce', $nonce);

        /** @var Response $response */
        $response = $next($request);

        foreach ($this->headers($request) as $header => $value) {
            // Never clobber a header a controller set deliberately -- the
            // webcast export sets its own Cache-Control, for instance.
            if (! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $headers = [
            'Content-Security-Policy' => $this->contentSecurityPolicy($request),
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // Nothing in this application uses a camera, a microphone or
            // geolocation, so the answer to every one of them is "no".
            'Permissions-Policy' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), '
                .'magnetometer=(), microphone=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            // Issue #143: the third of the trio. With COOP and CORP already
            // set, this is what actually makes the origin cross-origin
            // isolated. It costs nothing here because every resource the
            // pages load is same-origin and already answers with CORP --
            // verified by loading each page with it on. The day someone
            // embeds a third-party script or font, this is the header that
            // will refuse it, and that refusal is the point.
            'Cross-Origin-Embedder-Policy' => 'require-corp',
        ];

        // Only over TLS: sending HSTS on a plain-HTTP response is ignored by
        // browsers, and sending it from a local dev server would pin
        // developers' machines to https://localhost.
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }

    private function contentSecurityPolicy(Request $request): string
    {
        $nonce = "'nonce-".$request->attributes->get(self::NONCE_ATTRIBUTE)."'";

        $script = ["'self'", $nonce];
        $style = ["'self'", $nonce];
        $connect = ["'self'"];

        // Vite's dev server serves the module graph and opens a websocket for
        // hot reload; neither exists in a built deployment.
        //
        // The port is a constant, not env('VITE_PORT'), and PHPStan is what
        // pointed at it. Two reasons, and the second is the real one:
        // env() outside config/ returns null once `config:cache` has run,
        // and -- more to the point -- vite.config.js pins the dev server
        // with `port: 5173, strictPort: true`, so VITE_PORT never moved
        // anything. Reading it here only created a way for the two halves
        // to disagree: set VITE_PORT=5174 and the CSP would have allowed
        // 5174 while Vite still served 5173, blocking the dev server for a
        // reason nothing would explain. Moving the port means editing both
        // files, which is the honest cost.
        if (app()->environment('local') && config('app.debug')) {
            $devServer = 'http://localhost:'.self::VITE_DEV_PORT;
            $script[] = $devServer;
            $connect[] = $devServer;
            $connect[] = 'ws://localhost:'.self::VITE_DEV_PORT;
        }

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $script),
            // Issue #143: this used to be 'unsafe-inline', justified by
            // "Vue's scoped styles and the components' inline style
            // bindings". That justification did not survive checking:
            // resources/js has no :style bindings and no <style> blocks in
            // any SFC, and Vite extracts component CSS to
            // public/build/assets/*.css at build time. The only inline
            // styles in the application were three style= attributes in
            // Blade, and they are gone.
            //
            // A nonce cannot cover a style ATTRIBUTE -- that needs
            // 'unsafe-hashes' -- so the dynamic colours moved into a nonced
            // <style> block instead of being annotated in place.
            'style-src '.implode(' ', $style),
            "img-src 'self' data:",
            "font-src 'self' data:",
            'connect-src '.implode(' ', $connect),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            // 'self', not 'none', to keep the same answer the existing
            // X-Frame-Options: SAMEORIGIN was already giving. Tightening
            // who may frame the app is a separate decision from adding a
            // policy, and CSP silently wins over the older header where
            // both are understood.
            "frame-ancestors 'self'",
        ]);
    }
}
