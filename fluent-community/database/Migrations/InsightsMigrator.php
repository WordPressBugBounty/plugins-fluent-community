<?php
// phpcs:disable
namespace FluentCommunity\Database\Migrations;

use FluentCommunity\App\Services\Insights\Clock;
use FluentCommunity\App\Services\Insights\Tracking\Backfill;

class InsightsMigrator
{
    const TRACKING_SINCE_OPTION = 'fluent_community_insights_tracking_since';

    const INDEX_JOB_HOOK = 'fluent_community/insights/build_indexes';

    /*
     * Above this many rows an ALTER is left to a background job, so a plugin
     * update never holds a request for minutes on a large table.
     */
    const SYNC_INDEX_ROW_LIMIT = 1000000;

    /**
     * Covering indexes: every Insights range query reads the index only. The
     * plain (type, created_at) versions were benchmarked and were slower, see
     * docs/reporting/11-query-cost.md.
     *
     * @return array table => [index name => column list]
     */
    public static function indexes()
    {
        return [
            'fcom_posts'           => ['ins_posts' => '`type`, `status`, `created_at`, `user_id`, `space_id`'],
            'fcom_post_comments'   => ['ins_comments' => '`type`, `status`, `created_at`, `user_id`, `post_id`'],
            'fcom_post_reactions'  => ['ins_reactions' => '`object_type`, `type`, `created_at`, `user_id`, `object_id`'],
            'fcom_xprofile'        => ['ins_created' => '`created_at`'],
            'fcom_user_activities' => [
                'action_created' => '`action_name`, `created_at`',
                'space_id'       => '`space_id`',
            ],
        ];
    }

    public static function migrate($isForced = false)
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        $dailyTable = $wpdb->prefix . 'fcom_activity_daily';
        $isNewDailyTable = $wpdb->get_var("SHOW TABLES LIKE '$dailyTable'") != $dailyTable;

        if ($isNewDailyTable || $isForced) {
            $sql = "CREATE TABLE $dailyTable (
                `user_id` BIGINT UNSIGNED NOT NULL,
                `stat_date` DATE NOT NULL,
                `visits` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `posts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `comments` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `reactions` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `messages` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `lessons` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY  (`stat_date`, `user_id`),
                KEY `user_date` (`user_id`, `stat_date`)
            ) $charsetCollate;";
            dbDelta($sql);
        }

        $countersTable = $wpdb->prefix . 'fcom_insight_counters';
        if ($wpdb->get_var("SHOW TABLES LIKE '$countersTable'") != $countersTable || $isForced) {
            $sql = "CREATE TABLE $countersTable (
                `stat_date` DATE NOT NULL,
                `object_type` VARCHAR(20) NOT NULL,
                `object_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `metric` VARCHAR(40) NOT NULL,
                `value` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY  (`stat_date`, `object_type`, `object_id`, `metric`)
            ) $charsetCollate;";
            dbDelta($sql);
        }

        // Visits cannot be backfilled, so the date capture starts is what the
        // UI shows as "visits tracked from". Set once, never moved.
        if (!get_option(self::TRACKING_SINCE_OPTION)) {
            update_option(self::TRACKING_SINCE_OPTION, Clock::today()->format('Y-m-d'), false);
        }

        if ($isNewDailyTable) {
            Backfill::schedule();
        }

        self::addIndexes(false);
    }

    /**
     * @param bool $includeLargeTables true from the background job
     * @return array index names that are still missing
     */
    public static function addIndexes($includeLargeTables = false)
    {
        global $wpdb;

        $pending = [];

        foreach (self::indexes() as $tableName => $tableIndexes) {
            $table = $wpdb->prefix . $tableName;

            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
                continue;
            }

            $existing = $wpdb->get_col("SHOW INDEX FROM $table", 2);

            foreach ($tableIndexes as $indexName => $columns) {
                if (in_array($indexName, $existing, true)) {
                    continue;
                }

                if (!$includeLargeTables && self::estimatedRows($table) >= self::SYNC_INDEX_ROW_LIMIT) {
                    $pending[] = $indexName;
                    continue;
                }

                // Online where the server supports it; a server that rejects the
                // clause still gets the index the ordinary way.
                if ($wpdb->query("ALTER TABLE $table ADD INDEX `$indexName` ($columns), ALGORITHM=INPLACE, LOCK=NONE") === false) {
                    $wpdb->query("ALTER TABLE $table ADD INDEX `$indexName` ($columns)");
                }
            }
        }

        if ($pending && function_exists('\as_next_scheduled_action') && !\as_next_scheduled_action(self::INDEX_JOB_HOOK)) {
            \as_schedule_single_action(time() + 60, self::INDEX_JOB_HOOK, [], 'fluent-community');
        }

        return $pending;
    }

    /**
     * @return bool true while a large table still waits for its index
     */
    public static function isOptimising()
    {
        global $wpdb;

        foreach (self::indexes() as $tableName => $tableIndexes) {
            $table = $wpdb->prefix . $tableName;
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
                continue;
            }

            $existing = $wpdb->get_col("SHOW INDEX FROM $table", 2);
            if (array_diff(array_keys($tableIndexes), $existing)) {
                return true;
            }
        }

        return false;
    }

    private static function estimatedRows($table)
    {
        global $wpdb;

        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            $table
        ));
    }
}
