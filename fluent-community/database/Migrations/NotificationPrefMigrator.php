<?php
// phpcs:disable

namespace FluentCommunity\Database\Migrations;

use FluentCommunity\App\Services\NotificationPref;

class NotificationPrefMigrator
{
    /**
     * Legacy pref rows in fcom_notification_users mapped onto the
     * (channel, event_key) grid. The legacy keys baked the channel into the key
     * name - com_my_post_mail / com_my_post_push - which is what this table undoes.
     *
     * Deliberately a frozen literal rather than derived from
     * NotificationPref::prefKeyMap(): this describes what the rows meant at the
     * time of the move, and must not shift if the live vocabulary later does.
     *
     * legacy notification_type => [channel, event_key, is_object_scoped]
     */
    private static $legacyMap = [
        'com_my_post_mail'        => ['mail', 'comment', false],
        'com_my_post_push'        => ['push', 'comment', false],
        'reply_my_com_mail'       => ['mail', 'reply', false],
        'reply_my_com_push'       => ['push', 'reply', false],
        'mention_mail'            => ['mail', 'mention', false],
        'mention_push'            => ['push', 'mention', false],
        'digest_mail'             => ['mail', 'digest', false],
        'message_email_frequency' => ['mail', 'message_frequency', false],
        'np_by_member_mail'       => ['mail', 'np_by_member', true],
        'np_by_admin_mail'        => ['mail', 'np_by_admin', true],
    ];

    /** Legacy rows scanned per statement. Bounded work, not bounded matches. */
    const BATCH_SIZE = 5000;

    /** Seconds of this request the backfill may use before deferring the rest. */
    const TIME_BUDGET = 15;

    const DONE_OPTION = 'fluent_community_notification_pref_backfilled';

    const CURSOR_OPTION = 'fluent_community_notification_pref_backfill_cursor';

    const ERROR_OPTION = 'fluent_community_notification_pref_backfill_error';

    const RESUME_HOOK = 'fluent_community/migrate_notification_prefs';

    /** Legacy rows deleted per statement, once the copy is done and verified. */
    const DELETE_BATCH_SIZE = 2000;

