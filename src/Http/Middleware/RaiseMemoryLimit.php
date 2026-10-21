<?php

namespace Agoodley\LibrenmsTopology\Http\Middleware;

use Agoodley\LibrenmsTopology\Support\MemoryLimit;
use Closure;
use Illuminate\Http\Request;

/**
 * Memory for every request of the plugin (pages, APIs, forms): LibreNMS' php_memory_limit when it is set, else
 * DEFAULT_MB. The pages fit in PHP's 128M default on the README's benchmark network; this is the margin for larger ones.
 */
class RaiseMemoryLimit
{
    /** Memory of a request when LibreNMS sets no php_memory_limit (MB). */
    public const DEFAULT_MB = 256;

    public function handle(Request $request, Closure $next): mixed
    {
        MemoryLimit::raise(self::DEFAULT_MB);

        return $next($request);
    }
}
