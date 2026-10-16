<?php

namespace FluentCommunity\App\Services\Insights;

/**
 * Card payload cache. The object cache when the site has a persistent one,
 * otherwise a transient, because without a persistent object cache
 * wp_cache_* lasts a single request and every view would recompute.
 *
 * An empty result is a valid result and is cached too. Only exceptions skip
 * the cache, so an error is never stored.
 */
class CardCache
{
    const GROUP = 'fluent_community';

    /**
     * @param string $key
     * @param int $ttl seconds
     * @param callable $callback
     * @param bool $fresh skip the read, still write
     * @return array [value, bool was it a cache hit]
     */
    public static function remember($key, $ttl, callable $callback, $fresh = false)
    {
        $cacheKey = 'focm_insights_' . md5($key);
        $usesTransient = !wp_using_ext_object_cache();

        if (!$fresh) {
            $found = false;
            $value = wp_cache_get($cacheKey, self::GROUP, false, $found);

            if (!$found && $usesTransient) {
                $value = get_transient($cacheKey);
                $found = $value !== false;
            }

            if ($found && is_array($value)) {
                return [$value, true];
            }
        }

        $value = $callback();

        wp_cache_set($cacheKey, $value, self::GROUP, $ttl);
        if ($usesTransient) {
            set_transient($cacheKey, $value, $ttl);
        }

        return [$value, false];
    }
}
