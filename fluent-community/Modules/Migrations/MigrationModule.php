<?php

namespace FluentCommunity\Modules\Migrations;

use FluentCommunity\App\Models\User;
use FluentCommunity\Modules\Migrations\Helpers\BPMigratorHelper;

class MigrationModule
{
    public function register($app)
    {
        if (!defined('BP_PLUGIN_DIR')) {
            return;
        }

        /*
        * register the routes
        */
        $app->router->group(function ($router) {
            require_once __DIR__ . '/Http/migration_api.php';
        });

        add_filter('fluent_community/portal_settings_menu_items', function ($menuItems) {
            $menuItems['manage_migrations'] = [
                'label'    => __('Manage Migrations', 'fluent-community'),
                'route'    => 'manage_migrations',
                'icon_svg' => '<svg viewBox="0 0 1024 1024"><path fill="currentColor" d="M640 608h-64V416h64zm0 160v160a32 32 0 0 1-32 32H416a32 32 0 0 1-32-32V768h64v128h128V768zM384 608V416h64v192zm256-352h-64V128H448v128h-64V96a32 32 0 0 1 32-32h192a32 32 0 0 1 32 32z"></path><path fill="currentColor" d="m220.8 256-71.232 80 71.168 80H768V256H220.8zm-14.4-64H800a32 32 0 0 1 32 32v224a32 32 0 0 1-32 32H206.4a32 32 0 0 1-23.936-10.752l-99.584-112a32 32 0 0 1 0-42.496l99.584-112A32 32 0 0 1 206.4 192m678.784 496-71.104 80H266.816V608h547.2l71.168 80zm-56.768-144H234.88a32 32 0 0 0-32 32v224a32 32 0 0 0 32 32h593.6a32 32 0 0 0 23.936-10.752l99.584-112a32 32 0 0 0 0-42.496l-99.584-112A32 32 0 0 0 828.48 544z"></path></svg>'
            ];
            return $menuItems;
        });

    }
}
