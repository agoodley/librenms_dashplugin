<?php

namespace Agoodley\LibrenmsAnsible\Hooks;

use Agoodley\LibrenmsAnsible\Support\Access;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;

/**
 * The plugin's page in Plugin Admin (/plugin/ansible): links to its pages. Only for LibreNMS admins (LibreNMS
 * shows its "missing" page to the others).
 */
class Page implements SinglePageHook
{
    /**
     * Admins only.
     */
    public function authorize(): bool
    {
        return Access::canView(auth()->user());
    }

    /**
     * @return array{title: string, content_view: string} rendered by LibreNMS inside its plugin page layout
     */
    public function handle(string $pluginName): array
    {
        return ['title' => 'Ansible', 'content_view' => "$pluginName::landing"];
    }
}
