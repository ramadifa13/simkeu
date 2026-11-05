<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $response->headers->set('X-Content-Type-Options', 'nosniff', false);
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()', false);
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin', false);
        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy(), false);

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains', false);
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        // Livewire/Flux need inline script/style; Vite HMR only in local.
        $vite = app()->environment('local')
            ? ' http://localhost:5173 http://127.0.0.1:5173 ws://localhost:5173 ws://127.0.0.1:5173'
            : '';

        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "style-src 'self' 'unsafe-inline'{$vite}",
            // Livewire/Flux still need inline + eval; tighten when assets are nonced.
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'{$vite}",
            "connect-src 'self'{$vite}",
        ];

        if (! app()->environment('local')) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }
}
