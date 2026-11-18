<?php

namespace FluentCommunity\Modules\Mcp\Tools;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Models\Comment;
use FluentCommunity\App\Models\Feed;
use FluentCommunity\App\Models\Notification;
use FluentCommunity\App\Services\FeedsHelper;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Services\ProfileHelper;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\Modules\Mcp\Support\McpHelper;
use FluentCommunity\Modules\Mcp\Support\PostImages;
use FluentCommunity\Modules\Mcp\Support\Projector;
use FluentCommunity\Modules\Mcp\Support\RestBridge;

defined('ABSPATH') || exit;

class PostTools
{
    const COMMENTS_PER_PAGE = 20;

    const MAX_POSTS_PER_GET = 10;

    public static function listPosts($params)
    {
        $view = Arr::get($params, 'view', 'compact');
        $paging = $view === 'full' ? McpHelper::pagination($params, 10, 10) : McpHelper::pagination($params, 20, 50);

        $since = null;
        if (!empty($params['since'])) {
            $since = McpHelper::toSiteDate($params['since']);
            if (!$since) {
                return McpHelper::error('invalid_param', 'since is not a date. Use ISO 8601, for example 2026-11-16T09:00:00+06:00.', [ 'fields' => [ 'since' ] ]);
            }
        }

        $filter = Arr::get($params, 'filter');
        $orderBy = $filter === 'unanswered' ? 'unanswered' : Arr::get($params, 'order_by', $since ? 'new_activity' : 'latest');

        $query = array_filter([
            'page'           => $paging['page'],
            'per_page'       => $paging['per_page'],
            'disable_sticky' => 'yes',
            'order_by_type'  => $orderBy,
            'space'          => sanitize_text_field((string) Arr::get($params, 'space', '')),
            'user_id'        => (int) Arr::get($params, 'user_id', 0),
            'topic_slug'     => sanitize_text_field((string) Arr::get($params, 'topic', '')),
            'search'         => sanitize_text_field((string) Arr::get($params, 'search', '')),
        ]);

        // The feeds route reads an unknown topic slug as "any topic", which widens the result.
        if (!empty($query['topic_slug'])) {
            $topicSlugs = array_values(array_filter(array_map('strval', array_column((array) Utility::getTopics(), 'slug'))));

            if (!in_array($query['topic_slug'], $topicSlugs, true)) {
                return McpHelper::error('invalid_param', 'topic is not a topic slug on this site. Use a slug from get-community-context topics.', [
                    'fields'  => [ 'topic' ],
                    'allowed' => array_slice($topicSlugs, 0, 50),
                ]);
            }
        }

        if (!empty($query['search'])) {
            $query['search_in'] = [ 'post_content', 'post_comments' ];
        }

        $mentionedFeedIds = $filter === 'mentions_me' ? self::mentionedFeedIds(get_current_user_id()) : null;

        $constraint = function ($feedsQuery) use ($since, $mentionedFeedIds) {
            if ($since) {
                $feedsQuery->where('updated_at', '>', $since);
            }

            if ($mentionedFeedIds !== null) {
                $feedsQuery->whereIn('id', $mentionedFeedIds ? $mentionedFeedIds : [ 0 ]);
            }
        };

        add_action('fluent_community/feeds_query', $constraint);
        try {
            $result = RestBridge::call('GET', '/feeds', $query);
        } finally {
            remove_action('fluent_community/feeds_query', $constraint);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        $feeds = (array) Arr::get($result, 'feeds.data', []);
        $models = self::feedModels(array_column($feeds, 'id'));
        $user = Helper::getCurrentUser();

        $posts = [];
        foreach ($feeds as $feed) {
            $model = Arr::get($models, (int) $feed['id']);
            $row = Projector::post($feed, $model ? $model->message : '', $view);
            $row['last_activity'] = $model ? McpHelper::iso($model->updated_at) : null;

            if ($model && ($view === 'full' || $since)) {
                $row = array_merge($row, self::recentComments($model, $user, $since, $view === 'full' ? 3 : 0));
            }

            $posts[] = $row;
        }

        $count = count($posts);
        $summary = $count ? sprintf('%d posts on page %d.', $count, $paging['page']) : 'No posts match.';

        return McpHelper::envelope($summary, [ 'posts' => $posts ], [
            'paging'          => [
                'page'     => $paging['page'],
                'per_page' => $paging['per_page'],
                'has_more' => (bool) Arr::get($result, 'feeds.has_more', false),
            ],
            'content_warning' => McpHelper::CONTENT_WARNING,
        ]);
    }

    public static function getPost($params)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) Arr::get($params, 'ids', [])))));

        if (!empty($params['id'])) {
            array_unshift($ids, (int) $params['id']);
        }

        if (!empty($params['slug'])) {
            $feedId = self::readableFeedIdBySlug((string) $params['slug']);
            if (!$feedId) {
                return McpHelper::error('not_found', 'No post you can read has this slug.', [ 'fields' => [ 'slug' ] ]);
            }
            array_unshift($ids, $feedId);
        }

        $ids = array_values(array_unique($ids));

        if (!$ids) {
            return McpHelper::error('invalid_param', 'Pass id, slug or ids.', [ 'fields' => [ 'id' ] ]);
        }

        if (count($ids) > self::MAX_POSTS_PER_GET) {
            return McpHelper::error('cap_reached', sprintf('Pass at most %d ids per call.', self::MAX_POSTS_PER_GET), [ 'fields' => [ 'ids' ] ]);
        }

        $since = null;
        if (!empty($params['comments_since'])) {
            $since = McpHelper::toSiteDate($params['comments_since']);
            if (!$since) {
                return McpHelper::error('invalid_param', 'comments_since is not a date.', [ 'fields' => [ 'comments_since' ] ]);
            }
        }

        $single = count($ids) === 1;
        $page = max(1, (int) Arr::get($params, 'comments_page', 1));
        $perPage = $single ? self::COMMENTS_PER_PAGE : 10;

        $posts = [];
        $paging = [];

        foreach ($ids as $feedId) {
            $post = self::readPost($feedId, $since, $single ? $page : 1, $perPage);

            if (is_wp_error($post)) {
                if ($single) {
                    return $post;
                }
                $posts[] = [ 'id' => $feedId, 'error' => $post->get_error_code() ];
                continue;
            }

            $paging = $post['paging'];
            $posts[] = $post['post'];
        }

        $meta = [ 'content_warning' => McpHelper::CONTENT_WARNING ];

        if ($single) {
            $meta['paging'] = $paging;

            return McpHelper::envelope(
                sprintf('Post %d with %d of %d comments.', $ids[0], count($posts[0]['comment_list']), $paging['comments_total']),
                $posts[0],
                $meta
            );
        }

        return McpHelper::envelope(sprintf('%d posts.', count($posts)), [ 'posts' => $posts ], $meta);
    }

    /**
     * The slug goes through the feed route, so a post the user cannot read and a slug
     * that does not exist give the same answer.
     *
     * @param string $slug
     * @return int 0 when no readable post has the slug.
     */
    private static function readableFeedIdBySlug($slug)
    {
        $slug = sanitize_text_field($slug);

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
            return 0;
        }

        $result = RestBridge::call('GET', '/feeds/' . rawurlencode($slug) . '/by-slug');

        return is_wp_error($result) ? 0 : (int) Arr::get($result, 'feed.id');
    }

    public static function savePost($params)
    {
        $feedId = (int) Arr::get($params, 'id', 0);

        if ($feedId) {
            $existing = RestBridge::call('GET', '/feeds/' . $feedId . '/by-id', [ 'context' => 'edit' ]);
            if (is_wp_error($existing)) {
                return $existing;
            }

            $body = (array) Arr::get($existing, 'feed', []);
            if (!$body) {
                return McpHelper::error('forbidden', 'You cannot edit this post.');
            }

            foreach ([ 'message', 'title', 'topic_ids' ] as $field) {
                if (array_key_exists($field, $params)) {
                    $body[$field] = $params[$field];
                }
            }

            // Left out, images stay as they are. Given, the list of ids replaces them.
            if (array_key_exists('images', $params)) {
                $mediaImages = PostImages::resolve($params['images'], (array) Arr::get($body, 'media_images', []));
                if (is_wp_error($mediaImages)) {
                    return $mediaImages;
                }

                $body['media_images'] = $mediaImages;
            }

            $result = RestBridge::call('POST', '/feeds/' . $feedId, $body);
        } else {
            if (!isset($params['message']) || trim((string) $params['message']) === '') {
                return McpHelper::error('invalid_param', 'message is required for a new post.', [ 'fields' => [ 'message' ] ]);
            }

            $body = array_filter([
                'message'   => (string) $params['message'],
                'title'     => (string) Arr::get($params, 'title', ''),
                'space'     => sanitize_text_field((string) Arr::get($params, 'space', '')),
                'topic_ids' => array_values(array_filter(array_map('intval', (array) Arr::get($params, 'topic_ids', [])))),
            ]);

            if (!empty($params['send_announcement_email'])) {
                $body['send_announcement_email'] = 'yes';
            }

            if (!empty($params['images'])) {
                $mediaImages = PostImages::resolve($params['images']);
                if (is_wp_error($mediaImages)) {
                    return $mediaImages;
                }

                $body['media_images'] = $mediaImages;
            }

            $result = RestBridge::call('POST', '/feeds', $body);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        $feed = (array) Arr::get($result, 'feed', []);
        $status = Arr::get($feed, 'status');

        return McpHelper::envelope(
            $feedId ? sprintf('Post %d was updated.', $feedId) : sprintf('Post %d was created with status %s.', (int) Arr::get($feed, 'id'), $status),
            array_filter([
                'id'     => (int) Arr::get($feed, 'id'),
                'title'  => (string) Arr::get($feed, 'title', ''),
                'space'  => Arr::get($feed, 'space.slug'),
                'status' => $status,
                'url'    => Arr::get($feed, 'permalink'),
                'images' => PostImages::fromMeta(Arr::get($feed, 'meta', [])),
            ], function ($value, $key) {
                return $key !== 'images' || $value;
            }, ARRAY_FILTER_USE_BOTH)
        );
    }

    public static function uploadImage($params)
    {
        $image = PostImages::upload($params);
        if (is_wp_error($image)) {
            return $image;
        }

        return McpHelper::envelope(
            sprintf('Image %d was uploaded. Pass its id in images of save-post or save-comment within two hours, or it is removed.', $image['id']),
            $image
        );
    }

    public static function saveComment($params)
    {
        $commentId = (int) Arr::get($params, 'id', 0);
        $feedId = (int) Arr::get($params, 'post_id', 0);

        if ($commentId && !$feedId) {
            $feedId = (int) Comment::where('id', $commentId)->value('post_id');
        }

        if (!$feedId) {
            return McpHelper::error('invalid_param', 'post_id is required.', [ 'fields' => [ 'post_id' ] ]);
        }

        $body = [ 'comment' => (string) Arr::get($params, 'comment', '') ];

        // The update route keeps only the images a request sends, so an edit sends the current ones.
        $existingComment = $commentId ? Comment::where('id', $commentId)->where('post_id', $feedId)->first() : null;
        $currentMeta = $existingComment ? (array) $existingComment->meta : [];
        $current = (array) Arr::get($currentMeta, 'media_items', []);

        if (array_key_exists('images', $params)) {
            $mediaImages = PostImages::resolve($params['images'], $current);
            if (is_wp_error($mediaImages)) {
                return $mediaImages;
            }

            $body['media_images'] = $mediaImages;
        } elseif ($current) {
            $body['media_images'] = $current;
        } elseif (Arr::get($currentMeta, 'media_preview.image')) {
            $body['meta'] = [ 'media_preview' => $currentMeta['media_preview'] ];
        }

        if ($commentId) {
            $result = RestBridge::call('POST', '/feeds/' . $feedId . '/comments/' . $commentId, $body);
        } else {
            if (!empty($params['reply_to'])) {
                $body['parent_id'] = (int) $params['reply_to'];
            }
            $result = RestBridge::call('POST', '/feeds/' . $feedId . '/comments', $body);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        $comment = (array) Arr::get($result, 'comment', []);
        $status = Arr::get($comment, 'status', 'published');
        $newId = (int) Arr::get($comment, 'id', $commentId);

        return McpHelper::envelope(
            $commentId ? sprintf('Comment %d was updated.', $commentId) : sprintf('Comment %d was added with status %s.', $newId, $status),
            array_filter([
                'id'      => $newId,
                'post_id' => $feedId,
                'status'  => $status,
                'url'     => self::postUrl($feedId),
                'images'  => PostImages::fromMeta(Arr::get($comment, 'meta', [])),
            ], function ($value, $key) {
                return $key !== 'images' || $value;
            }, ARRAY_FILTER_USE_BOTH)
        );
    }

    public static function likeContent($params)
    {
        $remove = !empty($params['remove']);
        $commentId = (int) Arr::get($params, 'comment_id', 0);
        $feedId = (int) Arr::get($params, 'post_id', 0);

        if ($commentId) {
            $feedId = (int) Comment::where('id', $commentId)->value('post_id');
            if (!$feedId) {
                return McpHelper::error('not_found', 'No comment has this id.', [ 'fields' => [ 'comment_id' ] ]);
            }

            $result = RestBridge::call('POST', '/feeds/' . $feedId . '/comments/' . $commentId . '/reactions', [ 'state' => !$remove ]);
        } elseif ($feedId) {
            $body = [ 'react_type' => 'like' ];
            if ($remove) {
                $body['remove'] = true;
            }

            $result = RestBridge::call('POST', '/feeds/' . $feedId . '/react', $body);
        } else {
            return McpHelper::error('invalid_param', 'Pass post_id or comment_id.', [ 'fields' => [ 'post_id' ] ]);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        $target = $commentId ? 'comment ' . $commentId : 'post ' . $feedId;

        return McpHelper::envelope(
            $remove ? 'The like was removed from ' . $target . '.' : 'You like ' . $target . '.',
            array_filter([
                'post_id'    => $feedId,
                'comment_id' => $commentId,
                'liked'      => !$remove,
            ], function ($value) {
                return $value !== 0;
            })
        );
    }

    /**
     * @return array|\WP_Error ['post' => row, 'paging' => comment paging]
     */
    private static function readPost($feedId, $since, $page, $perPage)
    {
        $result = RestBridge::call('GET', '/feeds/' . $feedId . '/by-id');
        if (is_wp_error($result)) {
            return $result;
        }

        $feed = (array) Arr::get($result, 'feed', []);
        $models = self::feedModels([ $feedId ]);
        $post = Projector::post($feed, isset($models[$feedId]) ? $models[$feedId]->message : '', 'complete');

        $commentResult = RestBridge::call('GET', '/feeds/' . $feedId . '/comments');
        $comments = is_wp_error($commentResult) ? [] : (array) Arr::get($commentResult, 'comments', []);

        if ($since) {
            $comments = array_values(array_filter($comments, function ($comment) use ($since) {
                return (string) Arr::get($comment, 'created_at') > $since;
            }));
        }

        // Page 1 holds the newest comments; every page reads oldest to newest.
        $total = count($comments);
        $end = max(0, $total - ($page - 1) * $perPage);
        $start = max(0, $end - $perPage);

        $post['comment_list'] = array_map(function ($comment) {
            return Projector::comment($comment);
        }, array_slice($comments, $start, $end - $start));

        return [
            'post'   => $post,
            'paging' => [
                'comments_total' => $total,
                'comments_page'  => $page,
                'comments_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * @param int[] $feedIds
     * @return Feed[] Keyed by id. Access is decided by the REST call that produced the ids.
     */
    private static function feedModels($feedIds)
    {
        $feedIds = array_filter(array_map('intval', (array) $feedIds));
        if (!$feedIds) {
            return [];
        }

        $models = [];
        foreach (Feed::whereIn('id', $feedIds)->get() as $model) {
            $models[(int) $model->id] = $model;
        }

        return $models;
    }

    /**
     * The same visibility rules as the comment list route.
     *
     * @return array new_comments (when $since is set) and comment_list (when $limit > 0).
     */
    private static function recentComments(Feed $feed, $user, $since, $limit)
    {
        if (!apply_filters('fluent_community/can_view_comments_' . $feed->type, FeedsHelper::commentsEnabled($feed), $feed)) {
            return [];
        }

        $baseQuery = function () use ($feed, $user) {
            return Comment::where('post_id', $feed->id)
                ->byContentModerationAccessStatus($user)
                ->whereHas('xprofile', function ($profileQuery) {
                    $profileQuery->where('status', 'active');
                });
        };

        $data = [];

        if ($since) {
            $data['new_comments'] = $baseQuery()->where('created_at', '>', $since)->count();
        }

        if ($limit) {
            $comments = $baseQuery()
                ->with([
                    'xprofile' => function ($profileQuery) {
                        $profileQuery->select(ProfileHelper::getXProfilePublicFields());
                    },
                ])
                ->orderBy('created_at', 'DESC')
                ->orderBy('id', 'DESC')
                ->limit($limit)
                ->get()
                ->reverse()
                ->values();

            $data['comment_list'] = [];
            foreach ($comments as $comment) {
                $data['comment_list'][] = Projector::comment($comment->toArray(), $since);
            }
        }

        return $data;
    }

    private static function mentionedFeedIds($userId)
    {
        return Notification::query()->byType('mentioned')
            ->whereHas('subscribers', function ($subscriberQuery) use ($userId) {
                $subscriberQuery->where('user_id', $userId);
            })
            ->whereNotNull('feed_id')
            ->orderBy('id', 'DESC')
            ->limit(200)
            ->pluck('feed_id')
            ->unique()
            ->values()
            ->toArray();
    }

    private static function postUrl($feedId)
    {
        $feed = Feed::withoutGlobalScopes()->where('id', $feedId)->first();

        return $feed instanceof Feed ? $feed->getPermalink() : null;
    }
}
