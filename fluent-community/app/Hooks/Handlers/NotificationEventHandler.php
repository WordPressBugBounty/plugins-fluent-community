<?php

namespace FluentCommunity\App\Hooks\Handlers;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Models\Comment;
use FluentCommunity\App\Models\Feed;
use FluentCommunity\App\Models\Notification;
use FluentCommunity\App\Models\NotificationSubscriber;
use FluentCommunity\App\Models\SpaceUserPivot;
use FluentCommunity\Framework\Support\Arr;

class NotificationEventHandler
{
    public function register()
    {
        add_action('fluent_community/comment_added', [$this, 'handleNewCommentEvent'], 10, 3);
        add_action('fluent_community/space_feed/created', [$this, 'handleNewSpaceFeed'], 10);
        add_action('fluent_community/feed/react_added', [$this, 'handleNewFeedReact'], 10, 2);

        add_action('fluent_community/space/member/role_updated', [$this, 'handleSpaceMemberRoleUpdated'], 10, 2);

        /*
         * Mentions handler
         */
        add_action('fluent_community/feed_mentioned', [$this, 'handleFeedMentions'], 10, 2);

    }

    public function handleNewCommentEvent($comment, $feed, $mentionedUsers = null)
    {
        $this->commentNotificationToAuthorFeed($comment, $feed, $mentionedUsers);

        // now notify all users who commented on this feed
        $this->commentNotificationToFeedCommenters($comment, $feed, $mentionedUsers);
    }

    public function handleNewSpaceFeed($feed)
    {
        if (!$this->willCreateFeedCreatedNotification($feed)) {
            return;
        }

        $user = $feed->user;
        $space = $feed->space;

        $this->maybeHasEveryoneTag($feed);

        $userIds = SpaceUserPivot::where('space_id', $space->id)
            ->where('user_id', '!=', $user->ID)
            ->where('status', 'active')
            ->pluck('user_id')
            ->toArray();

        if (!$userIds) {
            return;
        }

        $feedTitle = $feed->getHumanExcerpt(60);

        $notificationContent = \sprintf(
        /* translators: %1$s is the user name, %2$s is the feed title and %3$3s is the space title */
            __('%1$s posted %2$s in %3$s', 'fluent-community'),
            '<b class="fcom_nudn">' . $user->display_name . '</b>',
            '<span class="fcom_nft">' . $feedTitle . '</span>',
            '<b class="fcom_nst">' . $space->title . '</b>'
        );

        $route = $feed->getJsRoute();

        $notification = [
            'feed_id'         => $feed->id,
            'src_user_id'     => $user->ID,
            'src_object_type' => 'feed',
            'action'          => 'space_feed/created',
            'content'         => $notificationContent,
            'route'           => $route,
        ];

        $notification = Notification::create($notification);

        $notification->subscribe($userIds);
    }

    public function handleNewFeedReact($react, $feed)
    {
        if ($react->user_id == $feed->user_id) {
            return;
        }

        $feedTitle = $feed->getHumanExcerpt(60);
        $user = $react->user;

        if ($feed->reactions_count > 1) {
            // check if we have existing notification for this feed and user
            $existingNotification = Notification::where('feed_id', $feed->id)
                ->where('action', 'feed/react_added')
                ->first();

            if ($existingNotification) {
                $notificationContent = \sprintf(
                /* translators: %1$s is the user name, %2$s is the like count & %3$s is the feed title */
                    __('%1$s and %2$s other people loved %3$s', 'fluent-community'),
                    '<b class="fcom_nudn">' . $user->display_name . '</b>',
                    '<b class="fcom_nrc">' . ($feed->reactions_count - 1) . '</b>',
                    '<span class="fcom_nft">' . $feedTitle . '</span>'
                );

                $existingNotification->content = $notificationContent;
                $existingNotification->src_user_id = $user->ID;
                $existingNotification->save();
                NotificationSubscriber::where('object_id', $existingNotification->id)
                    ->where('user_id', $feed->user_id)
                    ->update([
                        'is_read'    => 0,
                        'updated_at' => current_time('mysql')
                    ]);
                return;
            }
        }

        $notificationContent = \sprintf(
        /* translators: %1$s is the user name, %2$s is the feed title */
            __('%1$s loved %2$s', 'fluent-community'),
            '<b class="fcom_nudn">' . $user->display_name . '</b>',
            '<span class="fcom_nft">' . $feedTitle . '</span>'
        );

        $route = $feed->getJsRoute();

        $notification = [
            'feed_id'         => $feed->id,
            'src_user_id'     => $user->ID,
            'src_object_type' => 'feed',
            'action'          => 'feed/react_added',
            'content'         => $notificationContent,
            'route'           => $route,
        ];

        $notification = Notification::create($notification);

        $notification->subscribe([$feed->user_id]);
    }

