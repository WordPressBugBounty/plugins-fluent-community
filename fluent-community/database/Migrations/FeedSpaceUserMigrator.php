<?php
// phpcs:disable
namespace FluentCommunity\Database\Migrations;

class FeedSpaceUserMigrator
{
    const CONVERT_ERROR_OPTION = 'fluent_community_space_user_user_id_error';

    /**
     * @param bool $isForced
     * @return void
     */
    public static function migrate($isForced = false)
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'fcom_space_user';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table || $isForced) {
            $sql = "CREATE TABLE $table (
                `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `space_id` BIGINT UNSIGNED NULL,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `status` VARCHAR(100) NULL DEFAULT 'active',
                `role` VARCHAR(100) NULL DEFAULT 'member',
                `meta` TEXT NULL,
                `expire_at` DATETIME NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                INDEX `space_id_user_id` (`space_id`, `user_id`),
                INDEX `user_id_status` (`user_id`, `status`),
                INDEX `space_status_created` (`space_id`, `status`, `created_at`),
                INDEX `space_role_created` (`space_id`, `role`, `created_at`)
            ) $charsetCollate;";
            dbDelta($sql);
        } else {
            // check we have column notification_level
            // if yes then drop it
            $column_names = $wpdb->get_col("DESC " . $table, 0);
            if (in_array('notification_level', $column_names)) {
                $wpdb->query("ALTER TABLE $table DROP INDEX notification_level");
                $wpdb->query("ALTER TABLE $table DROP COLUMN notification_level");
            }

            // check if we have meta column
            // if not then add it
            if (!in_array('meta', $column_names)) {
                $wpdb->query("ALTER TABLE $table ADD COLUMN `meta` TEXT NULL AFTER `role`");
            }

            // Reserved for membership expiry. DATETIME rather than TIMESTAMP,
            // which cannot hold a date past 2038-01-19.
            if (!in_array('expire_at', $column_names)) {
                $wpdb->query("ALTER TABLE $table ADD COLUMN `expire_at` DATETIME NULL AFTER `meta`");
            }

            //  now check index for space_id_user_id
            $index_names = $wpdb->get_col("SHOW INDEX FROM $table", 2);
            if (!in_array('space_id_user_id', $index_names)) {
                $wpdb->query("ALTER TABLE $table ADD INDEX `space_id_user_id` (`space_id`, `user_id`)");
            }

            // Single-column status / role indexes: every query also filters by
            // space_id or user_id, which the composites below cover, and nearly
            // every row is 'active' / 'member'. `status` also misled the optimizer
            // into full probes for user_id lookups.
            foreach (['status', 'role'] as $staleIndex) {
                if (in_array($staleIndex, $index_names)) {
                    $wpdb->query("ALTER TABLE $table DROP INDEX `$staleIndex`");
                }
            }

            if (!in_array('user_id_status', $index_names)) {
                $wpdb->query("ALTER TABLE $table ADD INDEX `user_id_status` (`user_id`, `status`)");
            }

            if (!in_array('space_status_created', $index_names)) {
                $wpdb->query("ALTER TABLE $table ADD INDEX `space_status_created` (`space_id`, `status`, `created_at`)");
            }

            if (!in_array('space_role_created', $index_names)) {
                $wpdb->query("ALTER TABLE $table ADD INDEX `space_role_created` (`space_id`, `role`, `created_at`)");
            }

            self::maybeConvertUserIdColumn();
        }
    }

    /**
     * Tables created by earlier versions store user_id as VARCHAR(194) while
     * every other user_id in the schema, and wp_users.ID, is numeric. Joining
     * the two compares a string to a number, which MySQL cannot resolve through
     * the (user_id, status) index, so each probe scans every membership row for
     * the matched spaces. On MySQL 5.7 / MariaDB the FluentCRM space filter took
     * 86s over 13k contacts and 36k memberships this way.
     *
     * @return void
     */
    public static function maybeConvertUserIdColumn()
    {
        if (self::hasNumericUserId()) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fcom_space_user';

        // Every write path stores a WP user ID, so a value that is not one
        // is corrupt and cannot belong to any user. Left in place, MODIFY
        // would turn it into 0 (or fail midway under strict mode). Unanchored
        // on purpose: `$` also matches before a trailing newline on MySQL 8
        // and MariaDB but not on 5.7.
        $corruptWhere = "`user_id` = '' OR `user_id` REGEXP '[^0-9]' OR CHAR_LENGTH(`user_id`) > 19";

        $corruptRows = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $corruptWhere");
        if ($corruptRows === null) {
            update_option(self::CONVERT_ERROR_OPTION, $wpdb->last_error, false);
            return;
        }

        if ($corruptRows) {
            // A handful of corrupt rows is plausible; a large share means the
            // check itself is wrong for this database, so delete nothing.
            $totalRows = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
            if ($corruptRows > max(10, $totalRows * 0.01)) {
                update_option(self::CONVERT_ERROR_OPTION, sprintf('%d of %d rows look non-numeric; nothing deleted, column left as VARCHAR', $corruptRows, $totalRows), false);
                return;
            }

            if ($wpdb->query("DELETE FROM $table WHERE $corruptWhere") === false) {
                update_option(self::CONVERT_ERROR_OPTION, $wpdb->last_error, false);
                return;
            }
        }

        if ($wpdb->query("ALTER TABLE $table MODIFY `user_id` BIGINT UNSIGNED NOT NULL") === false) {
            update_option(self::CONVERT_ERROR_OPTION, $wpdb->last_error, false);
            return;
        }

        // Straight after the rebuild InnoDB's index statistics are stale, and
        // the optimizer was seen picking the `status` index for user_id probes.
        $wpdb->query("ANALYZE TABLE $table");

        delete_option(self::CONVERT_ERROR_OPTION);
    }

    /**
     * @return bool
     */
    private static function hasNumericUserId()
    {
        global $wpdb;

        $dataType = $wpdb->get_var($wpdb->prepare(
            "SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'user_id'",
            $wpdb->prefix . 'fcom_space_user'
        ));

        return $dataType && !in_array(strtolower($dataType), ['varchar', 'char', 'text'], true);
    }
}
