<?php

namespace FluentCommunity\App\Services;

/**
 * Fixed-window counters that hold under parallel requests.
 *
 * A MySQL named lock serializes the read-increment-write, so parallel requests
 * cannot all read the same count. Transients, not wp_cache_incr(): some Redis
 * ACLs deny INCRBY.
 */
class RateLimiter
{
    /**
     * Count one hit against $key.
     *
     * Lock timeout (contention) fails closed. No lock support at all (Galera,
     * SQLite) counts without the lock, so login and signup keep working there.
     *
     * @param string $key Transient key.
     * @param int $limit Allowed hits per window.
     * @param int $window Window length in seconds, from the first hit.
     * @return bool True when over the limit. A rejected hit is not counted.
     */
    public static function hit($key, $limit, $window)
    {
        global $wpdb;
        $lockName = 'fcom_rl_' . md5(constant('DB_NAME') . ':' . $wpdb->prefix . ':' . $key);

        $suppressed = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock; a cached result would defeat the lock
        $locked = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 2));
        $wpdb->suppress_errors($suppressed);

        if ($locked === null) {
            return self::count($key, $limit, $window);
        }

        if ((string) $locked !== '1') {
            return true;
        }

        try {
            return self::count($key, $limit, $window);
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory lock; a cached result would defeat the lock
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    private static function count($key, $limit, $window)
    {
        $now = time();
        $counter = get_transient($key);
        if (!is_array($counter) || (int) ($counter['expires'] ?? 0) <= $now) {
            $counter = [ 'count' => 0, 'expires' => $now + max(1, (int) $window) ];
        }

        if ($counter['count'] >= $limit) {
            return true;
        }

        ++$counter['count'];
        set_transient($key, $counter, max(1, $counter['expires'] - $now));

        return false;
    }
}
