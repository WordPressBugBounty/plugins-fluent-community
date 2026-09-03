<?php

namespace FluentCommunity\App\Services;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Models\NotificationPreference;
use FluentCommunity\App\Models\Space;
use FluentCommunity\Framework\Support\Arr;

/**
 * Read/write layer over a member's notification preferences.
 *
 * The public surface here - flat keys like 'mention_push', getUserPrefs(),
 * willGetNotification(), primeUserPrefs(), filterPushUserIds() - is unchanged.
 * What changed is where the rows live.
 *
 * Preferences used to share fcom_notification_users with notification receipts,
 * separated only by an object_type column and a global scope declared in a
 * boot() closure. The two have opposite lifecycles: receipts are high-churn and
 * pruned at a month, preferences are a handful of permanent rows per member.
 * They now live in fcom_notification_prefs, where the channel is a real column
 * rather than a suffix on a key name, and where the reconcile below cannot reach
 * a notification receipt even if its scoping were removed.
 *
 * NOTIFICATION_EVENTS stays the source of truth for the flat key vocabulary;
 * prefKeyMap() derives the storage cells from it, so registering a channel there
 * is still the only place a new channel has to be declared.
 */
class NotificationPref
{
    const NOTIFICATION_EVENTS = [
        'comment' => ['mail' => 'com_my_post_mail', 'push' => 'com_my_post_push'],
        'reply'   => ['mail' => 'reply_my_com_mail', 'push' => 'reply_my_com_push'],
        'mention' => ['mail' => 'mention_mail', 'push' => 'mention_push'],
        'co_comment' => ['push' => 'co_com_push'],
        'digest'  => ['mail' => 'digest_mail']
    ];

    /**
     * The digest is the one event whose admin default is named differently from
     * the member's row key. Everything else falls back to NOTIFICATION_EVENTS.
     */
    const GLOBAL_KEY_OVERRIDES = [
        'digest.mail' => 'digest_email_status'
    ];

    /**
     * Keys that predate NOTIFICATION_EVENTS and are not part of the event x
     * channel grid: a frequency enum, and the two space-scoped subscriptions.
     *
     * flat key => [channel, event_key, is_object_scoped]
     */
    const EXTRA_PREF_KEYS = [
        'message_email_frequency' => ['mail', 'message_frequency', false],
        'np_by_member_mail'       => ['mail', 'np_by_member', true],
        'np_by_admin_mail'        => ['mail', 'np_by_admin', true],
    ];

    const AGGREGATE_OPTION = 'fluent_community_pref_aggregates';

    const CACHE_PREFIX = 'user_notification_pref_';

    public static function getGlobalPrefs($type = 'mail')
    {
        if ($type === 'push') {
            $pref = Utility::getPushNotificationSettings();
        } else {
            $type = 'mail';
            $pref = Utility::getEmailNotificationSettings();
        }

        $globalKeys = [];

        foreach (array_keys(self::NOTIFICATION_EVENTS) as $event) {
            $globalKey = self::getGlobalKeyFor($event, $type);

            if ($globalKey) {
                $globalKeys[] = $globalKey;
            }
        }

        return array_map(function ($value) {
            return $value === 'yes' ? 1 : 0;
        }, Arr::only($pref, $globalKeys));
    }

    /**
     * Every flat pref key mapped to the cell it is stored in.
     *
     * @return array flat key => [channel, event_key, is_object_scoped]
     */
    public static function prefKeyMap()
    {
        static $map;

        if ($map !== null) {
            return $map;
        }

        $map = [];

        foreach (self::NOTIFICATION_EVENTS as $event => $channels) {
            foreach ($channels as $channel => $flatKey) {
                $map[$flatKey] = [$channel, $event, false];
            }
        }

        return $map = array_merge($map, self::EXTRA_PREF_KEYS);
    }

    /**
     * The inverse: a stored cell mapped back to the flat key callers use.
     *
     * @return array "channel/event_key" => flat key
     */
    private static function cellKeyMap()
    {
        static $map;

        if ($map !== null) {
            return $map;
        }

        $map = [];

        foreach (self::prefKeyMap() as $flatKey => $cell) {
            $map[$cell[0] . '/' . $cell[1]] = $flatKey;
        }

        return $map;
    }

