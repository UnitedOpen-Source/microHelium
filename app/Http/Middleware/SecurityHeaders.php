<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::NONCE_ATTRIBUTE, Str::random(24));

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
        $connect = ["'self'"];

        // Vite's dev server serves the module graph and opens a websocket for
        // hot reload; neither exists in a built deployment.
        if (app()->environment('local') && config('app.debug')) {
            $devServer = 'http://localhost:'.env('VITE_PORT', 5173);
            $script[] = $devServer;
            $connect[] = $devServer;
            $connect[] = 'ws://localhost:'.env('VITE_PORT', 5173);
        }

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $script),
            // 'unsafe-inline' for styles only, and knowingly: Vue's scoped
            // styles and the components' inline style bindings generate them
            // at runtime. An injected style is a far smaller weapon than an
            // injected script, and nonce-ing every one of them is not
            // reachable while the components are written this way.
            "style-src 'self' 'unsafe-inline'",
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
