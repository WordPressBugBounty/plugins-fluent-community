<?php

namespace FluentCommunity\App\Services;

use FluentCommunity\Framework\Support\Arr;

/**
 * Reconciles a space's primary menu.
 *
 * Runtime items are authoritative for what exists and where it routes; the stored list in
 * `settings.primary_menu` is authoritative for order, label, icon and enabled state. A stored
 * row with no runtime counterpart is dropped (page deleted, module deactivated, permission
 * revoked) and a runtime item absent from the stored list is appended, so a newly created page
 * appears without the admin having to re-save.
 *
 * Every tab, Posts included, can be switched off — the one invariant is that at least one row
 * stays enabled, because the space has to land a visitor somewhere.
 */
class SpaceMenuService
{
    const SETTINGS_KEY = 'primary_menu';

    const ICON_KEYS = [
        'emoji',
        'icon_image',
        'shape_svg',
    ];

    /**
     * The menu as one user sees it, ordered and access filtered.
     *
     * @param \FluentCommunity\App\Models\BaseSpace $space formatted space — `permissions` must be
     *                                                     populated, or module contributed items
     *                                                     (Documents, Media) will be missing
     * @param \FluentCommunity\App\Models\User|null $user
     * @return array
     */
    public static function getMenuLinks($space, $user = null)
    {
        $items = self::mergeItems(self::getRuntimeItems($space), self::getStoredItems($space));

        $links = [];

        foreach ($items as $item) {
            if (!Helper::isLinkAccessible($item, $user)) {
                continue;
            }

            $links[] = self::formatMenuLink($item);
        }

        return $links;
    }

    /**
     * The menu as the admin manages it — every row, disabled ones included.
     *
     * @param \FluentCommunity\App\Models\BaseSpace $space formatted space
     * @return array
     */
    public static function getManagerItems($space)
    {
        $items = self::mergeItems(self::getRuntimeItems($space), self::getStoredItems($space));

        return array_map(function ($item) {
            return [
                'slug'           => $item['slug'],
                'title'          => (string)Arr::get($item, 'title', ''),
                'emoji'          => (string)Arr::get($item, 'emoji', ''),
                'icon_image'     => (string)Arr::get($item, 'icon_image', ''),
                'shape_svg'      => (string)Arr::get($item, 'shape_svg', ''),
                'enabled'        => Arr::get($item, 'enabled') === 'no' ? 'no' : 'yes',
                'new_tab'        => Arr::get($item, 'new_tab') === 'yes' ? 'yes' : 'no',
                'privacy'        => (string)Arr::get($item, 'privacy', 'public'),
                'membership_ids' => array_values((array)Arr::get($item, 'membership_ids', [])),
                'permalink'      => (string)Arr::get($item, 'permalink', ''),
                'route'          => Arr::get($item, 'route'),
                'page_slug'      => (string)Arr::get($item, 'page_slug', ''),
                'link_type'      => (string)Arr::get($item, 'link_type', ''),
                'is_custom'      => Arr::get($item, 'is_custom') === 'yes' ? 'yes' : 'no',
                'is_system'      => Arr::get($item, 'is_system') === 'yes' ? 'yes' : 'no',
                'is_locked'      => Arr::get($item, 'is_locked') === 'yes' ? 'yes' : 'no',
                'source'         => (string)Arr::get($item, 'source', 'system'),
            ];
        }, $items);
    }

    /**
     * Everything that exists for this space right now: the two built-in tabs plus whatever the
     * modules contribute through `fluent_community/space_header_links`.
     *
     * @param \FluentCommunity\App\Models\BaseSpace $space
     * @return array
     */
    public static function getRuntimeItems($space)
    {
        $items = [
            [
                'slug'      => 'space_feeds',
                'title'     => __('Posts', 'fluent-community'),
                'route'     => [
                    'name' => 'space_feeds',
                ],
                'is_system' => 'yes',
            ],
        ];

        if (Arr::get($space->permissions, 'can_view_members')) {
            $items[] = [
                'slug'      => 'space_members',
                'title'     => __('Members', 'fluent-community'),
                'route'     => [
                    'name' => 'space_members',
                ],
                'is_system' => 'yes',
            ];
        }

        $items = apply_filters('fluent_community/space_header_links', $items, $space);

        return self::normalizeRuntimeItems($items);
    }