    public function handleSpaceMemberRoleUpdated($space, $pivot)
    {
        $user = $pivot->user;

        $notificationContent = \sprintf(
        /* translators: %1$s is the role name, %2$s is the space title */
            __('You have been added as %1$s in %2$s', 'fluent-community'),
            '<b>' . $pivot->role . '</b>',
            '<b>' . $space->title . '</b>'
        );

        $route = [
            'name'   => 'space_feeds',
            'params' => [
                'space' => $space->slug
            ]
        ];

        $notification = [
            'object_id'       => $space->id,
            'src_user_id'     => $user->ID,
            'src_object_type' => 'space',
            'action'          => 'space/member/role_updated',
            'content'         => $notificationContent,
            'route'           => $route,
        ];
        $notification = Notification::create($notification);
        $notification->subscribe([$pivot->user_id]);
    }

    protected function commentNotificationToAuthorFeed(Comment $comment, Feed $feed, $mentionUsers = null)
    {
        if ($comment->user_id == $feed->user_id) {
            return;
        }

        if ($mentionUsers) {
            $mentionedUserIds = $mentionUsers->pluck('ID')->toArray();
            if (in_array($feed->user_id, $mentionedUserIds)) {
                return;
            }
        }

        $commenter = '<b class="fcom_nudn">' . $comment->user->display_name . '</b>';
        $feedTitle = $feed->getHumanExcerpt(60);

        $exist = null;
        if ($feed->comments_count > 1) {
            // check if we have existing notification for this feed and user
            $exist = Notification::where('feed_id', $feed->id)
                ->where('action', 'comment_added')
                ->first();

            $totalUsers = $feed->comments->pluck('user_id')->unique()->count();

            if ($totalUsers > 1) {
                $commenter .= ' and ' . ($totalUsers - 1) . ' other people';
            }

            if ($feed->space_id) {
                $notificationContent = \sprintf(
                /* translators: %1$s is the user name, %2$s is the people count, %3$s is the feed title  and %4$s space title*/
                    __('%1$s and %2$s other people commented on your post %3$s in %4$s', 'fluent-community'),
                    '<b class="fcom_nudn">' . $comment->user->display_name . '</b>',
                    '<b class="fcom_nrc">' . ($totalUsers - 1) . '</b>',
                    '<span class="fcom_nft">' . $feedTitle . '</span>',
                    '<b class="fcom_nst">' . $feed->space->title . '</b>'
                );
            } else {
                $notificationContent = \sprintf(
                /* translators: %1$s is the user name, %2$s is the like count & %3$s is the feed title */
                    __('%1$s and %2$s other people commented on your post %3$s', 'fluent-community'),
                    '<b class="fcom_nudn">' . $comment->user->display_name . '</b>',
                    '<b class="fcom_nrc">' . ($totalUsers - 1) . '</b>',
                    '<span class="fcom_nft">' . $feedTitle . '</span>'
                );
            }
        } else {
            if ($feed->space_id) {
                $notificationContent = \sprintf(
                /* translators: %1$s is the commenter name, %2$s is the feed title & %3$s is the space title */
                    __('%1$s commented on your post: %2$s in %3$s', 'fluent-community'),
                    '<b class="fcom_nudn">' . $comment->user->display_name . '</b>',
                    '<span class="fcom_nft">' . $feedTitle . '</span>',
                    '<b class="fcom_nst">' . $feed->space->title . '</b>'
                );
            } else {
                $notificationContent = \sprintf(
                /* translators: %1$s is the commenter name & %2$s is the feed title */
                    __('%1$s commented on your post: %2$s', 'fluent-community'),
                    '<b class="fcom_nudn">' . $comment->user->display_name . '</b>',
                    '<span class="fcom_nft">' . $feedTitle . '</span>'
                );
            }
        }

        $route = $feed->getJsRoute();

        if ($exist) {
            $exist->content = $notificationContent;
            $exist->updated_at = current_time('mysql');
            $exist->src_user_id = $comment->user->ID;
            $exist->object_id = $comment->id;
            $exist->save();

            NotificationSubscriber::where('object_id', $exist->id)
                ->where('user_id', $feed->user_id)
                ->update([
                    'is_read'    => 0,
                    'updated_at' => current_time('mysql')
                ]);
            return;
        }

        $notification = [
            'feed_id'         => $feed->id,
            'object_id'       => $comment->id,
            'src_user_id'     => $comment->user_id,
            'src_object_type' => 'comment',
            'action'          => 'comment_added',
            'content'         => $notificationContent,
            'route'           => $route,
        ];

        $notification = Notification::create($notification);

        $notification->subscribe([$feed->user_id]);
    }

