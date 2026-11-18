<?php

namespace FluentCommunity\App\Services\Libs;

use FluentCommunity\App\Models\Media;

defined('ABSPATH') || exit;

/**
 * Finds media rows whose local file is missing or empty. Read-only.
 *
 * Until the WebpSave check, an image upload on a site with WordPress's GD editor could
 * end as an empty file: GD writes an empty WebP for a palette image (a GIF, many PNGs)
 * and the upload deleted the original. Such an image cannot be repaired; the member
 * has to upload it again. This lists them, with the post or comment that shows them.
 */
class EmptyMediaFinder
{
    const BATCH = 500;

    /**
     * @param bool $withDrafts Also check uploads that were never attached (the cleanup removes them anyway).
     * @return array[] Each: id, problem (missing|empty), media_type, object_source, feed_id, comment_id, user_id, created_at, url.
     */
    public static function find($withDrafts = false)
    {
        $found = [];
        $lastId = 0;

        do {
            $query = Media::where('driver', 'local')
                ->where('id', '>', $lastId)
                ->orderBy('id', 'ASC')
                ->limit(self::BATCH);

            if (!$withDrafts) {
                $query->where('is_active', 1);
            }

            $batch = $query->get();
            $batchSize = count($batch);

            foreach ($batch as $media) {
                $lastId = (int) $media->id;
                $problem = self::problem((string) $media->media_path);

                if ($problem) {
                    $found[] = [
                        'id'            => (int) $media->id,
                        'problem'       => $problem,
                        'media_type'    => (string) $media->media_type,
                        'object_source' => (string) $media->object_source,
                        'feed_id'       => (int) $media->feed_id,
                        'comment_id'    => $media->object_source === 'comment' ? (int) $media->sub_object_id : 0,
                        'user_id'       => (int) $media->user_id,
                        'created_at'    => (string) $media->created_at,
                        'url'           => (string) $media->media_url,
                    ];
                }
            }
        } while ($batchSize === self::BATCH);

        return $found;
    }

    /**
     * @param string $path
     * @return string|null missing, empty, or null for a file with content.
     */
    private static function problem($path)
    {
        if ($path === '' || !is_file($path)) {
            return 'missing';
        }

        clearstatcache(true, $path);

        return filesize($path) > 0 ? null : 'empty';
    }
}
