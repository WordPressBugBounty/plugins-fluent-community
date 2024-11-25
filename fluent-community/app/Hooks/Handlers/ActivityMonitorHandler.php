<?php

namespace FluentCommunity\App\Hooks\Handlers;

use FluentCommunity\App\Models\Activity;
use FluentCommunity\App\Models\XProfile;
use FluentCommunity\App\Services\Helper;

class ActivityMonitorHandler
{
    public function register()
    {
        add_action('fluent_community/comment_added', [$this, 'handleNewCommentEvent'], 10, 2);
        add_action('fluent_community/feed/created', [$this, 'handleFeedCreated'], 10, 1);

        add_action('fluent_communit/track_activity', [$this, 'trackActivity'], 10);

        add_action('profile_update', function ($userId) {
            $user = get_user_by('ID', $userId);
            $xprofile = XProfile::where('user_id', $userId)->first();
            if (!$xprofile) {
                return;
            }
            $firstName = $user->first_name;
            $lastName = $user->last_name;
            $displayName = trim($firstName . ' ' . $lastName);
            if ($displayName && $xprofile->display_name != $displayName) {
                $xprofile->display_name = $displayName;
                $xprofile->save();
            }
        });

    }


    public function handleNewCommentEvent($comment, $feed)
    {
        $isPublic = 1;

        if ($feed->space_id) {
            $isPublic = $feed->space->privacy == 'public';
        }

        $data = [
            'user_id'     => $comment->user_id,
            'feed_id'     => $feed->id,
            'space_id'    => $feed->space_id,
            'related_id'  => $comment->id,
            'action_name' => 'comment_added',
            'is_public'   => $isPublic,
        ];

        Activity::create($data);

        do_action('fluent_communit/track_activity');
    }

    public function handleFeedCreated($feed)
    {
        $isPublic = 1;

        if ($feed->space_id) {
            $isPublic = $feed->space->privacy == 'public';
        }

        $data = [
            'user_id'     => $feed->user_id,
            'feed_id'     => $feed->id,
            'space_id'    => $feed->space_id,
            'action_name' => 'feed_published',
            'is_public'   => $isPublic,
        ];

        Activity::create($data);

        do_action('fluent_communit/track_activity');
    }

    public function trackActivity()
    {
        $currentProfile = Helper::getCurrentProfile();

        if (!$currentProfile) {
            return false;
        }

        $currentProfile->last_activity = current_time('mysql');
        $currentProfile->save();
    }
}
