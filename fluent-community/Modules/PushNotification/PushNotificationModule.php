<?php

namespace FluentCommunity\Modules\PushNotification;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Services\NotificationPref;
use FluentCommunity\App\Services\OnboardingService;
use FluentCommunity\Framework\Support\Arr;

class PushNotificationModule
{
    const PROMPT_PLACEMENTS = ['feed_sidebar','profile_notification_prefs','profile_sidebar','notification_popover'];

    const COMMENT_ACTIONS = [
        'fluent_community/notification/comment/notifed_to_author',
        'fluent_community/notification/comment/notifed_to_mentions',
        'fluent_community/notification/comment/notifed_to_thread_commetenter',
        'fluent_community/notification/comment/notifed_to_other_users'
    ];

    public static function isFluentNotifyActive()
    {
        if (!defined('FLUENT_NOTIFY_PLUGIN_VERSION')) {
            return false;
        }

        return \FluentNotify\App\Services\Helper::isEnabled()
            && \FluentNotify\App\Services\Helper::isConfigComplete();
    }

    public static function isAvailable()
    {
        $pushEnabledInCommunity = Arr::get(Utility::getPushNotificationSettings(), 'push_enabled') === 'yes';
        
        return self::isFluentNotifyActive() && $pushEnabledInCommunity;
    }

    public function register()
    {
        add_action('fluent_community/install_fluent_notify_plugin', function () {
            OnboardingService::backgroundInstaller([
                'name'      => 'Fluent Notify',
                'repo-slug' => 'fluent-notify',
                'file'      => 'fluent-notify.php'
            ], 'https://fluentapi.wpmanageninja.com/addons/download/fluent-notify/1.0.0.zip');
        });

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

        $feedPermalik = $feed->getPermalink() . '?comment_id=' . $comment->id;

        do_action('fluent_notify/schedule_notifications', $userIds, [
            'user_ids'   => $userIds,
            'title'      => $title,
            'message'    => $content,
            'action_url' => $feedPermalik,
            'icon'       => $xprofile->avatar,
            'source'     => 'community_comment',
            'source_id'  => $comment->id,
        ]);
    }
}
