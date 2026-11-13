<?php

namespace FluentCommunity\App\Services;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Models\NotificationPreference;
use FluentCommunity\App\Models\Space;
use FluentCommunity\App\Models\SpaceUserPivot;

/**
 * One member's email settings for one space, as the space menu shows them.
 *
 * - new_posts: all (every new post), admin (posts by space admins) or none.
 *   Same np_by_member / np_by_admin rows as the profile form.
 * - announcements: only offered to a member who turned announcement emails
 *   off. Ticking it keeps this space (AnnouncementPref::keepSpace()). A member
 *   with them on cannot opt out per space: the goal is to keep members on
 *   announcements.
 */
class SpaceEmailPref
{
    const ALL = 'all';

    const ADMIN = 'admin';

    const NONE = 'none';

    const NEW_POST_CHOICES = [ self::ALL, self::ADMIN, self::NONE ];

    /**
     * @param int   $userId
     * @param Space $space
     * @return array
     */
    public static function getState($userId, $space)
    {
        $spaceId = (int)$space->id;
        $prefs = NotificationPref::getUserPrefs($userId);

        $newPosts = self::NONE;
        if (!empty($prefs['np_by_member_mail_' . $spaceId])) {
            $newPosts = self::ALL;
        } elseif (!empty($prefs['np_by_admin_mail_' . $spaceId])) {
            $newPosts = self::ADMIN;
        }

        $siteSendsAnnouncements = Utility::hasEmailAnnouncementEnabled();

        return [
            'new_posts'          => $newPosts,
            // The checkbox, shown only to a member who turned announcements off.
            'show_announcements' => $siteSendsAnnouncements && !AnnouncementPref::isEnabled($userId),
            'announcements'      => $siteSendsAnnouncements && AnnouncementPref::receivesFromSpace($userId, $spaceId),
        ];
    }

    /**
     * @param int    $userId
     * @param Space  $space
     * @param string $newPosts one of NEW_POST_CHOICES
     */
    public static function setNewPosts($userId, $space, $newPosts)
    {
        $userId = (int)$userId;
        $spaceId = (int)$space->id;

        NotificationPreference::where('user_id', $userId)
            ->where('channel', 'mail')
            ->whereIn('event_key', [ 'np_by_member', 'np_by_admin' ])
            ->where('object_id', $spaceId)
            ->delete();

        $events = [];
        if ($newPosts === self::ALL) {
            $events = [ 'np_by_member', 'np_by_admin' ];
        } elseif ($newPosts === self::ADMIN) {
            $events = [ 'np_by_admin' ];
        }

        foreach ($events as $eventKey) {
            NotificationPreference::create([
                'user_id'   => $userId,
                'channel'   => 'mail',
                'event_key' => $eventKey,
                'object_id' => $spaceId,
                'value'     => 1,
            ]);
        }

        NotificationPref::forgetUserCache($userId);
    }

    /**
     * Keep or drop this space for a member who turned announcements off.
     * Does nothing for a member who has them on.
     *
     * @param int   $userId
     * @param Space $space
     * @param bool  $keep
     */
    public static function setAnnouncements($userId, $space, $keep)
    {
        if (!Utility::hasEmailAnnouncementEnabled() || AnnouncementPref::isEnabled($userId)) {
            return;
        }

        if ($keep) {
            AnnouncementPref::keepSpace($userId, $space->id);
        } else {
            AnnouncementPref::removeSpaces($userId, $space->id);
        }
    }

    /**
     * @param int   $userId
     * @param Space $space
     * @return bool
     */
    public static function isActiveMember($userId, $space)
    {
        return SpaceUserPivot::where('space_id', (int)$space->id)
            ->where('user_id', (int)$userId)
            ->where('status', 'active')
            ->exists();
    }
}
