<?php

namespace Agoodley\LibrenmsAnsible\Http\Middleware;

use Agoodley\LibrenmsAnsible\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every page and API of the plugin is for LibreNMS admins (403 for everyone else); with the "run" parameter the
 * route also needs the plugin's run permission, with "approve" its approve permission. JSON routes get a JSON error
 * that says what is missing.
 */
class RequireAdmin
{
    public function handle(Request $request, Closure $next, string $need = 'view'): Response
    {
        $user = $request->user();
        $allowed = match ($need) {
            'run' => Access::canRun($user),
            'approve' => Access::canApprove($user),
            default => Access::canView($user),
        };
        if (! $allowed) {
            $message = match (true) {
                ! Access::canView($user) => 'The Ansible plugin is for LibreNMS admins.',
                $need === 'approve' => 'Approving and rejecting changes needs the Ansible plugin\'s approve permission: an admin grants it on the plugin\'s settings page.',
                default => 'Preparing, dry-running, applying and stopping changes needs the Ansible plugin\'s run permission: an admin grants it on the plugin\'s settings page.',
            };
            if ($request->expectsJson() || str_contains($request->path(), '/api/')) {
                return response()->json(['error' => $message], 403);
            }
            abort(403, $message);
        }

        return $next($request);
    }
}
