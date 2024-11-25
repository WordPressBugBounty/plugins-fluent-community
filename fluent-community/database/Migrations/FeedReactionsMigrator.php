<?php
// phpcs:disable
namespace FluentCommunity\Database\Migrations;

class FeedReactionsMigrator
{
    /**
     * @param bool $isForced
     * @return void
     */
    public static function migrate($isForced = false)
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'fcom_post_reactions';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table || $isForced) {
            $sql = "CREATE TABLE $table (
                `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NULL,
                `object_id` BIGINT UNSIGNED NULL,
                `parent_id` BIGINT UNSIGNED NULL,
                `object_type` VARCHAR(100) default 'feed',
                `type` VARCHAR(100) NULL DEFAULT 'like',
                `ip_address` VARCHAR(100) NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                INDEX `object_id` (`object_id`),
                INDEX `object_type` (`object_type`),
                INDEX `type` (`type`)
            ) $charsetCollate;";
            dbDelta($sql);
        }
    }
}
