<?php

namespace FluentCommunity\Modules\Mcp\Support;

use FluentCommunity\Framework\Support\Arr;

defined('ABSPATH') || exit;

/**
 * Turns REST payloads into the compact rows agents read. The portal payloads
 * carry avatars, rendered HTML and UI state an agent does not need.
 */
class Projector
{
    /**
     * @param array  $feed    A feed from the REST API.
     * @param string $message The post's Markdown.
     * @param string $view    compact|full|complete
     * @return array
     */
    public static function post(array $feed, $message, $view = 'compact')
    {
        $row = [
            'id'       => (int) Arr::get($feed, 'id'),
            'title'    => (string) Arr::get($feed, 'title', ''),
            'author'   => self::person((array) Arr::get($feed, 'xprofile', [])),
            'space'    => Arr::get($feed, 'space.slug'),
            'status'   => Arr::get($feed, 'status'),
            'comments' => (int) Arr::get($feed, 'comments_count', 0),
            'likes'    => (int) Arr::get($feed, 'reactions_count', 0),
            'created'  => McpHelper::iso(Arr::get($feed, 'created_at')),
            'url'      => Arr::get($feed, 'permalink'),
        ];

        if ((int) Arr::get($feed, 'is_sticky', 0)) {
            $row['pinned'] = true;
        }

        $topics = array_values(array_filter(array_map(function ($term) {
            return Arr::get($term, 'slug');
        }, (array) Arr::get($feed, 'terms', []))));

        if ($topics) {
            $row['topics'] = $topics;
        }

        if ($view === 'compact') {
            $row['excerpt'] = McpHelper::excerpt($message);

            return $row;
        }

        if ($view === 'complete') {
            $row['message'] = trim((string) $message);
        } else {
            list($row['message'], $wasCut) = McpHelper::cut($message, McpHelper::BODY_CHARS);
            if ($wasCut) {
                $row['truncated'] = true;
            }
        }

        $poll = self::poll((array) Arr::get($feed, 'meta', []));
        if ($poll) {
            $row['poll'] = $poll;
        }

        $images = PostImages::fromMeta(Arr::get($feed, 'meta', []));
        if ($images) {
            $row['images'] = $images;
        }

        return $row;
    }

    /**
     * @param array       $comment A comment from the REST API.
     * @param string|null $since   Site-local datetime; newer comments get new=true.
     * @return array
     */
    public static function comment(array $comment, $since = null)
    {
        list($message) = McpHelper::cut(Arr::get($comment, 'message', ''), McpHelper::COMMENT_CHARS);

        $row = [
            'id'      => (int) Arr::get($comment, 'id'),
            'author'  => self::person((array) Arr::get($comment, 'xprofile', [])),
            'message' => $message,
            'likes'   => (int) Arr::get($comment, 'reactions_count', 0),
            'created' => McpHelper::iso(Arr::get($comment, 'created_at')),
        ];

        if (Arr::get($comment, 'parent_id')) {
            $row['reply_to'] = (int) Arr::get($comment, 'parent_id');
        }

        if (Arr::get($comment, 'status') && Arr::get($comment, 'status') !== 'published') {
            $row['status'] = Arr::get($comment, 'status');
        }

        if ($since && (string) Arr::get($comment, 'created_at') > $since) {
            $row['new'] = true;
        }

        $images = PostImages::fromMeta(Arr::get($comment, 'meta', []));
        if ($images) {
            $row['images'] = $images;
        }

        return $row;
    }

    /**
     * @param array $profile An xprofile from the REST API.
     * @return array
     */
    public static function member(array $profile)
    {
        $row = [
            'id'            => (int) Arr::get($profile, 'user_id'),
            'name'          => (string) Arr::get($profile, 'display_name', ''),
            'username'      => (string) Arr::get($profile, 'username', ''),
            'status'        => Arr::get($profile, 'status') === '' ? 'deactivated' : Arr::get($profile, 'status'),
            'joined'        => McpHelper::iso(Arr::get($profile, 'created_at')),
            'last_activity' => McpHelper::iso(Arr::get($profile, 'last_activity')),
        ];

        if ((int) Arr::get($profile, 'is_verified', 0)) {
            $row['verified'] = true;
        }

        return $row;
    }

    /**
     * @param array $profile
     * @return array|null
     */
    public static function person(array $profile)
    {
        if (!$profile) {
            return null;
        }

        return [
            'id'       => (int) Arr::get($profile, 'user_id'),
            'name'     => (string) Arr::get($profile, 'display_name', ''),
            'username' => (string) Arr::get($profile, 'username', ''),
        ];
    }

    private static function poll(array $meta)
    {
        $options = Arr::get($meta, 'survey_config.options');

        if (!is_array($options) || !$options) {
            return null;
        }

        return array_map(function ($option) {
            return [
                'label' => (string) Arr::get($option, 'label', ''),
                'votes' => (int) Arr::get($option, 'vote_counts', 0),
            ];
        }, array_values($options));
    }
}
