<?php

namespace FluentCommunity\App\Services;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Models\NotificationPreference;
use FluentCommunity\App\Models\Space;

/**
 * Member preferences for announcement emails: posts a space admin sends to
 * every member of the space ("send announcement email" on the post).
 *
 * Everyone gets them by default. A member who turns them off can keep a few
 * spaces ("only from these spaces"), from the profile form or a space menu.
 * There is no per-space mute: the goal is to keep members on announcements.
 *
 * Stored in fcom_notification_prefs:
 * - Site-wide switch: channel = mail, event_key = announcement, object_id = 0.
 *   No row (or value 1) means on, value 0 means off. Managed by the profile
 *   form like every other NOTIFICATION_EVENTS row.
 * - Kept space: channel = mail, event_key = announcement_space, object_id = space
 *   id, value = 1. Only read while the switch is off. Written by
 *   setAllowedSpaces(), keepSpace() and removeSpaces();
 *   NotificationPref::updateUserPrefs() never reconciles these rows.
 * - A member who gets admin posts from a space by email (np_by_admin) always
 *   gets its announcements: an announcement is an admin post, and the
 *   new-post fan-out skips it.
 */
class AnnouncementPref
{
    const EVENT = 'announcement';

    const SPACE_EVENT = 'announcement_space';

    /**
     * Portal action (Helper::getActionKey(), legacy `fcom_action`) of the page the email links to.
     */
    const PAGE_ACTION = 'announcement_email';

    public static function isEnabled($userId)
    {
        return NotificationPref::willGetNotification($userId, self::EVENT, 'mail', Utility::hasEmailAnnouncementEnabled());
    }

    public static function setEnabled($userId, $enabled)
    {
        $userId = (int)$userId;

        if (!$userId) {
            return;
        }

        $value = $enabled ? 1 : 0;

        $row = NotificationPreference::where('user_id', $userId)
            ->where('channel', 'mail')
            ->where('event_key', self::EVENT)
            ->where('object_id', 0)
            ->first();

        if ($row) {
            if ((int)$row->value !== $value) {
                $row->value = $value;
                $row->save();
            }
        } else {
            NotificationPreference::create([
                'user_id'   => $userId,
                'channel'   => 'mail',
                'event_key' => self::EVENT,
                'object_id' => 0,
                'value'     => $value,
            ]);
        }

        NotificationPref::forgetUserCache($userId);
    }

    /**
     * The spaces a member keeps while announcement emails are off.
     *
     * @param int $userId
     * @return int[]
     */
    public static function getAllowedSpaceIds($userId)
    {
        $ids = NotificationPreference::where('user_id', (int)$userId)
            ->where('channel', 'mail')
            ->where('event_key', self::SPACE_EVENT)
            ->where('value', 1)
            ->pluck('object_id')
            ->toArray();

        $ids = array_map('intval', $ids);
        sort($ids);

        return $ids;
    }

    /**
     * Replace the kept spaces with exactly these. Ids of spaces the member is
     * not an active member of are dropped.
     *
     * @param int   $userId
     * @param int[] $spaceIds
     * @return int[] the ids stored
     */
    public static function setAllowedSpaces($userId, $spaceIds)
    {
        $userId = (int)$userId;

        if (!$userId) {
            return [];
        }

        $spaceIds = array_values(array_intersect(
            array_unique(array_filter(array_map('intval', (array)$spaceIds))),
            self::getMemberSpaceIds($userId)
        ));

        NotificationPreference::where('user_id', $userId)
            ->where('channel', 'mail')
            ->where('event_key', self::SPACE_EVENT)
            ->when($spaceIds, function ($q) use ($spaceIds) {
                $q->whereNotIn('object_id', $spaceIds);
            })
            ->delete();

        $existing = self::getAllowedSpaceIds($userId);

        foreach (array_diff($spaceIds, $existing) as $spaceId) {
            self::createKeptRow($userId, $spaceId);
        }

        NotificationPref::forgetUserCache($userId);

        sort($spaceIds);

        return $spaceIds;
    }

    /**
     * Keep one space while announcement emails are off. The caller checks
     * that the member is an active member of the space.
     *
     * @param int $userId
     * @param int $spaceId
     */
    public static function keepSpace($userId, $spaceId)
    {
        $userId = (int)$userId;
        $spaceId = (int)$spaceId;

        if (!$userId || !$spaceId || in_array($spaceId, self::getAllowedSpaceIds($userId), true)) {
            return;
        }

        self::createKeptRow($userId, $spaceId);

        NotificationPref::forgetUserCache($userId);
    }

