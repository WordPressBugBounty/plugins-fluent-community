<?php

namespace FluentCommunity\App\Services\Insights\Tracking;

use FluentCommunity\App\Services\Insights\Clock;
use FluentCommunity\Database\Migrations\InsightsMigrator;

/**
 * Rebuilds the contribution columns of fcom_activity_daily from the source
 * tables. Visits are not in any source table, so they are never touched.
 *
 * Works in windows of whole local days rather than primary-key chunks: a
 * user-day split across two id chunks would be counted as two partial values,
 * and GREATEST() would keep only the larger. Each window counts complete days,
 * so the upsert can take GREATEST(existing, computed). That makes the job
 * idempotent (a second run computes the same values) and leaves a higher
 * live-tracked count alone (a reaction removed after it was counted).
 *
 * Resumable: progress is saved after every window, and the daily job picks up
 * a run that stopped.
 */
class Backfill
{
    const OPTION = 'fluent_community_insights_backfill';

    const HOOK = 'fluent_community/insights/backfill';

    const WINDOW_DAYS = 31;

    /**
     * @return array column => [table, where]
     */
    public static function sources()
    {
        global $wpdb;

        $sources = [
            'posts'     => [$wpdb->prefix . 'fcom_posts', "`type` = 'text' AND `status` = 'published'"],
            'comments'  => [$wpdb->prefix . 'fcom_post_comments', "`type` = 'comment' AND `status` = 'published'"],
            'reactions' => [$wpdb->prefix . 'fcom_post_reactions', "`object_type` IN ('feed', 'comment') AND `type` = 'like'"],
            'lessons'   => [$wpdb->prefix . 'fcom_post_reactions', "`object_type` = 'lesson_completed' AND `type` = 'completed'"],
        ];

        // Fluent Messaging owns this table; use it only when it is there.
        $chatTable = $wpdb->prefix . 'fcom_chat_messages';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $chatTable)) === $chatTable) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            // No created_at index there (we must not migrate it), so one pass
            // over the whole table instead of one full scan per window.
            $sources['messages'] = [$chatTable, '1 = 1', 'single_pass'];
        }

        return $sources;
    }

    /**
     * Start (or restart) a backfill and queue the first run.
     *
     * @return array state
     */
    public static function schedule()
    {
        $state = self::start();

        if (function_exists('\as_schedule_single_action') && !\as_next_scheduled_action(self::HOOK)) {
            \as_schedule_single_action(time(), self::HOOK, [], 'fluent-community');
        }

        return $state;
    }

    /**
     * @return array state
     */
    public static function start()
    {
        $columns = array_keys(self::sources());

        $state = [
            'status'      => 'running',
            'queue'       => $columns,
            'column'      => array_shift($columns),
            'cursor'      => null,
            'end'         => Clock::today()->format('Y-m-d'),
            'started_at'  => Clock::now()->format('Y-m-d H:i:s'),
            'finished_at' => null,
        ];
        $state['queue'] = $columns;

        update_option(self::OPTION, $state, false);

        return $state;
    }

    /**
     * @return array|null
     */
    public static function state()
    {
        $state = get_option(self::OPTION);

        return is_array($state) ? $state : null;
    }

    /**
     * Process windows until the time budget runs out.
     *
     * @param int $budgetSeconds
     * @param int|null $maxWindows stop after this many windows (tests)
     * @return array state
     */
    public static function run($budgetSeconds = 20, $maxWindows = null)
    {
        $state = self::state();

        if (!$state || $state['status'] !== 'running') {
            return $state ? $state : [];
        }

        $sources = self::sources();
        $startedAt = microtime(true);
        $windows = 0;

        while ($state['column']) {
            $column = $state['column'];

            if (!isset($sources[$column])) {
                $state = self::nextColumn($state);
                continue;
            }

            $table = $sources[$column][0];
            $where = $sources[$column][1];
            $singlePass = !empty($sources[$column][2]);

            if (!$state['cursor']) {
                $state['cursor'] = self::earliestDate($table, $where);
                if (!$state['cursor']) {
                    $state = self::nextColumn($state);
                    update_option(self::OPTION, $state, false);
                    continue;
                }
            }

            $windowStart = new \DateTimeImmutable($state['cursor'], Clock::timezone());
            $windowEnd = $singlePass
                ? new \DateTimeImmutable($state['end'], Clock::timezone())
                : $windowStart->modify('+' . self::WINDOW_DAYS . ' days');
            if ($singlePass) {
                $windowEnd = $windowEnd->modify('+1 day');
            }

            self::fillWindow($column, $table, $where, $windowStart->format('Y-m-d'), $windowEnd->format('Y-m-d'));
            ++$windows;

            if ($windowEnd->format('Y-m-d') > $state['end']) {
                $state = self::nextColumn($state);
            } else {
                $state['cursor'] = $windowEnd->format('Y-m-d');
            }

            update_option(self::OPTION, $state, false);

            if (($maxWindows && $windows >= $maxWindows) || (microtime(true) - $startedAt) > $budgetSeconds) {
                return $state;
            }
        }

        $state['status'] = 'done';
        $state['finished_at'] = Clock::now()->format('Y-m-d H:i:s');
        update_option(self::OPTION, $state, false);

        return $state;
    }

    /**
     * Action Scheduler entry point.
     */
    public static function handleJob()
    {
        // On a large site the covering indexes may still be building; without
        // them every window would scan the whole table. Wait for them.
        if (InsightsMigrator::isOptimising()) {
            if (function_exists('\as_schedule_single_action')) {
                \as_schedule_single_action(time() + 10 * MINUTE_IN_SECONDS, self::HOOK, [], 'fluent-community');
            }

            return;
        }

        $state = self::run();

        if ($state && $state['status'] === 'running' && function_exists('\as_schedule_single_action')) {
            \as_schedule_single_action(time() + 5, self::HOOK, [], 'fluent-community');
        }
    }

    /**
     * Daily safety net for a run whose queued action was lost.
     */
    public static function maybeResume()
    {
        $state = self::state();

        if ($state && $state['status'] === 'running' && function_exists('\as_next_scheduled_action') && !\as_next_scheduled_action(self::HOOK)) {
            \as_schedule_single_action(time(), self::HOOK, [], 'fluent-community');
        }
    }

    /**
     * @param string $column
     * @param string $table
     * @param string $where static SQL from sources()
     * @param string $from inclusive local date
     * @param string $toExclusive exclusive local date
     */
    private static function fillWindow($column, $table, $where, $from, $toExclusive)
    {
        global $wpdb;
        $dailyTable = $wpdb->prefix . 'fcom_activity_daily';

        // $column, $table and $where come from sources(), never from input.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            "INSERT INTO {$dailyTable} (`user_id`, `stat_date`, `{$column}`)
             SELECT `user_id`, DATE(`created_at`), LEAST(COUNT(*), 65535)
             FROM {$table}
             WHERE {$where} AND `user_id` IS NOT NULL AND `user_id` > 0 AND `created_at` >= %s AND `created_at` < %s
             GROUP BY `user_id`, DATE(`created_at`)
             ON DUPLICATE KEY UPDATE `{$column}` = GREATEST(`{$column}`, VALUES(`{$column}`))",
            $from . ' 00:00:00',
            $toExclusive . ' 00:00:00'
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    private static function earliestDate($table, $where)
    {
        global $wpdb;

        $earliest = $wpdb->get_var("SELECT MIN(`created_at`) FROM {$table} WHERE {$where} AND `created_at` IS NOT NULL"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table and $where come from sources(), which is static SQL

        if (!$earliest) {
            return null;
        }

        // Rows older than the retention window would be purged by the next daily job.
        $cutoff = ActivityTracker::retentionCutoff();

        return ($cutoff && $cutoff > substr($earliest, 0, 10)) ? $cutoff : substr($earliest, 0, 10);
    }

    private static function nextColumn($state)
    {
        $state['column'] = $state['queue'] ? array_shift($state['queue']) : null;
        $state['cursor'] = null;

        return $state;
    }
}
