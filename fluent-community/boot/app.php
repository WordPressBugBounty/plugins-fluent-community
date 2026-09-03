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
                \as_schedule_recurring_action(time(), 3600, 'fluent_community_scheduled_hour_jobs', [], 'fluent-community', true);
            }

            if (!\as_next_scheduled_action('fluent_community_daily_jobs')) {
                \as_schedule_recurring_action(time(), 86400, 'fluent_community_daily_jobs', [], 'fluent-community', true);
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

        add_action('init', function () use ($app) {
            /*
             * Transitional: repair a stale schema on ANY request, not only on the
             * ones a privileged user makes.
             *
             * Both entry points below are gated on a capability, so on an
             * upgrading site a newly added table does not exist until someone who
             * can activate plugins loads wp-admin. Cron, REST and ordinary member
             * requests all reach the plugin before that and read a table that is
             * not there yet. Multisite is worse: activate_plugins maps to
             * manage_network_plugins there, so only a super admin, visiting each
             * subsite in turn, ever migrates it.
             *
             * Ahead of on_wp_init deliberately, so the request that performs the
             * migration is also the first to benefit from it.
             *
             * Safe under the concurrency this exposes it to: dbDelta sits behind a
             * table-exists check, and the preference backfill replays as a no-op
             * (its INSERT is ON DUPLICATE KEY UPDATE with a self-assignment), so a
             * burst of traffic straight after an update duplicates work rather
             * than corrupting anything.
             *
             * Remove once 2.9.x is broadly adopted; the capability-gated entry
             * points are enough on a site that is already current.
             */
            fluent_community_maybe_migrate_db();

            do_action('fluent_community/on_wp_init', $app);
        });
    });

    /*
     * A stale schema is repaired from three places. The init hook above is the one
     * that actually closes the gap, because it needs no privileged user; the two
     * below predate it and are kept as belt and braces. Portal render catches
     * sites where an admin browses the community; admin_init catches the
     * plugin-update case, where the first request after the new code lands is a
     * wp-admin page. A missing index only slows things down, but a missing table
     * is fatal, so more than one entry point matters.
     *
     * The version option is autoloaded so the check below costs nothing on the
     * requests where there is nothing to do - which, after the first one, is all
     * of them. Note that update_option() returns early when the value is
     * unchanged, so the autoload flag only flips on a site whose version string
     * actually moves; one already stamped at the current version keeps the old
     * flag until the next DB version bump.
     */
    if (!function_exists('fluent_community_maybe_migrate_db')) {
        function fluent_community_maybe_migrate_db()
        {
            $currentDBVersion = get_option('fluent_community_db_version');

            if ($currentDBVersion && version_compare($currentDBVersion, FLUENT_COMMUNITY_DB_VERSION, '>=')) {
                return;
            }

            if (get_transient('fluent_community_db_migration_lock')) {
                return;
            }

            set_transient('fluent_community_db_migration_lock', 1, 5 * MINUTE_IN_SECONDS);
            \FluentCommunity\Database\DBMigrator::run();
            update_option('fluent_community_db_version', FLUENT_COMMUNITY_DB_VERSION, true);
            delete_transient('fluent_community_db_migration_lock');
        }
    }

    add_action('admin_init', function () {
        if (current_user_can('activate_plugins')) {
            fluent_community_maybe_migrate_db();
        }
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
        fluent_community_maybe_migrate_db();


        if (defined('FLUENT_COMMUNITY_PRO_VERSION')) {
            add_filter('fluent_community/portal_notices', function ($notices) {
                if (FLUENT_COMMUNITY_MIN_PRO_VERSION !== FLUENT_COMMUNITY_PRO_VERSION && version_compare(FLUENT_COMMUNITY_MIN_PRO_VERSION, FLUENT_COMMUNITY_PRO_VERSION, '>')) {
                    $updateUrl = admin_url('plugins.php?s=fluent-community&plugin_status=all&fluent-fluent-community-pro-check-update=' . time());
                    $notices[] = '<div style="padding: 10px; background-color: var(--fcom-primary-bg, white);" class="error"><b>' . esc_html__('Heads UP:', 'fluent-community') . ' </b> ' . esc_html__('FluentCommunityPro Plugin needs to be updated to the latest version.', 'fluent-community') . ' <a href="' . esc_url($updateUrl) . '">' . esc_html__('Click here to update', 'fluent-community') . '</a></div>';
                }
                return $notices;
            });
        }

    });
};