    /**
     * @param int $userId
     * @param int $spaceId
     * @return bool
     */
    public static function receivesFromSpace($userId, $spaceId)
    {
        $prefs = NotificationPref::getUserPrefs($userId);
        if (!empty($prefs['np_by_admin_mail_' . (int)$spaceId])) {
            return true;
        }

        return self::isEnabled($userId) || in_array((int)$spaceId, self::getAllowedSpaceIds($userId), true);
    }

    /**
     * Does the member get admin-post emails from any space? Those spaces send
     * their announcements whatever the switch says.
     *
     * @param int $userId
     * @return bool
     */
    public static function hasAdminPostEmails($userId)
    {
        return NotificationPreference::where('user_id', (int)$userId)
            ->where('channel', 'mail')
            ->where('event_key', 'np_by_admin')
            ->where('value', 1)
            ->exists();
    }

    private static function createKeptRow($userId, $spaceId)
    {
        NotificationPreference::create([
            'user_id'   => $userId,
            'channel'   => 'mail',
            'event_key' => self::SPACE_EVENT,
            'object_id' => $spaceId,
            'value'     => 1,
        ]);
    }

    /**
     * @param int       $userId
     * @param int|int[] $spaceIds
     */
    public static function removeSpaces($userId, $spaceIds)
    {
        $spaceIds = array_filter(array_map('intval', (array)$spaceIds));

        if (!$spaceIds) {
            return;
        }

        NotificationPreference::where('user_id', (int)$userId)
            ->where('channel', 'mail')
            ->where('event_key', self::SPACE_EVENT)
            ->whereIn('object_id', $spaceIds)
            ->delete();

        NotificationPref::forgetUserCache($userId);
    }

    /**
     * Community spaces the member is an active member of, ordered by title.
     *
     * @param int $userId
     * @return \FluentCommunity\Framework\Support\Collection
     */
    public static function getMemberSpaces($userId)
    {
        return Space::whereHas('members', function ($q) use ($userId) {
            $q->where('user_id', (int)$userId)
                ->where('status', 'active');
        })
            ->orderBy('title', 'ASC')
            ->get();
    }

    /**
     * @param int $userId
     * @return int[]
     */
    public static function getMemberSpaceIds($userId)
    {
        return array_map('intval', self::getMemberSpaces($userId)->pluck('id')->toArray());
    }

    /**
     * Limit a User query to members who get announcement emails from this space:
     * the switch is on, the member kept this space, or the member gets admin
     * posts from it by email.
     *
     * @param \FluentCommunity\Framework\Database\Orm\Builder $query
     * @param int                                             $spaceId
     * @return \FluentCommunity\Framework\Database\Orm\Builder
     */
    public static function scopeRecipients($query, $spaceId)
    {
        $spaceId = (int)$spaceId;

        return $query->where(function ($q) use ($spaceId) {
            $q->whereDoesntHave('notificationPreferences', function ($q) {
                $q->where('channel', 'mail')
                    ->where('event_key', self::EVENT)
                    ->where('object_id', 0)
                    ->where('value', 0);
            })->orWhereHas('notificationPreferences', function ($q) use ($spaceId) {
                $q->where('channel', 'mail')
                    ->whereIn('event_key', [ self::SPACE_EVENT, 'np_by_admin' ])
                    ->where('object_id', $spaceId)
                    ->where('value', 1);
            });
        });
    }

    /**
     * The token in the email link. It never expires and only lets its holder
     * change this member's announcement emails. It cannot log anyone in.
     */
    public static function getToken($userId, $spaceId)
    {
        return hash_hmac('sha256', 'fcom_announcement_email|' . (int)$userId . '|' . (int)$spaceId, wp_salt('auth'));
    }

    public static function verifyToken($userId, $spaceId, $token)
    {
        if (!(int)$userId || !(int)$spaceId || !is_string($token) || $token === '') {
            return false;
        }

        return hash_equals(self::getToken($userId, $spaceId), $token);
    }

    /**
     * The page the email footer links to.
     */
    public static function getManageUrl($userId, $spaceId)
    {
        return Helper::actionUrl(self::PAGE_ACTION, [
            'uid'   => (int)$userId,
            'space' => (int)$spaceId,
            'token' => self::getToken($userId, $spaceId),
        ]);
    }
}
