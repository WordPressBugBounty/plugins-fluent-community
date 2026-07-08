<?php

namespace FluentCommunity\App\Hooks\Handlers;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Models\NotificationSubscription;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\Framework\Support\DateTime;
use FluentCommunity\App\Services\Helper;

class Scheduler
{
    public function register()
    {
        add_action('fluent_community_scheduled_hour_jobs', function () {
            $this->checkDailyDigestSchedule();
            do_action('fluent_community/maybe_delete_draft_medias');
        }, 10);

        add_action('fluent_community_send_daily_digest_init', function () {
            do_action('fluent_community_send_daily_digest');
        }, 10);

        add_action('fluent_community_daily_jobs', function () {
            // let's fire the old email notifications hook
            do_action('fluent_community/remove_old_notifications');
            $this->maybeRemoveOldScheuledActionLogs();
        }, 10);

    }

    public function checkDailyDigestSchedule($willReset = false)
    {
        $notificationSettings = Utility::getEmailNotificationSettings();
        $globalStatus = Arr::get($notificationSettings, 'digest_email_status', 'no');

        if ($globalStatus != 'yes') {
            // Global Status is false
            // Check if any user enabled that or not
            $isEnabled = NotificationSubscription::query()->where('notification_type', 'digest_mail')
                ->where('is_read', 1)
                ->exists();

            if (!$isEnabled) {
                // unset the scheduled action
                if (\as_next_scheduled_action('fluent_community_send_daily_digest_init')) {
                    \as_unschedule_all_actions('fluent_community_send_daily_digest_init', [], 'fluent-community');
                }
                return;
            }
        }

        $notificationDay = Arr::get($notificationSettings, 'digest_mail_day');
        $digestTime = Arr::get($notificationSettings, 'daily_digest_time', '09:00');

        // Let's check if we have the daily digest action scheduled or not
        if (!\as_next_scheduled_action('fluent_community_send_daily_digest_init')) {
            $timestamp = $this->getNextOccurrenceTimestamp(Helper::getFullDayName($notificationDay), $digestTime);
            if ($timestamp) {
                \as_schedule_single_action($timestamp, 'fluent_community_send_daily_digest_init', [], 'fluent-community', true);
            }
        }
    }

    private function maybeRemoveOldScheuledActionLogs($group_slug = 'fluent-community', $days_old = 7)
    {
        global $wpdb;

        // Get the timestamp for $days_old days ago
        $cutoff_date = gmdate('Y-m-d H:i:s', strtotime("-{$days_old} days"));

        // Get the group ID
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $group_id = $wpdb->get_var($wpdb->prepare(
            "SELECT group_id FROM {$wpdb->prefix}actionscheduler_groups WHERE slug = %s",
            $group_slug
        ));

        if (!$group_id) {
            return false; // Group not found
        }

        // Delete old actions and their associated logs in bounded batches. Action
        // Scheduler tables are read/written constantly by the queue runner, so a
        // single unbounded multi-table DELETE could hold a large lock and bloat the
        // transaction on busy sites. We loop while batches stay full and we are still
        // within a safe time budget (mirrors the other cleanup jobs in this plugin).
        $batchSize = 500;
        $totalDeleted = 0;

        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $actionIds = $wpdb->get_col($wpdb->prepare(
                "SELECT action_id
                 FROM {$wpdb->prefix}actionscheduler_actions
                 WHERE group_id = %d
                 AND status IN ('complete', 'failed')
                 AND scheduled_date_gmt < %s
                 LIMIT %d",
                $group_id, $cutoff_date, $batchSize
            ));

            if (!$actionIds) {
                break;
            }

            // Safe to interpolate: every id is cast to an integer.
            $ids = implode(',', array_map('intval', $actionIds));

            // Remove the associated logs first, then the actions themselves.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("DELETE FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id IN ({$ids})");
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
            $totalDeleted += (int) $wpdb->query("DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id IN ({$ids})");

            $isFullBatch = count($actionIds) === $batchSize;
        } while ($isFullBatch && (microtime(true) - FLUENT_COMMUNITY_START_TIME) < 30);

        // Clean up orphaned claims (claims whose actions no longer exist)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query("
        DELETE c
        FROM {$wpdb->prefix}actionscheduler_claims c
        LEFT JOIN {$wpdb->prefix}actionscheduler_actions a ON c.claim_id = a.claim_id
        WHERE a.action_id IS NULL");

        return $totalDeleted;
    }

    private function getNextOccurrenceTimestamp($dayname, $time)
    {
        // Ensure dayname is lowercase and valid
        $dayname = strtolower($dayname);
        $valid_days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        if (!in_array($dayname, $valid_days)) {
            return false;
        }

        // Get current time in WordPress timezone
        $current = current_datetime();

        // Create target DateTime with "next" modifier in WordPress timezone
        $target = new \DateTime('next ' . $dayname . ' ' . $time, wp_timezone());

        $targetDate = $target->format('Ymd');
        $currentDate = $current->format('Ymd');

        // If it's the same day and time has passed, move to next week
        if ($targetDate === $currentDate || $currentDate > $targetDate) {
            $target->modify('+7 days');
        }

        // Switch to UTC timezone and get timestamp
        $target->setTimezone(new \DateTimeZone('UTC'));
        return $target->getTimestamp();
    }

}
