<?php

namespace FluentCommunity\App\Hooks\Handlers;

use FluentCommunity\App\Services\Insights\Tracking\ActivityTracker;
use FluentCommunity\App\Services\Insights\Tracking\Backfill;
use FluentCommunity\Database\Migrations\InsightsMigrator;

/**
 * Feeds fcom_activity_daily and fcom_insight_counters from existing events.
 * Visits are recorded from ActivityMonitorHandler::trackActivity(), next to
 * the throttled last_activity write.
 */
class InsightsTrackingHandler
{
    public function register()
    {
        add_action('fluent_community/feed/created', [$this, 'onFeedCreated'], 20, 1);
        add_action('fluent_community/comment_added', [$this, 'onCommentAdded'], 20, 2);
        add_action('fluent_community/feed/react_added', [$this, 'onReactionAdded'], 20, 2);
        add_action('fluent_community/course/lesson_completed', [$this, 'onLessonCompleted'], 20, 2);
        add_action('fluent_messaging/after_add_message', [$this, 'onMessageAdded'], 20, 1);

        add_action('fluent_community/space/joined', [$this, 'onSpaceJoined'], 20, 2);
        add_action('fluent_community/space/user_left', [$this, 'onSpaceLeft'], 20, 2);
        add_action('fluent_community/course/enrolled', [$this, 'onCourseEnrolled'], 20, 2);
        add_action('fluent_community/course/student_left', [$this, 'onCourseLeft'], 20, 2);

        add_action('deleted_user', [$this, 'onUserDeleted'], 20, 1);
        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerEraser']);

        add_action(Backfill::HOOK, [Backfill::class, 'handleJob']);
        add_action(InsightsMigrator::INDEX_JOB_HOOK, [$this, 'buildPendingIndexes']);
        add_action('fluent_community_daily_jobs', [$this, 'dailyMaintenance'], 20);
    }

    public function onFeedCreated($feed)
    {
        if (!$feed || $feed->type !== 'text' || $feed->status !== 'published') {
            return;
        }

        ActivityTracker::increment($feed->user_id, 'posts', $feed->created_at);
    }

    public function onCommentAdded($comment, $feed = null)
    {
        if (!$comment || $comment->type !== 'comment' || $comment->status !== 'published') {
            return;
        }

        ActivityTracker::increment($comment->user_id, 'comments', $comment->created_at);
    }

    public function onReactionAdded($reaction, $feed = null)
    {
        if (!$reaction || $reaction->type !== 'like' || !in_array($reaction->object_type, ['feed', 'comment'], true)) {
            return;
        }

        ActivityTracker::increment($reaction->user_id, 'reactions', $reaction->created_at);
    }

    public function onLessonCompleted($lesson, $userId)
    {
        ActivityTracker::increment($userId, 'lessons');
    }

    public function onMessageAdded($message)
    {
        if (!is_object($message) || empty($message->user_id)) {
            return;
        }

        ActivityTracker::increment($message->user_id, 'messages', isset($message->created_at) ? $message->created_at : null);
    }

    public function onSpaceJoined($space, $userId)
    {
        if ($space && !empty($space->id)) {
            ActivityTracker::incrementCounter('space', $space->id, 'space_joined');
        }
    }

    public function onSpaceLeft($space, $userId)
    {
        if ($space && !empty($space->id)) {
            ActivityTracker::incrementCounter('space', $space->id, 'space_left');
        }
    }

    public function onCourseEnrolled($course, $userId)
    {
        if ($course && !empty($course->id)) {
            ActivityTracker::incrementCounter('course', $course->id, 'space_joined');
        }
    }

    public function onCourseLeft($course, $userId)
    {
        if ($course && !empty($course->id)) {
            ActivityTracker::incrementCounter('course', $course->id, 'space_left');
        }
    }

    public function onUserDeleted($userId)
    {
        ActivityTracker::deleteUser($userId);
    }

    public function registerExporter($exporters)
    {
        $exporters['fluent-community-insights'] = [
            'exporter_friendly_name' => __('Community activity history', 'fluent-community'),
            'callback'               => [$this, 'exportPersonalData'],
        ];

        return $exporters;
    }

    public function registerEraser($erasers)
    {
        $erasers['fluent-community-insights'] = [
            'eraser_friendly_name' => __('Community activity history', 'fluent-community'),
            'callback'             => [$this, 'erasePersonalData'],
        ];

        return $erasers;
    }

    public function exportPersonalData($email, $page = 1)
    {
        $user = get_user_by('email', $email);
        $items = [];

        if ($user) {
            foreach (ActivityTracker::getUserRows($user->ID) as $row) {
                $data = [];
                foreach (array_merge(['stat_date'], ActivityTracker::COLUMNS) as $column) {
                    $data[] = ['name' => $column, 'value' => $row[$column]];
                }

                $items[] = [
                    'group_id'    => 'fluent-community-insights',
                    'group_label' => __('Community activity history', 'fluent-community'),
                    'item_id'     => 'fcom-activity-' . $row['stat_date'],
                    'data'        => $data,
                ];
            }
        }

        return ['data' => $items, 'done' => true];
    }

    public function erasePersonalData($email, $page = 1)
    {
        $user = get_user_by('email', $email);
        $removed = $user ? ActivityTracker::deleteUser($user->ID) : 0;

        return [
            'items_removed'  => $removed > 0,
            'items_retained' => false,
            'messages'       => [],
            'done'           => true,
        ];
    }

    public function buildPendingIndexes()
    {
        InsightsMigrator::addIndexes(true);
    }

    public function dailyMaintenance()
    {
        ActivityTracker::purgeExpired();
        Backfill::maybeResume();
    }
}
