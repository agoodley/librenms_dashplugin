<?php

namespace Agoodley\LibrenmsInventory\Http\Middleware;

use Agoodley\LibrenmsInventory\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The plugin's pages are for logged in LibreNMS users (Support\Access), each for the devices they may see; with the
 * "admin" parameter the route is for LibreNMS admins (the settings, a software check now).
 */
class RequireAccess
{
    public function handle(Request $request, Closure $next, string $need = 'view'): Response
    {
        $user = $request->user();
        if (! ($need === 'admin' ? Access::isAdmin($user) : Access::canView($user))) {
            abort(403, $need === 'admin' ? 'This is for LibreNMS admins.' : 'Log in to LibreNMS to see the inventory.');
        }

        return $next($request);
    }
}
