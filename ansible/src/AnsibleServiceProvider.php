<?php

namespace Agoodley\LibrenmsAnsible;

use Illuminate\Support\ServiceProvider;

/**
 * Entry point of the plugin package, auto-discovered by Laravel (see composer.json extra.laravel).
 */
class AnsibleServiceProvider extends ServiceProvider
{
    /** Name of the plugin inside LibreNMS (plugin admin, settings, view namespace). */
    public const PLUGIN_NAME = 'ansible';
}
