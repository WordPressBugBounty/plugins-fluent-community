<?php

namespace FluentCommunity\App\Services\Libs;

use FluentCommunity\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * Checks an image editor save() to WebP. WordPress's GD editor writes an empty file
 * when it saves a palette (8-bit) image as WebP, a GIF or many PNGs, and still reports
 * image/webp. An upload that trusts that report deletes its original and keeps nothing.
 */
class WebpSave
{
    /**
     * @param array|\WP_Error $result An image editor save() result.
     * @return bool A WebP file that exists and is not empty.
     */
    public static function succeeded($result)
    {
        if (is_wp_error($result) || Arr::get($result, 'mime-type') !== 'image/webp' || empty($result['path'])) {
            return false;
        }

        clearstatcache(true, $result['path']);

        return is_file($result['path']) && filesize($result['path']) > 0;
    }

    /**
     * Removes the empty or partial file a failed save left behind.
     *
     * @param array|\WP_Error $result
     */
    public static function discard($result)
    {
        if (!is_wp_error($result) && !empty($result['path']) && is_file($result['path'])) {
            wp_delete_file($result['path']);
        }
    }
}