    protected function commentNotificationToFeedCommenters($comment, $feed, $mentionedUsers = null)
    {
        if ($comment->parent_id) {
            return $this->notifyForChildCommentReply($comment, $feed);
        }

        $comments = Comment::whereNotIn('user_id', [$feed->user_id, $comment->user_id])
            ->select(['user_id'])
            ->where('post_id', $feed->id)
            ->whereNull('parent_id')
            ->distinct('user_id')
            ->get();

        $userIds = $comments->pluck('user_id')->toArray();

        $mentionedUserIds = [];

        if ($mentionedUsers) {
            $mentionedUserIds = $mentionedUsers->pluck('ID')->toArray();
        }

        if (!$userIds && !$mentionedUserIds) {
            return;
        }

        $feedTitle = $feed->getHumanExcerpt(60);

        $route = $feed->getJsRoute();

        if ($feed->space_id) {
            $space = $feed->space;
            $notificationContent = \sprintf(
            /* translators: %1$s is the commenter name, %2$s is the feed title & %3$s is the space title */
                __('%1$s also commented on %2$s in %3$s', 'fluent-community'),
                '<b class="fcom_nudn">' . $comment->user->display_name . '</b>',
                '<span class="fcom_nft">' . $feedTitle . '</span>',
                '<b class="fcom_nst">' . $space->title . '</b>'
            );
        } else {
            $notificationContent = \sprintf(
            /* translators: %1$s is the commenter name & %2$s is the feed title */
                __('%1$s also commented on %2$s', 'fluent-community'),
                '<b class="fcom_nudn">' . $comment->user->display_name . '</b>',
                '<span class="fcom_nft">' . $feedTitle . '</span>'
            );
        }

        if ($mentionedUserIds) {
            $mentionNotification = Notification::create([
                'feed_id'         => $feed->id,
                'object_id'       => $comment->id,
                'src_user_id'     => $comment->user_id,
                'src_object_type' => 'comment',
                'action'          => 'mention_added',
                'content'         => \sprintf(
                // translators: %1$s is the commenter name & %2$s is the feed title
                    __('%1$s mentioned you in a comment at %2$s', 'fluent-community'),
                    '<b class="fcom_nudn">' . $comment->user->display_name . '</b>',
                    '<b class="fcom_nft">' . $feedTitle . '</b>'
                ),
                'route'           => $route,
            ]);

            $mentionNotification->subscribe($mentionedUserIds);
        }

        if ($mentionedUserIds) {
            $userIds = array_values(array_diff($userIds, $mentionedUserIds));
        }

        if (!$userIds) {
            return;
        }

        $notification = [
            'feed_id'         => $feed->id,
            'object_id'       => $comment->id,
            'src_user_id'     => $comment->user_id,
            'src_object_type' => 'comment',
            'action'          => 'comment_added',
            'content'         => $notificationContent,
            'route'           => $route,
        ];
        $notification = Notification::create($notification);
        $notification->subscribe($userIds);
    }

