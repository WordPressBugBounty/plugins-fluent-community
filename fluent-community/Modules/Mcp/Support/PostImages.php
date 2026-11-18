<?php

namespace FluentCommunity\Modules\Mcp\Support;

use FluentCommunity\App\Models\Media;
use FluentCommunity\App\Services\FeedsHelper;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Services\Libs\FileSystem;
use FluentCommunity\App\Services\UploadHelper;
use FluentCommunity\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * Images on posts and comments, the way the composer does it: upload first, then save
 * the post or comment with the ids. One shape for agents: {id, url, width, height}.
 * Posts store one image as meta.media_preview and several as meta.media_items, comments
 * as meta.media_items; the routes take media_images. This class converts between them.
 */
class PostImages
{
    const IMAGE_TYPES = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];

    const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    const DOWNLOAD_TIMEOUT = 15;

    const MAX_REDIRECTS = 3;

    /** The composer's own upload settings (FeedTextComposer.vue). */
    const UPLOAD_OPTIONS = [ 'resize' => true, 'max_width' => 1400, 'context' => '' ];

    /**
     * The images a post or comment shows.
     *
     * @param array $meta The meta of a REST feed or comment row.
     * @return array[] Each: id (media id), url, width, height.
     */
    public static function fromMeta($meta)
    {
        $meta = (array) $meta;
        $items = (array) Arr::get($meta, 'media_items', []);

        if (!$items) {
            $preview = (array) Arr::get($meta, 'media_preview', []);
            if (Arr::get($preview, 'provider') === 'uploader' && Arr::get($preview, 'media_id')) {
                $items = [
                    [
                        'media_id' => Arr::get($preview, 'media_id'),
                        'url'      => Arr::get($preview, 'image'),
                        'width'    => Arr::get($preview, 'width'),
                        'height'   => Arr::get($preview, 'height'),
                    ],
                ];
            }
        }

        $images = [];
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['url'])) {
                continue;
            }

            $images[] = array_filter([
                'id'     => (int) Arr::get($item, 'media_id'),
                'url'    => (string) $item['url'],
                'width'  => (int) Arr::get($item, 'width'),
                'height' => (int) Arr::get($item, 'height'),
            ]);
        }

        return $images;
    }

    public static function maxPerPost()
    {
        $limit = FeedsHelper::getMaxMediaPerPost();

        return $limit === null ? null : max(1, $limit);
    }

    /**
     * The upload-image tool: stores one image as a draft, as the composer's upload does.
     * It becomes part of a post or comment when its id is saved with it; a draft that is
     * never used is removed after about two hours (CleanupHandler::deleteOldDraftMedias).
     *
     * @param array $params url, or data with an optional filename.
     * @return array|\WP_Error {id, url, width, height}
     */
    public static function upload($params)
    {
        $given = array_keys(array_filter(Arr::only((array) $params, [ 'url', 'data' ]), function ($value) {
            return $value !== null && $value !== '';
        }));

        if (count($given) !== 1) {
            return McpHelper::error('invalid_param', 'Pass exactly one of url or data.', [ 'fields' => [ 'url', 'data' ] ]);
        }

        if ($given[0] === 'url' && !self::isFetchableUrl((string) $params['url'])) {
            return McpHelper::error('invalid_param', 'url must be an http or https address on a public host.', [ 'fields' => [ 'url' ] ]);
        }

        $stored = self::store((array) $params);

        if (is_wp_error($stored)) {
            $retryable = $stored->get_error_code() === 'rate_limited';

            return McpHelper::error($retryable ? 'rate_limited' : 'invalid_param', $stored->get_error_message(), [
                'fields'    => [ $given[0] ],
                'retryable' => $retryable,
            ]);
        }

        return [
            'id'     => (int) Media::where('media_key', $stored['media_key'])->value('id'),
            'url'    => (string) $stored['url'],
            'width'  => (int) $stored['width'],
            'height' => (int) $stored['height'],
        ];
    }

    /**
     * The media_images list a post or comment route takes, from image ids.
     *
     * @param mixed $ids      The images parameter: media ids.
     * @param array $existing The images the post or comment has now (route items with media_id).
     * @return array|\WP_Error
     */
    public static function resolve($ids, array $existing = [])
    {
        if (!is_array($ids)) {
            return McpHelper::error('invalid_param', 'images must be a list of image ids.', [ 'fields' => [ 'images' ] ]);
        }

        $ids = array_values($ids);
        $max = self::maxPerPost();

        if ($max !== null && count($ids) > $max) {
            return McpHelper::error('cap_reached', sprintf('At most %d images on this site.', $max), [ 'fields' => [ 'images' ] ]);
        }

        $existingById = [];
        foreach ($existing as $item) {
            if (is_array($item) && !empty($item['media_id'])) {
                $existingById[(int) $item['media_id']] = $item;
            }
        }

        $mediaImages = [];

        foreach ($ids as $index => $mediaId) {
            $mediaId = is_numeric($mediaId) ? (int) $mediaId : 0;

            if (isset($existingById[$mediaId])) {
                $mediaImages[] = $existingById[$mediaId];
                continue;
            }

            // A draft only its uploader can use: not yet part of any post or comment.
            $media = $mediaId ? Media::where('id', $mediaId)
                ->where('is_active', 0)
                ->where('user_id', get_current_user_id())
                ->first() : null;

            if (!$media) {
                return McpHelper::error('invalid_param', sprintf('images[%d]: %s is not an image you uploaded with upload-image, or an image this already has.', $index, wp_json_encode($ids[$index])), [ 'fields' => [ 'images' ] ]);
            }

            $mediaImages[] = [
                'url'      => add_query_arg([ 'media_key' => $media->media_key ], $media->public_url),
                'type'     => 'image',
                'provider' => 'uploader',
                'width'    => Arr::get($media->settings, 'width'),
                'height'   => Arr::get($media->settings, 'height'),
            ];
        }

        return $mediaImages;
    }

    /**
     * @param array $image {url} or {data, filename}.
     * @return array|\WP_Error The stored upload: url (with media_key), media_key, type, width, height.
     */
    private static function store(array $image)
    {
        try {
            do_action('fluent_community/check_rate_limit/media_upload', Helper::getCurrentUser());
        } catch (\Exception $e) {
            return new \WP_Error('rate_limited', $e->getMessage());
        }

        $maxBytes = self::maxBytes();
        $tmpFile = wp_tempnam('fcom-mcp-image');

        if (!$tmpFile) {
            return new \WP_Error('upload_error', 'The server could not create a temporary file.');
        }

        if (!empty($image['url'])) {
            $name = basename((string) wp_parse_url((string) $image['url'], PHP_URL_PATH));
            $written = self::download((string) $image['url'], $tmpFile, $maxBytes);
        } else {
            $name = (string) Arr::get($image, 'filename', '');
            $written = self::decode((string) $image['data'], $tmpFile, $maxBytes);
        }

        if (is_wp_error($written)) {
            wp_delete_file($tmpFile);

            return $written;
        }

        $mime = wp_get_image_mime($tmpFile);
        $allowed = array_intersect(self::IMAGE_TYPES, (array) apply_filters('fluent_community/support_attachment_types', self::IMAGE_TYPES));

        if (!$mime || !in_array($mime, $allowed, true)) {
            wp_delete_file($tmpFile);

            return new \WP_Error('invalid_type', 'The file is not a JPEG, PNG, GIF or WebP image.');
        }

        // An image with PHP code in it (a polyglot) is refused. The WebP conversion would drop the
        // extra bytes, but an image that is kept as it is (a palette PNG on GD, a GIF) keeps them.
        if (stripos((string) file_get_contents($tmpFile), '<?php') !== false) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local temp file
            wp_delete_file($tmpFile);

            return new \WP_Error('invalid_type', 'The image contains PHP code.');
        }

        // The extension follows the bytes, never the given name, so a .php name cannot survive.
        $rawBase = strtolower(trim(pathinfo($name, PATHINFO_FILENAME)));
        // A bare type word ("png" from /image/png) makes WordPress name the file unnamed-file.
        $base = in_array($rawBase, [ '', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'image' ], true) ? 'image' : sanitize_file_name($rawBase);
        if ($base === '' || strpos($base, 'unnamed-file') === 0) {
            $base = 'image';
        }
        $fileName = 'mcp-' . $base . '.' . self::EXTENSIONS[$mime];

        $stored = (new FileSystem())->sideload([
            'name'     => $fileName,
            'type'     => $mime,
            'tmp_name' => $tmpFile,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmpFile),
        ]);

        if (is_wp_error($stored)) {
            wp_delete_file($tmpFile);

            return $stored;
        }

        return UploadHelper::finishImageUpload($stored, self::UPLOAD_OPTIONS);
    }

    private static function download($url, $tmpFile, $maxBytes)
    {
        // Redirects are followed here, not by WordPress, so every hop gets the same host check
        // as the first URL. Every failure gives one message, so it tells nothing about the hop.
        $failed = new \WP_Error('download_failed', 'The image could not be downloaded from url.');

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if ($hop && !self::isFetchableUrl($url)) {
                return $failed;
            }

            $response = wp_safe_remote_get($url, [
                'timeout'             => self::DOWNLOAD_TIMEOUT,
                'redirection'         => 0,
                'stream'              => true,
                'filename'            => $tmpFile,
                'limit_response_size' => $maxBytes + 1,
            ]);

            if (is_wp_error($response)) {
                return $failed;
            }

            $status = (int) wp_remote_retrieve_response_code($response);

            if (in_array($status, [ 301, 302, 303, 307, 308 ], true)) {
                $location = (string) wp_remote_retrieve_header($response, 'location');
                if ($location === '') {
                    return $failed;
                }

                $url = \WP_Http::make_absolute_url($location, $url);
                continue;
            }

            if ($status !== 200) {
                return $failed;
            }

            clearstatcache(true, $tmpFile);

            return self::checkSize($tmpFile, $maxBytes);
        }

        return $failed;
    }

    private static function decode($data, $tmpFile, $maxBytes)
    {
        // A data URI prefix (data:image/png;base64,) is allowed and ignored.
        $data = preg_replace('/^data:[^,]*,/', '', trim($data));
        $bytes = base64_decode(preg_replace('/\s+/', '', $data), true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- image bytes an agent sent

        if ($bytes === false || $bytes === '') {
            return new \WP_Error('invalid_data', 'data is not valid base64.');
        }

        if (strlen($bytes) > $maxBytes) {
            return self::tooLarge($maxBytes);
        }

        file_put_contents($tmpFile, $bytes); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

        return true;
    }

    private static function checkSize($tmpFile, $maxBytes)
    {
        $size = (int) filesize($tmpFile);

        if (!$size) {
            return new \WP_Error('download_failed', 'The image url returned no data.');
        }

        return $size > $maxBytes ? self::tooLarge($maxBytes) : true;
    }

    private static function tooLarge($maxBytes)
    {
        return new \WP_Error('too_large', sprintf('The image is larger than %s.', size_format($maxBytes)));
    }

    /** The composer's limit (media_upload_max_file_size, 100 MB by default). */
    private static function maxBytes()
    {
        $unit = strtoupper((string) apply_filters('fluent_community/media_upload_max_file_unit', 'MB'));
        $size = (float) apply_filters('fluent_community/media_upload_max_file_size', 100);

        if ($unit === 'GB') {
            return (int) ($size * GB_IN_BYTES);
        }

        return (int) ($unit === 'KB' ? $size * KB_IN_BYTES : $size * MB_IN_BYTES);
    }

    /**
     * The host must resolve to a public address, checked here before anything is fetched.
     * wp_safe_remote_get() checks again at request time and after each redirect.
     * WordPress's http_request_host_is_external filter can allow a host, as it does in core.
     */
    private static function isFetchableUrl($url)
    {
        $parts = wp_parse_url($url);

        if (!$parts || empty($parts['host']) || !in_array(strtolower((string) Arr::get($parts, 'scheme')), [ 'http', 'https' ], true)) {
            return false;
        }

        $host = trim($parts['host'], '[].');

        if (apply_filters('http_request_host_is_external', false, $host, $url)) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter
            return true;
        }

        $address = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        // 100.64.0.0/10 (carrier-grade NAT, some cloud metadata services): PHP's flags leave it out.
        $long = ip2long($address);

        return $long === false || ($long & 0xFFC00000) !== (ip2long('100.64.0.0') & 0xFFC00000);
    }
}