    /**
     * A member's explicit overrides, keyed the way every caller expects:
     * '<flat key>' globally, '<flat key>_<object id>' for space-scoped rows.
     *
     * @param int $userId
     * @return array
     */
    public static function getUserPrefs($userId)
    {
        $cacheKey = self::CACHE_PREFIX . $userId;

        $cached = Utility::getFromCache($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        $prefs = Arr::get(self::loadUserPrefs([$userId]), $userId, []);

        // setCache rather than getFromCache's callback: a member with no overrides
        // at all is the common case, and getFromCache only stores truthy values, so
        // those users would miss the cache on every recipient of every fan-out.
        Utility::setCache($cacheKey, $prefs, 86400 * 30);

        return $prefs;
    }

    /**
     * Load the flat pref arrays for many members in one query.
     *
     * @param array $userIds
     * @return array user id => [flat key => value]
     */
    private static function loadUserPrefs($userIds)
    {
        $grouped = array_fill_keys($userIds, []);

        if (!$userIds) {
            return $grouped;
        }

        $rows = NotificationPreference::whereIn('user_id', $userIds)
            ->select(['user_id', 'channel', 'event_key', 'object_id', 'value'])
            ->get();

        $cellKeys = self::cellKeyMap();

        foreach ($rows as $row) {
            $flatKey = Arr::get($cellKeys, $row->channel . '/' . $row->event_key);

            if (!$flatKey) {
                continue;
            }

            if ($row->object_id) {
                $flatKey .= '_' . $row->object_id;
            }

            $grouped[$row->user_id][$flatKey] = (int)$row->value;
        }

        return $grouped;
    }

    public static function filterValidPrefs($prefs)
    {
        $validPrefs = [];

        foreach ((array)$prefs as $key => $value) {
            if (in_array($key, self::validPrefKeys())) {
                $validPrefs[$key] = $value ? 1 : 0;
            } else if (strpos($key, 'np_by_') === 0) {
                // This is the notification by object. We are processing per key when updating
                $validPrefs[$key] = $value ? 1 : 0;
            } else if ($key == 'message_email_frequency') {
                $validPrefs[$key] = $value;
            }
        }

        return $validPrefs;
    }

    /**
     * Resolve a flat pref key to the cell it belongs in.
     *
     * @param string $key
     * @return array|null [channel, event_key, object_id]
     */
    private static function resolveCell($key)
    {
        $map = self::prefKeyMap();

        if (isset($map[$key])) {
            list($channel, $eventKey, $isScoped) = $map[$key];

            // A scoped subscription without an object id addresses nothing.
            return $isScoped ? null : [$channel, $eventKey, 0];
        }

        if (!preg_match('/^(.+)_(\d+)$/', $key, $matches)) {
            return null;
        }

        $baseKey = $matches[1];
        $objectId = (int)$matches[2];

        if (!isset($map[$baseKey])) {
            return null;
        }

        list($channel, $eventKey, $isScoped) = $map[$baseKey];

        if (!$isScoped || !$objectId || !Space::where('id', $objectId)->exists()) {
            return null;
        }

        return [$channel, $eventKey, $objectId];
    }

    /**
     * Replace a member's overrides.
     *
     * Only the channels present in $prefs are reconciled. Saving the email form
     * therefore cannot delete a member's push preferences - on the old shared
     * table this delete removed every row the payload did not mention, and its
     * safety against also deleting notification receipts rested entirely on a
     * global scope declared in a boot() closure.
     *
     * @param int   $userId
     * @param array $prefs
     * @return array
     */
    public static function updateUserPrefs($userId, $prefs = [])
    {
        $userId = (int)$userId;

        if (!$userId) {
            return [];
        }

        $cells = [];
        $channels = [];

        foreach (self::filterValidPrefs($prefs) as $key => $value) {
            $cell = self::resolveCell($key);

            if (!$cell) {
                continue;
            }

            list($channel, $eventKey, $objectId) = $cell;

            $cells[$channel . '/' . $eventKey . '/' . $objectId] = [
                'channel'   => $channel,
                'event_key' => $eventKey,
                'object_id' => $objectId,
                'value'     => (int)$value,
            ];

            $channels[$channel] = true;
        }

        $channels = array_keys($channels);

        if (!$channels) {
            return self::getUserPrefs($userId);
        }

        // One read of the member's current rows, then a diff. The previous
        // implementation ran a SELECT per pref key.
        $existing = [];
        $existingRows = NotificationPreference::where('user_id', $userId)
            ->whereIn('channel', $channels)
            ->get();

        foreach ($existingRows as $row) {
            $existing[$row->channel . '/' . $row->event_key . '/' . $row->object_id] = $row;
        }

        $keptIds = [];

        foreach ($cells as $cellKey => $cell) {
            if (isset($existing[$cellKey])) {
                /** @var NotificationPreference $row */
                $row = $existing[$cellKey];

                if ((int)$row->value !== $cell['value']) {
                    $row->value = $cell['value'];
                    $row->save();
                }

                $keptIds[] = $row->id;
                continue;
            }

            $created = NotificationPreference::create(array_merge($cell, ['user_id' => $userId]));

            $keptIds[] = $created->id;
        }

        $staleQuery = NotificationPreference::where('user_id', $userId)
            ->whereIn('channel', $channels);

        if ($keptIds) {
            $staleQuery->whereNotIn('id', $keptIds);
        }

        $staleQuery->delete();

        self::forgetUserCache($userId);
        self::refreshAggregates();

        return self::getUserPrefs($userId);
    }

    public static function updateUserSinglePref($userId, $prefKey, $prefValue, $objectId = null)
    {
        $prefs = self::getUserPrefs($userId);
        $prefs[$prefKey] = $prefValue ? 1 : 0;

        if ($objectId) {
            $prefs[$prefKey . '_' . $objectId] = $prefValue;
        }

        return self::updateUserPrefs($userId, $prefs);
    }

    public static function isPrefEnabled($userId, $prefKey, $globalStatus = false)
    {
        $prefs = self::getUserPrefs($userId);

        if (!isset($prefs[$prefKey])) {
            return $globalStatus;
        }

        return (bool)$prefs[$prefKey];
    }

    public static function validPrefKeys()
    {
        static $keys;

        if ($keys !== null) {
            return $keys;
        }

        $keys = [];

        foreach (self::NOTIFICATION_EVENTS as $channels) {
            foreach ($channels as $key) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public static function getGlobalKeyFor($event, $type)
    {
        $path = $event . '.' . $type;

        return Arr::get(self::GLOBAL_KEY_OVERRIDES, $path) ?: Arr::get(self::NOTIFICATION_EVENTS, $path);
    }

    public static function willGetNotification($userId, $event, $type = 'mail', $globalStatus = null)
    {
        $prefKey = Arr::get(self::NOTIFICATION_EVENTS, $event . '.' . $type);

        // This event has no such channel, e.g. digest.push (event = digest, type = push)
        if (!$prefKey) return false;

        if ($globalStatus === null) {
            $globalKey = self::getGlobalKeyFor($event, $type);
            $globalStatus = (bool)Arr::get(self::getGlobalPrefs($type), $globalKey, false);
        }

        return self::isPrefEnabled($userId, $prefKey, $globalStatus);
    }

    public static function primeUserPrefs($userIds)
    {
        $missing = [];

        foreach ($userIds as $userId) {
            if (Utility::getFromCache(self::CACHE_PREFIX . $userId) === false) {
                $missing[] = $userId;
            }
        }

        if (!$missing) return;

        foreach (self::loadUserPrefs($missing) as $userId => $userPrefs) {
            Utility::setCache(self::CACHE_PREFIX . $userId, $userPrefs, 86400 * 30);
        }
    }

    public static function filterPushUserIds($userIds, $event)
    {
        if (!$userIds || !$event) return [];

        $prefKey = Arr::get(self::NOTIFICATION_EVENTS, $event . '.push');

        if (!$prefKey) return [];

        $userIds = array_filter(array_map('intval', (array)$userIds), function ($userId) {
            return $userId > 0;
        });

        $userIds = array_values(array_unique($userIds));

        if (!$userIds) return [];

        $globalKey = self::getGlobalKeyFor($event, 'push');
        $globalStatus = (bool)Arr::get(self::getGlobalPrefs('push'), $globalKey, false);

        self::primeUserPrefs($userIds);

        $enabled = [];

        foreach ($userIds as $userId) {
            if (self::isPrefEnabled($userId, $prefKey, $globalStatus)) {
                $enabled[] = $userId;
            }
        }

        return $enabled;
    }

    public static function forgetUserCache($userId)
    {
        Utility::forgetCache(self::CACHE_PREFIX . $userId);
    }

    /**
     * (channel, event) pairs that need a "does anybody have this on?" answer
     * across all members. Answered from a denormalized option refreshed on the
     * preference write path, so the hourly digest check never scans.
     *
     * @return array list of [channel, event_key]
     */
    public static function getAggregatedPrefs()
    {
        return apply_filters('fluent_community/aggregated_notification_prefs', [
            ['mail', 'digest'],
        ]);
    }

    /**
     * @param string $eventKey
     * @param string $channel
     * @return bool
     */
    public static function hasAnyEnabled($eventKey, $channel = 'mail')
    {
        $aggregates = get_option(self::AGGREGATE_OPTION);

        if (!is_array($aggregates)) {
            $aggregates = self::refreshAggregates();
        }

        return !empty($aggregates[$channel . '.' . $eventKey]);
    }

    /**
     * @return array
     */
    public static function refreshAggregates()
    {
        $aggregates = [];

        foreach (self::getAggregatedPrefs() as $pair) {
            list($channel, $eventKey) = $pair;

            $aggregates[$channel . '.' . $eventKey] = NotificationPreference::query()
                ->where('channel', $channel)
                ->where('event_key', $eventKey)
                ->where('value', 1)
                ->exists();
        }

        update_option(self::AGGREGATE_OPTION, $aggregates, false);

        return $aggregates;
    }
}