    protected function notifyForChildCommentReply($comment, $feed)
    {
        // This is a parent comment, so we need to notify the parent comment author & all child comment authors
        $childCommentUserIds = Comment::where('parent_id', $comment->parent_id)
            ->whereNotIn('user_id', [$comment->user_id, $feed->user_id])
            ->select(['user_id'])
            ->distinct('user_id')
            ->get()
            ->pluck('user_id')
            ->toArray();

        if (!$childCommentUserIds) {
            return false;
        }

        $existingNotification = Notification::where('object_id', $comment->parent_id)
            ->where('action', 'child_comment_added')
            ->first();

        if ($existingNotification) {
            $newContent = \sprintf(
            /* translators: %1$s is the commenter name, %2$s is the comment user count & %3$s feed excerpt */
                __('%1$s and %2$s other people replied to your comment at %3$s', 'fluent-community'),
                '<b class="fcom_nudn">' . $comment->user->display_name . '</b>',
                '<b class="fcom_nrc">' . count($childCommentUserIds) . '</b>',
                '<b class="fcom_nft">' . $feed->getHumanExcerpt(60) . '</b>'
            );

            $route = $existingNotification->route;
            $route['query'] = [
                'comment_id' => $comment->id
            ];

            $existingNotification->content = $newContent;
            $existingNotification->updated_at = current_time('mysql');
            $existingNotification->src_user_id = $comment->user_id;
            $existingNotification->route = $route;
            $existingNotification->save();

            NotificationSubscriber::where('object_id', $existingNotification->id)
                ->whereIn('user_id', $childCommentUserIds)
                ->update([
                    'is_read'    => 0,
                    'updated_at' => current_time('mysql')
                ]);

            return $existingNotification;
        }

        $notificationContent = \sprintf(
        /* translators: %1$s is the commenter name & %2$s is the feed excerpt */
            __('%1$s replied your comment at %2$s', 'fluent-community'),
            '<b class="fcom_nudn">' . $comment->user->display_name . '</b>',
            '<b class="fcom_nft">' . $feed->getHumanExcerpt(60) . '</b>'
        );

        $route = $feed->getJsRoute();

        $notification = [
            'feed_id'         => $feed->id,
            'object_id'       => $comment->parent_id,
            'src_user_id'     => $comment->user_id,
            'src_object_type' => 'comment',
            'action'          => 'child_comment_added',
            'content'         => $notificationContent,
            'route'           => $route,
        ];

        $notification = Notification::create($notification);
        $notification->subscribe($childCommentUserIds);
        return $notification;
    }

    public function handleFeedMentions($feed, $mentionedUsers)
    {
        $feedTitle = $feed->getHumanExcerpt(60);
        $user = $feed->user;

        $notificationContent = \sprintf(
        /* translators: %1$s is the user name, %2$s is the feed title */
            __('%1$s mentioned you in a post: %2$s', 'fluent-community'),
            '<b class="fcom_nudn">' . sanitize_text_field($user->display_name) . '</b>',
            '<b class="fcom_nft">' . $feedTitle . '</b>'
        );

        $route = $feed->getJsRoute();

        $notification = [
            'feed_id'         => $feed->id,
            'src_user_id'     => $user->ID,
            'src_object_type' => 'feed',
            'action'          => 'feed/mentioned',
            'content'         => $notificationContent,
            'route'           => $route,
        ];

        $notification = Notification::create($notification);

        $userIds = $mentionedUsers->pluck('ID')->toArray();

        $notification->subscribe($userIds);
    }

    private function maybeHasEveryoneTag(Feed $feed)
    {
        if (!$feed->space_id) {
            return;
        }

        if (Arr::get($feed->meta, 'send_announcement_email') !== 'yes') {
            return;
        }

        // we have everyone
        // check if current user is a moderator or admin
        $user = $feed->user;
        $spaceRole = $user->getSpaceRole($feed->space);
        if (!in_array($spaceRole, ['admin', 'moderator'])) {
            return;
        }

        if (Utility::hasEmailAnnouncementEnabled()) {
            do_action('fluent_community/feed/scheduling_everyone_tag', $feed);
            // Let's schedule an email hook to send email to everyone for this post
            // We are scheduling this after 5 minutes of the post publish for performance
            as_schedule_single_action(time() + 300, 'fluent_community/email_notify_users_everyone_tag', [
                $feed->id,
                0
            ], 'fluent-community');
        }

    }

    private function willCreateFeedCreatedNotification($feed)
    {
        // validate if the user is a moderator
        if (!$feed->user) {
            return;
        }

        if ($feed->user->isCommunityModerator()) {
            return true;
        }

        if ($feed->space) {
            $role = $feed->user->getSpaceRole($feed->space);
            return in_array($role, ['admin', 'moderator']);
        }

        return false;
    }
}
