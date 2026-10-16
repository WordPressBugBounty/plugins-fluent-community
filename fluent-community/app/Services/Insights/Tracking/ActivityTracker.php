<?php

namespace FluentCommunity\App\Services\Insights\Tracking;

use FluentCommunity\App\Services\Insights\Clock;

/**
 * Writes fcom_activity_daily (one row per member per local day) and
 * fcom_insight_counters (events the source tables forget, such as leaves).
 *
 * Every write is a single upsert. Counters only ever go up: a reaction that is
 * later removed still happened that day.
 */
class ActivityTracker
{
    const COLUMNS = ['visits', 'posts', 'comments', 'reactions', 'messages', 'lessons'];

    const COUNTER_METRICS = ['space_joined', 'space_left'];

    /**
     * @param int $userId
     * @param string $column one of self::COLUMNS
     * @param string|null $dateTime local 'Y-m-d H:i:s' of the event, defaults to now
     * @return bool
     */
    public static function increment($userId, $column, $dateTime = null)
    {
        $userId = (int)$userId;

        if (!$userId || !in_array($column, self::COLUMNS, true)) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fcom_activity_daily';

        // $column is checked against the allowlist above.
        $result = $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix plus a literal; $column is allowlisted above
            "INSERT INTO {$table} (`user_id`, `stat_date`, `{$column}`) VALUES (%d, %s, 1) ON DUPLICATE KEY UPDATE `{$column}` = LEAST(`{$column}` + 1, 65535)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $userId,
            self::localDate($dateTime)
        ));

        return $result !== false;
    }

    /**
     * Called from the throttled last_activity write, so it inherits the
     * existing one-write-per-five-minutes budget.
     *
     * @param int $userId
     * @return bool
     */
    public static function recordVisit($userId)
    {
        /**
         * Return false to stop visit capture, e.g. on a privacy-strict site.
         *
         * @param bool $track
         * @param int $userId
         */
        if (!apply_filters('fluent_community/insights/track_visit', true, (int)$userId)) {
            return false;
        }

        return self::increment($userId, 'visits');
    }

    /**
     * @param string $objectType space|course|global
     * @param int $objectId
     * @param string $metric one of self::COUNTER_METRICS
     * @return bool
     */
    public static function incrementCounter($objectType, $objectId, $metric)
    {
        if (!in_array($metric, self::COUNTER_METRICS, true) || !in_array($objectType, ['space', 'course', 'global'], true)) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fcom_insight_counters';

        $result = $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix plus a literal
            "INSERT INTO {$table} (`stat_date`, `object_type`, `object_id`, `metric`, `value`) VALUES (%s, %s, %d, %s, 1) ON DUPLICATE KEY UPDATE `value` = `value` + 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            Clock::today()->format('Y-m-d'),
            $objectType,
            (int)$objectId,
            $metric
        ));

        return $result !== false;
    }

    /**
     * @param int $userId
     * @return int rows removed
     */
    public static function deleteUser($userId)
    {
        $userId = (int)$userId;
        if (!$userId) {
            return 0;
        }

        global $wpdb;

        return (int)$wpdb->delete($wpdb->prefix . 'fcom_activity_daily', ['user_id' => $userId], ['%d']); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * @param int $userId
     * @return array
     */
    public static function getUserRows($userId)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fcom_activity_daily';

        return $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix plus a literal
            "SELECT * FROM {$table} WHERE `user_id` = %d ORDER BY `stat_date` ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (int)$userId
        ), ARRAY_A);
    }

    /**
     * Deletes rows older than the retention window, in batches so one daily run
     * never holds a long lock.
     *
     * @return int rows removed
     */
    public static function purgeExpired()
    {
        $cutoff = self::retentionCutoff();
        if (!$cutoff) {
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fcom_activity_daily';

        $removed = 0;
        for ($batch = 0; $batch < 50; $batch++) {
            $deleted = $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is $wpdb->prefix plus a literal
                "DELETE FROM {$table} WHERE `stat_date` < %s LIMIT 5000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $cutoff
            ));

            if (!$deleted) {
                break;
            }

            $removed += (int)$deleted;
        }

        return $removed;
    }

    /**
     * @return string|null first local date that is kept, or null to keep everything
     */
    public static function retentionCutoff()
    {
        /**
         * How long fcom_activity_daily keeps rows. Default: 25 months, so a
         * year-over-year comparison of the last 12 months still has data.
         *
         * @param int $days
         */
        $days = (int)apply_filters('fluent_community/insights/retention_days', 761);
        if ($days < 1) {
            return null;
        }

        return Clock::today()->modify('-' . $days . ' days')->format('Y-m-d');
    }

    /**
     * @param string|null $dateTime
     * @return string local 'Y-m-d'
     */
    private static function localDate($dateTime)
    {
        if ($dateTime instanceof \DateTimeInterface) {
            return $dateTime->format('Y-m-d');
        }

        if ($dateTime && preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$dateTime, $matches)) {
            return $matches[0];
        }

        return Clock::today()->format('Y-m-d');
    }
}
