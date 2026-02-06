<?php

namespace FluentCommunity\Modules\PushNotification;

use FluentCommunity\App\Services\Helper;
use FluentCommunity\Framework\Support\Arr;

class PushNotificationModule
{
    public function register()
    {
        if (!defined('FLUENT_NOTIFY_PLUGIN_VERSION') || !\FluentNotify\App\Services\Helper::isEnabled()) {
            return;
        }

        $commentActions = [
            'fluent_community/notification/comment/notifed_to_author',
           // 'fluent_community/notification/comment/notifed_to_author',
            'fluent_community/notification/comment/notifed_to_mentions',
            //'fluent_community/notification/comment/notifed_to_other_users',
            'fluent_community/notification/comment/notifed_to_thread_commetenter'
        ];
        foreach ($commentActions as $action) {
            add_action($action, [$this, 'handleCommentNotification'], 10, 1);
        }

        // let's load the assets
        add_filter('fluent_community/portal_data_vars', function ($vars) {
            if (!is_user_logged_in()) {
                return $vars;
            }

            $vars['js_files']['push_notification'] = [
                'url'  => \FluentNotify\App\Vite::getStaticSrcUrl('push_notification.js'),
                'deps' => []
            ];
            $vars['js_vars']['fluentNotifyPublic'] = \FluentNotify\App\Services\Helper::getPublicConfig();

            return $vars;
        });

        add_filter('fluent_community/portal_vars', function ($vars) {
            if (!is_user_logged_in()) {
                return $vars;
            }

            $vars['has_push_notification'] = true;

            return $vars;
        });

    }

    public function handleCommentNotification($eventData)
    {
        $key = Arr::get($eventData, 'key');
        $comment = Arr::get($eventData, 'comment');
        $feed = Arr::get($eventData, 'feed');

        $xprofile = $comment->xprofile;

        if (!$feed || !$comment) {
            return;
        }

        $notification = Arr::get($eventData, 'notification');
        $content = Helper::getHumanExcerpt($comment->message, 100);
        if (!$content) {
            $content = Helper::getHumanExcerpt($notification->content, 100);
        }

        $commenter = $xprofile ? $xprofile->display_name : '' . __('Someone', 'fluent-community');
        $feedTitle = $feed->title ? $feed->title : __('post', 'fluent-community');

        // get the first name only
        $commenterParts = explode(' ', $commenter);
        if (count($commenterParts) > 0) {
            $commenter = $commenterParts[0];
        }

        switch ($key):
            case 'notifed_to_author':
                $title = \sprintf(__('💬 by %1$s: %2$s', 'fluent-community'), $commenter, $feedTitle);
                break;
            case 'notifed_to_mentions':
                $title = \sprintf(__('%1s mentioned you on: %2$s', 'fluent-community'), $commenter, $feedTitle);
                break;
            case 'notifed_to_other_users':
                $title = \sprintf(__('New comment by %1$s on: %2$s', 'fluent-community'), $commenter, $feedTitle);
                break;
            case 'notifed_to_thread_commetenter':
                $title = \sprintf(__('%1$s replied to a comment on: %2$s', 'fluent-community'), $commenter, $feedTitle);
                break;
            default:
                $content = \sprintf(__('Comment by %1$s on %2$s', 'fluent-community'), $commenter, $feedTitle);
        endswitch;

        $userIds = Arr::get($eventData, 'user_ids', []);
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