    /**
     * The stored row slug a space page's runtime item must carry. Pro calls this so the two sides
     * cannot drift.
     *
     * @param string $pageSlug
     * @return string
     */
    public static function getPageItemSlug($pageSlug)
    {
        return 'page_' . sanitize_title($pageSlug);
    }

    /**
     * Persists the stored list.
     *
     * `settings` is one serialized column, so a read-modify-write here races any other write
     * to the same row — callers must not run this concurrently with a `links` save.
     *
     * @param \FluentCommunity\App\Models\BaseSpace $space an unformatted space; formatSpaceData()
     *                                                     stamps virtual attributes that make
     *                                                     save() fail
     * @param array $menuItems already sanitized rows
     * @return void
     */
    public static function storeMenu($space, $menuItems)
    {
        $settings = $space->settings;
        $settings[self::SETTINGS_KEY] = $menuItems;
        $space->settings = $settings;
        $space->save();
    }

    /**
     * @param array $runtimeItems
     * @param array $storedItems
     * @return array
     */
    public static function mergeItems($runtimeItems, $storedItems)
    {
        $runtimeBySlug = [];

        foreach ($runtimeItems as $runtimeItem) {
            $runtimeBySlug[$runtimeItem['slug']] = $runtimeItem;
        }

        $suppressed = self::getSuppressedSlugs($storedItems);

        $merged = [];
        $consumed = [];

        foreach ($storedItems as $storedItem) {
            $slug = Arr::get($storedItem, 'slug');

            if (!$slug || isset($consumed[$slug])) {
                continue;
            }

            if (Arr::get($storedItem, 'is_custom') === 'yes') {
                $consumed[$slug] = true;
                $merged[] = self::normalizeCustomItem($storedItem);
                continue;
            }

            if (!isset($runtimeBySlug[$slug]) || isset($suppressed[$slug])) {
                continue;
            }

            $consumed[$slug] = true;
            $merged[] = self::applyOverrides($runtimeBySlug[$slug], $storedItem);
        }

        foreach ($runtimeItems as $runtimeItem) {
            $slug = $runtimeItem['slug'];

            if (isset($consumed[$slug]) || isset($suppressed[$slug])) {
                continue;
            }

            $merged[] = $runtimeItem;
        }

        return self::ensureOneEnabled($merged);
    }

    /**
     * A space whose every tab is switched off has nowhere to land a visitor, so the first row in
     * the admin's own order comes back on.
     *
     * This replaced a hard lock on the Posts tab. The constraint was never that Posts in
     * particular must exist, only that something must — a documents-only or pages-only space is a
     * reasonable thing to want, and `SpaceHomeRedirect` now follows whatever sits first.
     *
     * @param array $items
     * @return array
     */
    protected static function ensureOneEnabled($items)
    {
        if (!$items) {
            return $items;
        }

        foreach ($items as $item) {
            // A runtime item that was never stored carries no `enabled` key and counts as on
            if (Arr::get($item, 'enabled') !== 'no') {
                return $items;
            }
        }

        $items[0]['enabled'] = 'yes';

        return $items;
    }

