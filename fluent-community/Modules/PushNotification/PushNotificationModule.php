<?php

namespace FluentCommunity\Modules\PushNotification;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Services\NotificationPref;
use FluentCommunity\Framework\Support\Arr;

class PushNotificationModule
{
    const SETUP_NOT_INSTALLED = 'not_installed';
    const SETUP_INACTIVE = 'inactive';
    const SETUP_DISABLED = 'disabled';
    const SETUP_INCOMPLETE = 'incomplete';
    const SETUP_READY = 'ready';

    const PROMPT_PLACEMENTS = ['feed_sidebar','profile_notification_prefs','profile_sidebar','notification_popover'];

    const COMMENT_ACTIONS = [
        'fluent_community/notification/comment/notifed_to_author',
        'fluent_community/notification/comment/notifed_to_mentions',
        'fluent_community/notification/comment/notifed_to_thread_commetenter',
        'fluent_community/notification/comment/notifed_to_other_users'
    ];

    const PUSHED_ACTIONS = ['comment_added', 'child_comment_added', 'mention_added'];

    public static function isFluentNotifyActive()
    {
        return self::getSetupState() === self::SETUP_READY;
    }

    /**
     * Which step of the FluentNotify setup is still outstanding, so the settings
     * screen can say what to do rather than only that something is missing.
     *
     * @return string One of the SETUP_* constants.
     */
    public static function getSetupState()
    {
        if (!defined('FLUENT_NOTIFY_PLUGIN_VERSION')) {
            // The files can be there while the plugin is deactivated, which needs
            // activating rather than another download.
            if (file_exists(WP_PLUGIN_DIR . '/fluent-notify/fluent-notify.php')) {
                return self::SETUP_INACTIVE;
            }

            return self::SETUP_NOT_INSTALLED;
        }

        if (!\FluentNotify\App\Services\Helper::isEnabled()) {
            return self::SETUP_DISABLED;
        }

        if (!\FluentNotify\App\Services\Helper::isConfigComplete()) {
            return self::SETUP_INCOMPLETE;
        }

        return self::SETUP_READY;
    }

    public static function getSettingsUrl()
    {
        // FluentNotify picks hash or history routing at runtime; the fragment is
        // ignored under history routing, which lands on its dashboard instead.
        return admin_url('admin.php?page=fluent-notify#/settings');
    }

    public static function isAvailable()
    {
        $pushEnabledInCommunity = Arr::get(Utility::getPushNotificationSettings(), 'push_enabled') === 'yes';
        
        return self::isFluentNotifyActive() && $pushEnabledInCommunity;
    }

    public static function getPushedCommentIds($userId, $commentIds)
    {
        if (!$userId || !$commentIds || !class_exists('\FluentNotify\App\Models\NotificationLog')) {
            return [];
        }

        $pushed = \FluentNotify\App\Models\NotificationLog::query()
            ->join('fn_subscriptions', 'fn_subscriptions.id', '=', 'fn_notifications.subscription_id')
            ->where('fn_subscriptions.user_id', $userId)
            ->where('fn_notifications.source', 'community_comment')
            ->whereIn('fn_notifications.source_id', $commentIds)
            ->whereNotIn('fn_notifications.status', ['fcm_failed', 'fcm_skipped'])
            ->pluck('fn_notifications.source_id')
            ->toArray();

        return array_values(array_unique(array_map('intval', $pushed)));
    }

