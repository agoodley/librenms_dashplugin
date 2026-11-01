<?php

namespace Agoodley\LibrenmsInventory\Http\Middleware;

use Agoodley\LibrenmsInventory\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The plugin's pages are for logged in LibreNMS users (Support\Access), each for the devices they may see.
 */
class RequireAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Access::canView($request->user())) {
            abort(403, 'Log in to LibreNMS to see the inventory.');
        }

        return $next($request);
    }
}
