<?php

namespace Agoodley\LibrenmsAnsible\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the inventory endpoint take a LibreNMS API token the way LibreNMS' own API does (an X-Auth-Token
 * header) as well as a Bearer token. LibreNMS tells Sanctum to read X-Auth-Token only on api/v0 paths, so the
 * header is handed to Sanctum as a Bearer token here; Sanctum then checks it as for the API (the token exists,
 * has not expired, its user is enabled). The answer is always JSON (no redirect to the login page).
 */
class AuthTokenHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');
        $token = trim((string) $request->header('X-Auth-Token', ''));
        if ($token !== '' && $request->bearerToken() === null) {
            $request->headers->set('Authorization', 'Bearer ' . $token);
        }

        return $next($request);
    }
}