    public function register()
    {
        if (!self::isAvailable()) return;

        foreach (self::COMMENT_ACTIONS as $action) {
            add_action($action, [$this, 'handleCommentNotification'], 10, 1);
        }

        // let's load the assets
        add_filter('fluent_community/portal_data_vars', function ($vars) {
            if (!is_user_logged_in()) {
                return $vars;
            }

            $vars['js_files']['push_notification'] = [
                'url'  => \FluentNotify\App\Vite::getStaticSrcUrl('push_notification.js'),
                'deps' => [],
            ];
            $vars['js_vars']['fluentNotifyPublic'] = \FluentNotify\App\Services\Helper::getPublicConfig();

            return $vars;
        });

        add_filter('fluent_community/portal_vars', function ($vars) {
            if (!is_user_logged_in()) {
                return $vars;
            }

            $vars['has_push_notification'] = true;
            $vars['push_prompt_placements'] = Arr::get(Utility::getPushNotificationSettings(), 'prompt_placements');

            return $vars;
        });

    }

    public function handleCommentNotification($eventData)
    {
        $key = Arr::get($eventData, 'key');
        $comment = Arr::get($eventData, 'comment');
        $feed = Arr::get($eventData, 'feed');

        if (!$feed || !$comment) return;

        // Hook event key => notification event in NotificationPref::NOTIFICATION_EVENTS.
        $hookToEvent = [
            'notifed_to_author'             => 'comment',
            'notifed_to_thread_commetenter' => 'reply',
            'notifed_to_mentions'           => 'mention',
            'notifed_to_other_users'        => 'co_comment'
        ];

        $userIds = NotificationPref::filterPushUserIds(
            Arr::get($eventData, 'user_ids', []),
            Arr::get($hookToEvent, $key, '')
        );

        if (!$userIds) return;

        $xprofile = $comment->xprofile;

        $notification = Arr::get($eventData, 'notification');
        $content = Helper::getHumanExcerpt($comment->message_rendered ?: $comment->message, 100);
        if (!$content) {
            // The co-comment hook passes the notification as an array, the others as a model.
            $fallback = is_array($notification) ? Arr::get($notification, 'content') : $notification->content;
            $content = Helper::getHumanExcerpt($fallback, 100);
        }

        $commenter = $xprofile ? $xprofile->display_name : '' . __('Someone', 'fluent-community');
        $feedTitle = $feed->title ? $feed->title : __('post', 'fluent-community');

        // get the first name only
        $commenterParts = explode(' ', $commenter);
        if (count($commenterParts) > 0) {
            $commenter = $commenterParts[0];
        }

        $title = '';
        switch ($key):
            case 'notifed_to_author':
                /* translators: %1$s is the commenter name, %2$s is the post title */
                $title = \sprintf(__('New comment by %1$s on: %2$s', 'fluent-community'), $commenter, $feedTitle);
                break;
            case 'notifed_to_mentions':
                /* translators: %1$s is the commenter name, %2$s is the post title */
                $title = \sprintf(__('%1$s mentioned you on: %2$s', 'fluent-community'), $commenter, $feedTitle);
                break;
            case 'notifed_to_other_users':
                /* translators: %1$s is the commenter name, %2$s is the post title */
                $title = \sprintf(__('%1$s also commented on: %2$s', 'fluent-community'), $commenter, $feedTitle);
                break;
            case 'notifed_to_thread_commetenter':
                /* translators: %1$s is the commenter name, %2$s is the post title */
                $title = \sprintf(__('%1$s replied to a comment on: %2$s', 'fluent-community'), $commenter, $feedTitle);
                break;
            default:
                /* translators: %1$s is the commenter name, %2$s is the post title */
                $content = \sprintf(__('Comment by %1$s on %2$s', 'fluent-community'), $commenter, $feedTitle);
        endswitch;

        $actionUrl = add_query_arg([
            'comment_id' => $comment->id,
            'fcom_pn'    => 'true',
            'feed_id'    => $feed->id,
        ], $feed->getPermalink());

        do_action('fluent_notify/schedule_notifications', $userIds, [
            'user_ids'   => $userIds,
            'title'      => $title,
            'message'    => $content,
            'action_url' => $actionUrl,
            'icon'       => $xprofile->avatar,
            'source'     => 'community_comment',
            'source_id'  => $comment->id,
        ]);
    }
}
