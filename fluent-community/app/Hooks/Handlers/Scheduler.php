<?php

namespace FluentCommunity\App\Hooks\Handlers;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Models\NotificationSubscription;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\Framework\Support\Arr;

class Scheduler
{

    public function register()
    {
        add_action('fluent_community_scheduled_hour_jobs', function () {
            $this->checkDailyDigestSchedule();
            do_action('fluent_community/maybe_delete_draft_medias');
        });

        add_action('fluent_community_send_daily_digest_init', function () {
            do_action('fluent_community_send_daily_digest');
        });

        add_action('fluent_community_daily_jobs', function () {
            // let's fire the old email notifications hook
            do_action('fluent_community/remove_old_notifications');
            $this->maybeRemoveOldScheuledActionLogs();
        });

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
            $notificationDay = 'next ' . $notificationDay . 'day ' . $digestTime;
            $diff = time() - current_time('timestamp');
            $timeStamp = strtotime($notificationDay) + $diff;
            \as_schedule_single_action($timeStamp, 'fluent_community_send_daily_digest_init', [], 'fluent-community', true);
        }
    }

    private function maybeRemoveOldScheuledActionLogs($group_slug = 'fluent-community', $days_old = 7)
    {
        global $wpdb;

        // Get the timestamp for 7 days ago
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));

        // Get the group ID
        $group_id = $wpdb->get_var($wpdb->prepare(
            "SELECT group_id FROM {$wpdb->prefix}actionscheduler_groups WHERE slug = %s",
            $group_slug
        ));

        if (!$group_id) {
            return false; // Group not found
        }

        // Delete old actions and their associated logs
        $deleted = $wpdb->query($wpdb->prepare("
        DELETE a, l
        FROM {$wpdb->prefix}actionscheduler_actions a
        LEFT JOIN {$wpdb->prefix}actionscheduler_logs l ON a.action_id = l.action_id
        WHERE a.group_id = %d
        AND a.status IN ('complete', 'failed')
        AND a.scheduled_date_gmt < %s", $group_id, $cutoff_date));

        // Clean up orphaned claims
        $wpdb->query("
        DELETE c
        FROM {$wpdb->prefix}actionscheduler_claims c
        LEFT JOIN {$wpdb->prefix}actionscheduler_actions a ON c.claim_id = a.claim_id
        WHERE a.action_id IS NULL");

        return $deleted;
    }
}