    /**
     * @param \FluentCommunity\App\Models\BaseSpace $space
     * @return array
     */
    protected static function getStoredItems($space)
    {
        $stored = Arr::get($space->settings, self::SETTINGS_KEY, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * A custom row pointing at a space page replaces that page's own auto appended row, so the
     * deliberately placed one wins and the tab is not rendered twice.
     *
     * @param array $storedItems
     * @return array
     */
    protected static function getSuppressedSlugs($storedItems)
    {
        $suppressed = [];

        foreach ($storedItems as $storedItem) {
            if (Arr::get($storedItem, 'is_custom') !== 'yes') {
                continue;
            }

            if (Arr::get($storedItem, 'link_type') !== 'space_page') {
                continue;
            }

            $pageSlug = Arr::get($storedItem, 'page_slug');

            if ($pageSlug) {
                $suppressed[self::getPageItemSlug($pageSlug)] = true;
            }
        }

        return $suppressed;
    }

    /**
     * @param array $items
     * @return array
     */
    protected static function normalizeRuntimeItems($items)
    {
        $normalized = [];
        $seen = [];

        foreach ((array)$items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $slug = self::resolveRuntimeSlug($item);

            if (isset($seen[$slug])) {
                continue;
            }

            $seen[$slug] = true;

            $item['slug'] = $slug;
            $item['is_system'] = 'yes';
            $item['is_custom'] = 'no';

            if (empty($item['source'])) {
                $item['source'] = 'system';
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * @param array $item
     * @return string
     */
    protected static function resolveRuntimeSlug($item)
    {
        $slug = Arr::get($item, 'slug');

        if ($slug) {
            return sanitize_title($slug);
        }

        $routeName = Arr::get($item, 'route.name');

        if ($routeName) {
            $params = (array)Arr::get($item, 'route.params', []);

            // A space page keys the same way whether or not pro set the slug itself. Pro only
            // sets it when this class exists, so an older pro against this core arrives without
            // one — and deriving it any other way would orphan every stored page row the moment
            // pro is updated, silently throwing the admin's page order back to the end of the bar.
            if ($routeName === 'space_page' && !empty($params['page_slug'])) {
                return self::getPageItemSlug($params['page_slug']);
            }

            ksort($params);

            return sanitize_title($routeName . ($params ? '_' . implode('_', array_map('strval', $params)) : ''));
        }

        // Identity digest for third party filter items that carry no slug of their own. Not a
        // security hash — it only has to stay stable across requests so a saved order survives.
        $url = Arr::get($item, 'url', Arr::get($item, 'permalink', ''));

        if ($url) {
            return 'ext_' . substr(md5($url), 0, 12);
        }

        return 'item_' . substr(md5((string)Arr::get($item, 'title', '')), 0, 12);
    }

    /**
     * @param array $storedItem
     * @return array
     */
    protected static function normalizeCustomItem($storedItem)
    {
        $storedItem['is_custom'] = 'yes';
        $storedItem['is_system'] = 'no';
        $storedItem['is_locked'] = 'no';
        $storedItem['source'] = 'custom';
        $storedItem['enabled'] = Arr::get($storedItem, 'enabled') === 'no' ? 'no' : 'yes';

        return $storedItem;
    }

    /**
     * @param array $runtimeItem
     * @param array $storedItem
     * @return array
     */
    protected static function applyOverrides($runtimeItem, $storedItem)
    {
        $item = $runtimeItem;

        $title = Arr::get($storedItem, 'title');

        if ($title) {
            $item['title'] = $title;
        }

        foreach (self::ICON_KEYS as $iconKey) {
            $iconValue = Arr::get($storedItem, $iconKey);

            if ($iconValue) {
                $item[$iconKey] = $iconValue;
            }
        }

        $item['enabled'] = Arr::get($storedItem, 'enabled') === 'no' ? 'no' : 'yes';

        // Nothing in core locks a row any more. The flag stays honoured so a third party
        // contributing through `space_header_links` can still mark its tab undisableable.
        if (Arr::get($runtimeItem, 'is_locked') === 'yes') {
            $item['enabled'] = 'yes';
        }

        $privacy = Arr::get($storedItem, 'privacy');

        if ($privacy) {
            $item['privacy'] = $privacy;
            $item['membership_ids'] = (array)Arr::get($storedItem, 'membership_ids', []);
        }

        return $item;
    }

    /**
     * @param array $item
     * @return array
     */
    protected static function formatMenuLink($item)
    {
        $link = [
            'slug'  => $item['slug'],
            'title' => (string)Arr::get($item, 'title', ''),
        ];

        foreach (self::ICON_KEYS as $iconKey) {
            if (!empty($item[$iconKey])) {
                $link[$iconKey] = $item[$iconKey];
            }
        }

        if (!empty($item['route'])) {
            $link['route'] = $item['route'];

            return $link;
        }

        if (Arr::get($item, 'link_type') === 'space_page' && Arr::get($item, 'page_slug')) {
            $link['route'] = [
                'name'   => 'space_page',
                'params' => [
                    'page_slug' => Arr::get($item, 'page_slug'),
                ],
            ];

            return $link;
        }

        $link['url'] = (string)Arr::get($item, 'permalink', Arr::get($item, 'url', ''));
        $link['is_external'] = array_key_exists('is_external', $item)
            ? !empty($item['is_external'])
            : Arr::get($item, 'new_tab') === 'yes';

        $cssClass = Arr::get($item, 'css_class');

        if ($cssClass) {
            $link['css_class'] = $cssClass;
        }

        return $link;
    }
}
