<?php if (!defined('ABSPATH')) exit; // Exit if accessed directly

use FluentCommunity\App\Hooks\Handlers\ActivationHandler;
use FluentCommunity\App\Hooks\Handlers\DeactivationHandler;
use FluentCommunity\Framework\Foundation\Application;

return function ($file) {

    $app = new Application($file);

    register_activation_hook($file, function () use ($app) {
        ($app->make(ActivationHandler::class))->handle();

        if (function_exists('\as_next_scheduled_action')) {
            if (!\as_next_scheduled_action('fluent_community_scheduled_hour_jobs')) {
                as_schedule_recurring_action(time(), 3600, 'fluent_community_scheduled_hour_jobs', [], 'fluent-community');
            }

            if (!\as_next_scheduled_action('fluent_community_daily_jobs')) {
                as_schedule_recurring_action(time(), 86400, 'fluent_community_daily_jobs', [], 'fluent-community');
            }
        }

    });

    register_deactivation_hook($file, function () use ($app) {
        ($app->make(DeactivationHandler::class))->handle();
    });

    require_once FLUENT_COMMUNITY_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
    require_once FLUENT_COMMUNITY_PLUGIN_DIR . 'app/Functions/helpers.php';

    if (file_exists(FLUENT_COMMUNITY_PLUGIN_DIR . 'Modules/modules_init.php')) {
        require_once FLUENT_COMMUNITY_PLUGIN_DIR . 'Modules/modules_init.php';
    }

    add_action('plugins_loaded', function () use ($app) {
        do_action('fluent_community/portal_loaded', $app);
    });

    add_action('fluent_community/portal_render_for_user', function () {
        if (!\FluentCommunity\App\Services\Helper::isSiteAdmin()) {
            return;
        }

        if (!\as_next_scheduled_action('fluent_community_scheduled_hour_jobs')) {
            as_schedule_recurring_action(time(), 3600, 'fluent_community_scheduled_hour_jobs', [], 'fluent-community');
        }

        if (!\as_next_scheduled_action('fluent_community_daily_jobs')) {
            \as_schedule_recurring_action(time(), 86400, 'fluent_community_daily_jobs', [], 'fluent-community');
        }

        /*
         * We will remove this after final release
         */
        $currentDBVersion = get_option('fluent_community_db_version');
        if (!$currentDBVersion || version_compare($currentDBVersion, FLUENT_COMMUNITY_DB_VERSION, '<')) {
            update_option('fluent_community_db_version', FLUENT_COMMUNITY_DB_VERSION, 'no');
            \FluentCommunity\Database\DBMigrator::run();
        }
    });
};
