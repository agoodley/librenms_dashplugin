<?php

namespace Agoodley\LibrenmsCompliance\Hooks;

use Agoodley\LibrenmsCompliance\Support\Access;
use LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook;

/**
 * The plugin's page in Plugin Admin (/plugin/compliance): links to its pages. For the users who may read
 * configuration backups (LibreNMS shows its "missing" page to the others).
 */
class Page implements SinglePageHook
{
    public function authorize(): bool
    {
        return Access::canView(auth()->user());
    }

    /**
     * @return array{title: string, content_view: string} rendered by LibreNMS inside its plugin page layout
     */
    public function handle(string $pluginName): array
    {
        return ['title' => 'Compliance', 'content_view' => "$pluginName::landing"];
    }
}
