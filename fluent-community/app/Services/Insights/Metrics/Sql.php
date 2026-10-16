<?php

namespace FluentCommunity\App\Services\Insights\Metrics;

use FluentCommunity\App\Services\Insights\Range;
use FluentCommunity\App\Services\Insights\Scope;

/**
 * The shared SQL pieces every metric is built from, so "a post" or "a
 * reaction" means exactly one thing everywhere (docs/reporting/03).
 *
 * Every fragment returned here is either static or built from integers and
 * dates this class formats itself, so it can be interpolated into a query.
 */
class Sql
{
    /**
     * @param string $name table name without the WordPress prefix
     * @return string
     */
    public static function table($name)
    {
        global $wpdb;

        return $wpdb->prefix . $name;
    }

    /** F_POST */
    public static function isPost($alias)
    {
        return "{$alias}.type = 'text' AND {$alias}.status = 'published'";
    }

    /** F_COMMENT */
    public static function isComment($alias)
    {
        return "{$alias}.type = 'comment' AND {$alias}.status = 'published'";
    }

    /** F_REACTION */
    public static function isReaction($alias)
    {
        return "{$alias}.object_type IN ('feed', 'comment') AND {$alias}.type = 'like'";
    }

    /** Lesson completions count as contributions. */
    public static function isLessonCompletion($alias)
    {
        return "{$alias}.object_type = 'lesson_completed' AND {$alias}.type = 'completed'";
    }

    /**
     * The post a reaction belongs to: a post reaction points at the post, a
     * comment reaction keeps the post id in parent_id.
     */
    public static function reactionPostId($alias)
    {
        return "(CASE WHEN {$alias}.object_type = 'feed' THEN {$alias}.object_id ELSE {$alias}.parent_id END)";
    }

    /**
     * `col >= from AND col < to + 1 day`, the only range shape Insights uses.
     *
     * @param string $column
     * @param Range $range
     * @return string
     */
    public static function inRange($column, Range $range)
    {
        return self::between($column, $range->sqlFrom(), $range->sqlToExclusive());
    }

    /**
     * @param string $column
     * @param string $from 'Y-m-d H:i:s', inclusive
     * @param string $toExclusive 'Y-m-d H:i:s', exclusive
     * @return string
     */
    public static function between($column, $from, $toExclusive)
    {
        return "{$column} >= '" . self::date($from) . "' AND {$column} < '" . self::date($toExclusive) . "'";
    }

    /**
     * Leaves the scope's excluded members out.
     *
     * Deliberately NOT `col NOT IN (…)`: fcom_post_comments has rows with a NULL
     * user_id, and `NULL NOT IN (…)` is unknown, so those rows would silently
     * drop out of every count. NOT EXISTS keeps them.
     *
     * @param string $column
     * @param Scope $scope
     * @return string empty, or ` AND NOT EXISTS (…)`
     */
    public static function notExcluded($column, Scope $scope)
    {
        $userIds = $scope->excludedUserIds();

        if (!$userIds) {
            return '';
        }

        $rows = implode(' UNION ALL ', array_map(function ($userId) {
            return 'SELECT ' . (int)$userId . ' AS id';
        }, $userIds));

        return " AND NOT EXISTS (SELECT 1 FROM ({$rows}) AS fcom_ins_excluded WHERE fcom_ins_excluded.id = {$column})";
    }

    /**
     * @return bool true when Fluent Messaging's table exists
     */
    public static function hasChatTable()
    {
        static $exists = null;

        if ($exists === null) {
            global $wpdb;
            $table = self::table('fcom_chat_messages');
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        }

        return $exists;
    }

    /**
     * Runs a query that was assembled from the fragments above.
     *
     * @param string $sql
     * @return array
     */
    public static function rows($sql)
    {
        global $wpdb;

        $rows = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is assembled from the fragments above, never from request input

        return $rows ? $rows : [];
    }

    /**
     * @param string $sql
     * @return string|null
     */
    public static function value($sql)
    {
        global $wpdb;

        return $wpdb->get_var($sql); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is assembled from the fragments above, never from request input
    }

    /**
     * @param string $sql
     * @return array bucket key => int
     */
    public static function keyed($sql)
    {
        $map = [];
        foreach (self::rows($sql) as $row) {
            $map[$row['k']] = (int)$row['n'];
        }

        return $map;
    }

    private static function date($value)
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$value);

        // Never reached with Range output; refuses anything that is not a date.
        if (!$date) {
            throw new \InvalidArgumentException('Insights expects Y-m-d H:i:s dates.');
        }

        return $date->format('Y-m-d H:i:s');
    }
}
