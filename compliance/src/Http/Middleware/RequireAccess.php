<?php

namespace Agoodley\LibrenmsCompliance\Http\Middleware;

use Agoodley\LibrenmsCompliance\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The plugin's pages are for the users LibreNMS lets read configuration backups (Support\Access, 403 for everyone
 * else); with the "admin" parameter the route is for LibreNMS admins.
 */
class RequireAccess
{
    public function handle(Request $request, Closure $next, string $need = 'view'): Response
    {
        $user = $request->user();
        if (! ($need === 'admin' ? Access::isAdmin($user) : Access::canView($user))) {
            abort(403, $need === 'admin' ? 'This is for LibreNMS admins.'
                : 'The compliance plugin shows lines of the configuration backups: it is for LibreNMS admins and the users with LibreNMS\' config-backup.view permission.');
        }

        return $next($request);
    }
}