    /**
     * Migrate the table.
     *
     * @return void
     */
    public static function migrate()
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        $table = $wpdb->prefix . 'fcom_notification_prefs';
        $indexPrefix = $wpdb->prefix . 'fcom_np_';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            /*
             * object_id is NOT NULL DEFAULT 0 rather than nullable on purpose:
             * MySQL treats NULLs as distinct inside a UNIQUE key, so a nullable
             * object_id would let duplicate global prefs through the unique index.
             * 0 means "global / not scoped to an object".
             *
             * The `fanout` index is ordered for the recipient-selection queries in
             * EmailNotificationHandler: channel + event_key + object_id + value are
             * all equality predicates, and the trailing user_id serves both the
             * EXISTS probe and the `ID > $lastSentUserId` batch cursor without a
             * lookup back to the row.
             *
             * The `uniq` key doubles as the per-user read index (it leads with
             * user_id), so no separate index is needed for getUserPrefs().
             */
            $sql = "CREATE TABLE $table (
                `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `channel` VARCHAR(20) NOT NULL DEFAULT 'mail',
                `event_key` VARCHAR(50) NOT NULL,
                `object_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `value` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                 UNIQUE KEY `{$indexPrefix}_uniq` (`user_id`, `channel`, `event_key`, `object_id`),
                 INDEX `{$indexPrefix}_fanout` (`channel`, `event_key`, `object_id`, `value`, `user_id`)
            ) $charsetCollate;";
            dbDelta($sql);
        }

        self::maybeBackfillFromLegacy();
    }

    /**
     * Copy object_type = 'notification_pref' rows out of fcom_notification_users.
     *
     * Resumable by design. The legacy table has no index leading with
     * object_type or notification_type, so a single filtered pass would be a full
     * scan of a table that grows with notification receipts rather than with
     * preferences. Instead this walks the primary key in fixed-size steps, so the
     * work per statement is bounded by rows scanned, not by rows matched, and a
     * partial run resumes from its cursor rather than starting over.
     *
     * A failed statement never advances the cursor and never sets the done flag:
     * a half-copied migration that reported success would silently drop members
     * back to the site defaults - someone who switched digest mail off would
     * start receiving it again - which is worse than a migration that retries.
     *
     * @return bool true when the backfill is complete
     */
    public static function maybeBackfillFromLegacy()
    {
        global $wpdb;

        if (get_option(self::DONE_OPTION)) {
            return true;
        }

        $legacyTable = $wpdb->prefix . 'fcom_notification_users';

        if ($wpdb->get_var("SHOW TABLES LIKE '$legacyTable'") != $legacyTable) {
            // Fresh install: nothing to carry over.
            self::markComplete();
            return true;
        }

        $maxId = (int)$wpdb->get_var("SELECT MAX(id) FROM {$legacyTable}");
        $cursor = (int)get_option(self::CURSOR_OPTION, 0);

        // One budget for the whole call, timed from here. Anything that resumes
        // does so in an Action Scheduler request of its own, with a fresh one.
        $startedAt = microtime(true);

        while ($cursor < $maxId) {
            // The id this batch ends on. Walks the primary key evenly whether or
            // not the id space is sparse, and never scans more than BATCH_SIZE.
            $batchEnd = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT MAX(id) FROM (
                    SELECT id FROM {$legacyTable} WHERE id > %d ORDER BY id ASC LIMIT %d
                 ) AS batch",
                $cursor,
                self::BATCH_SIZE
            ));

            if (!$batchEnd) {
                break;
            }

            if (!self::copyLegacyRange($cursor, $batchEnd)) {
                // Leave the cursor where it was so the range is retried intact.
                update_option(self::ERROR_OPTION, $wpdb->last_error, false);
                self::scheduleResume();
                return false;
            }

            $cursor = $batchEnd;
            update_option(self::CURSOR_OPTION, $cursor, false);

            if (microtime(true) - $startedAt > self::TIME_BUDGET) {
                // Out of budget for this call, but the cursor is durable.
                self::scheduleResume();
                return false;
            }
        }

        /*
         * The copy is done. Check that every legacy row has a counterpart before
         * removing anything - it is one query, and it is the difference between
         * deleting rows we copied and deleting rows we only think we copied.
         *
         * Note what it does not compare: the value. A member who changes a
         * preference after the migration legitimately makes the two differ, so
         * matching on value would report false gaps forever.
         */
        $unmigrated = self::countUnmigratedRows();

        /*
         * And separately: rows whose notification_type is not in $legacyMap at all.
         *
         * countUnmigratedRows() cannot see these. It joins the same map the copy
         * joins, so a key the map does not know about is absent from both sides of
         * that comparison and reads as zero - the copy skips it, the check passes
         * it, and an unscoped delete then removes a preference nobody carried over.
         * The check has to be asked about the rows the map does not cover, not only
         * about the rows it does.
         */
        $unmapped = self::countUnmappedRows();

        if ($unmigrated > 0 || $unmapped > 0) {
            // Copied data stands and the new table is authoritative, so this is
            // complete either way - but leave the source alone for inspection.
            $problems = [];

            if ($unmigrated > 0) {
                $problems[] = sprintf('%d legacy rows had no counterpart', $unmigrated);
            }

            if ($unmapped > 0) {
                $problems[] = sprintf('%d legacy rows used a key this version does not map', $unmapped);
            }

            update_option(self::ERROR_OPTION, implode('; ', $problems) . '; source left in place', false);
            self::markComplete();

            return true;
        }

        if (!self::deleteLegacyRows($startedAt)) {
            self::scheduleResume();

            return false;
        }

        // Clear any error recorded by an attempt that has since succeeded.
        delete_option(self::ERROR_OPTION);
        self::markComplete();

        return true;
    }

    /**
     * Remove the rows the copy read from.
     *
     * Batched because a single DELETE over a large table holds locks for as long
     * as it runs, and this table is read on every portal request by the unread
     * count. Not otherwise ceremonious: if the request dies partway, the done
     * flag is never set, the next pass re-runs a copy that is now a no-op and
     * carries on deleting.
     *
     * Public as a test seam, for the same reason copyLegacyRange() is: it is pure
     * DML, and what it is scoped to is the part worth pinning down.
     *
     * @param float $startedAt microtime this call began, for the shared budget
     * @return bool true when nothing is left to delete
     */
    public static function deleteLegacyRows($startedAt)
    {
        global $wpdb;

        $legacyTable = $wpdb->prefix . 'fcom_notification_users';

        $keys = array_keys(self::$legacyMap);
        $placeholders = implode(', ', array_fill(0, count($keys), '%s'));

        while (true) {
            /*
             * Pinned twice over: to the preference rows, so this cannot reach a
             * notification receipt (live data the ticker and the toast read), and
             * to the keys the copy above actually knows how to place, so a key
             * this version does not map survives rather than being deleted
             * uncopied. The guard in the caller should already have stopped us
             * before that could happen; this makes it true by construction
             * instead of by check.
             */
            $args = $keys;
            $args[] = self::DELETE_BATCH_SIZE;

            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$legacyTable}
                 WHERE `object_type` = 'notification_pref'
                   AND `notification_type` IN ({$placeholders})
                 LIMIT %d",
                $args
            ));

            if ($deleted === false) {
                update_option(self::ERROR_OPTION, $wpdb->last_error, false);

                return false;
            }

            if (!$deleted) {
                return true;
            }

            if (microtime(true) - $startedAt > self::TIME_BUDGET) {
                return false;
            }
        }
    }

    /**
     * Copy one primary-key range in a single statement.
     *
     * ON DUPLICATE KEY UPDATE rather than INSERT IGNORE: both make a re-run of an
     * already-copied range a no-op, but IGNORE also downgrades
     * genuine errors (truncation, constraint violations) to warnings, which is
     * the exact silent failure this method exists to report. The update is a
     * deliberate self-assignment rather than a write, so a range replayed after
     * the member has since changed that preference cannot overwrite their newer
     * choice. The column is table-qualified because the joined legacy table
     * shares column names with the target.
     *
     * Public as a test seam: this is the mapping the whole migration rests on,
     * and it is pure DML, so the integration tier can exercise it inside its
     * transaction without the implicit COMMIT that CREATE TABLE would cause.
     *
     * @param int $fromId exclusive
     * @param int $toId   inclusive
     * @return bool
     */
    public static function copyLegacyRange($fromId, $toId)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'fcom_notification_prefs';
        $legacyTable = $wpdb->prefix . 'fcom_notification_users';

        $mapRows = [];
        $args = [];

        foreach (self::$legacyMap as $legacyKey => $config) {
            $mapRows[] = 'SELECT %s AS lk, %s AS ch, %s AS ek, %d AS scoped';
            $args[] = $legacyKey;
            $args[] = $config[0];
            $args[] = $config[1];
            $args[] = $config[2] ? 1 : 0;
        }

        $args[] = $fromId;
        $args[] = $toId;

        $sql = "INSERT INTO {$table}
                    (`user_id`, `channel`, `event_key`, `object_id`, `value`, `created_at`, `updated_at`)
                SELECT l.`user_id`, m.ch, m.ek,
                       CASE WHEN m.scoped = 1 THEN COALESCE(l.`object_id`, 0) ELSE 0 END,
                       COALESCE(l.`is_read`, 0), l.`created_at`, l.`updated_at`
                FROM {$legacyTable} l
                INNER JOIN (" . implode(' UNION ALL ', $mapRows) . ") m ON m.lk = l.`notification_type`
                WHERE l.`object_type` = 'notification_pref'
                  AND l.`user_id` IS NOT NULL
                  AND l.`id` > %d
                  AND l.`id` <= %d
                ON DUPLICATE KEY UPDATE {$table}.`value` = {$table}.`value`";

        return $wpdb->query($wpdb->prepare($sql, $args)) !== false;
    }

    /**
     * Legacy preference rows with no counterpart in the new table. Zero is the
     * only acceptable answer once the cursor has run out.
     *
     * @return int
     */
    public static function countUnmigratedRows()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'fcom_notification_prefs';
        $legacyTable = $wpdb->prefix . 'fcom_notification_users';

        $mapRows = [];
        $args = [];

        foreach (self::$legacyMap as $legacyKey => $config) {
            $mapRows[] = 'SELECT %s AS lk, %s AS ch, %s AS ek, %d AS scoped';
            $args[] = $legacyKey;
            $args[] = $config[0];
            $args[] = $config[1];
            $args[] = $config[2] ? 1 : 0;
        }

        $sql = "SELECT COUNT(*)
                FROM {$legacyTable} l
                INNER JOIN (" . implode(' UNION ALL ', $mapRows) . ") m ON m.lk = l.`notification_type`
                LEFT JOIN {$table} p
                       ON p.`user_id` = l.`user_id`
                      AND p.`channel` = m.ch
                      AND p.`event_key` = m.ek
                      AND p.`object_id` = CASE WHEN m.scoped = 1 THEN COALESCE(l.`object_id`, 0) ELSE 0 END
                WHERE l.`object_type` = 'notification_pref'
                  AND l.`user_id` IS NOT NULL
                  AND p.`id` IS NULL";

        return (int)$wpdb->get_var($wpdb->prepare($sql, $args));
    }

    /**
     * Legacy preference rows whose key is not in $legacyMap.
     *
     * The blind spot in countUnmigratedRows(): that query joins the map, so it can
     * only ever report on keys the map contains. This one asks the complement, and
     * a non-zero answer means the vocabulary has drifted from the frozen map and
     * the source must not be deleted.
     *
     * A NULL notification_type counts as unmapped. It is as unplaceable as an
     * unknown one, and SQL's NOT IN would otherwise return NULL and drop it.
     *
     * @return int
     */
    public static function countUnmappedRows()
    {
        global $wpdb;

        $legacyTable = $wpdb->prefix . 'fcom_notification_users';

        $keys = array_keys(self::$legacyMap);
        $placeholders = implode(', ', array_fill(0, count($keys), '%s'));

        $sql = "SELECT COUNT(*)
                FROM {$legacyTable}
                WHERE `object_type` = 'notification_pref'
                  AND (`notification_type` IS NULL OR `notification_type` NOT IN ({$placeholders}))";

        return (int)$wpdb->get_var($wpdb->prepare($sql, $keys));
    }

    /**
     * Continuation entry point. Deliberately not gated on the plugin's db-version
     * option: boot/app.php writes that as soon as DBMigrator::run() returns, so a
     * backfill that deferred work would never be reached through the migrator again.
     *
     * @return void
     */
    public static function continueBackfill()
    {
        if (get_option(self::DONE_OPTION)) {
            return;
        }

        self::maybeBackfillFromLegacy();
    }

    /**
     * @return void
     */
    private static function scheduleResume()
    {
        if (!function_exists('as_next_scheduled_action') || !function_exists('as_schedule_single_action')) {
            return;
        }

        if (\as_next_scheduled_action(self::RESUME_HOOK, [], 'fluent-community')) {
            return;
        }

        \as_schedule_single_action(time() + 60, self::RESUME_HOOK, [], 'fluent-community', true);
    }

    /**
     * @return void
     */
    private static function markComplete()
    {
        update_option(self::DONE_OPTION, 'yes', false);
        delete_option(self::CURSOR_OPTION);

        /*
         * Drop any aggregate computed while this table was still filling up.
         *
         * NotificationPref::hasAnyEnabled() reads a denormalized option and only
         * the preference write path refreshes it, so a "nobody has the digest on"
         * answer derived from a partial table would outlive the migration that
         * made it wrong - and the hourly scheduler unschedules the digest on it.
         * Deleting the option rather than recomputing it here keeps the migration
         * off the read path: the next call recomputes from a table that is now
         * whole.
         */
        delete_option(NotificationPref::AGGREGATE_OPTION);
    }
}
