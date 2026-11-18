<?php

namespace FluentCommunity\Modules\Mcp\Tools;

use FluentCommunity\App\Models\Comment;
use FluentCommunity\App\Models\Feed;
use FluentCommunity\App\Models\Space;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\Modules\Mcp\Support\McpHelper;
use FluentCommunity\Modules\Mcp\Support\RestBridge;

defined('ABSPATH') || exit;

class ModerationTools
{
    public static function moderateContent($params)
    {
        $action = Arr::get($params, 'action');
        $feedId = (int) Arr::get($params, 'post_id', 0);
        $commentId = (int) Arr::get($params, 'comment_id', 0);

        if ($commentId) {
            if ($action !== 'pin' && $action !== 'unpin') {
                return McpHelper::error('invalid_param', 'A comment can only be pinned or unpinned.', [ 'fields' => [ 'action' ] ]);
            }

            $feedId = (int) Comment::where('id', $commentId)->value('post_id');
            if (!$feedId) {
                return McpHelper::error('not_found', 'No comment has this id.', [ 'fields' => [ 'comment_id' ] ]);
            }

            $result = RestBridge::call('PATCH', '/feeds/' . $feedId . '/comments/' . $commentId, [ 'is_sticky' => $action === 'pin' ? 1 : 0 ]);

            if (is_wp_error($result)) {
                return $result;
            }

            return McpHelper::envelope(sprintf('Comment %d: %s done.', $commentId, $action), [ 'comment_id' => $commentId, 'post_id' => $feedId ]);
        }

        if (!$feedId) {
            return McpHelper::error('invalid_param', 'Pass post_id or comment_id.', [ 'fields' => [ 'post_id' ] ]);
        }

        if ($action === 'move') {
            return self::movePost($feedId, sanitize_text_field((string) Arr::get($params, 'to_space', '')));
        }

        $changes = [
            'pin'              => [ 'is_sticky' => 1 ],
            'unpin'            => [ 'is_sticky' => 0 ],
            'disable_comments' => [ 'comments_disabled' => 1 ],
            'enable_comments'  => [ 'comments_disabled' => 0 ],
        ];

        // The route lets an author change comments_disabled only, so moderator rights are checked first.
        $feed = Feed::find($feedId);
        $user = Helper::getCurrentUser();
        if (!$feed || !$user->hasPermissionOrInCurrentSpace('community_moderator', $feed->space)) {
            return McpHelper::error('forbidden', 'You are not a moderator of this post\'s space, or the post does not exist.');
        }

        $result = RestBridge::call('PATCH', '/feeds/' . $feedId, $changes[$action]);

        if (is_wp_error($result)) {
            return $result;
        }

        return McpHelper::envelope(sprintf('Post %d: %s done.', $feedId, $action), [
            'post_id'           => $feedId,
            'pinned'            => (bool) Arr::get($result, 'feed.is_sticky'),
            'comments_disabled' => Arr::get($result, 'feed.meta.comments_disabled') === 'yes',
        ]);
    }

    public static function deleteContent($params)
    {
        $dryRun = !empty($params['dry_run']);
        $commentId = (int) Arr::get($params, 'comment_id', 0);
        $feedId = (int) Arr::get($params, 'post_id', 0);
        $user = Helper::getCurrentUser();

        if ($commentId) {
            $comment = Comment::find($commentId);
            $feed = $comment ? Feed::withoutGlobalScopes()->find($comment->post_id) : null;

            if (!$comment || !$feed) {
                return McpHelper::error('not_found', 'No comment has this id.', [ 'fields' => [ 'comment_id' ] ]);
            }

            if ($dryRun) {
                $allowed = (int) $comment->user_id === (int) $user->ID || $user->can('delete_any_comment', $feed->space);

                // Only someone who may delete the comment sees what it says.
                if (!$allowed) {
                    return McpHelper::envelope('Dry run: you cannot delete this comment.', [ 'dry_run' => true, 'allowed' => false, 'comment_id' => $commentId ]);
                }

                return McpHelper::envelope('Dry run: this comment would be deleted.', [
                    'dry_run'    => true,
                    'allowed'    => true,
                    'comment_id' => $commentId,
                    'post_id'    => (int) $feed->id,
                    'author_id'  => (int) $comment->user_id,
                    'excerpt'    => McpHelper::excerpt($comment->message),
                ]);
            }

            $result = RestBridge::call('DELETE', '/feeds/' . $feed->id . '/comments/' . $commentId);

            if (is_wp_error($result)) {
                return $result;
            }

            return McpHelper::envelope(sprintf('Comment %d was deleted.', $commentId), [ 'deleted' => true, 'comment_id' => $commentId ]);
        }

        if (!$feedId) {
            return McpHelper::error('invalid_param', 'Pass post_id or comment_id.', [ 'fields' => [ 'post_id' ] ]);
        }

        $feed = Feed::find($feedId);
        if (!$feed) {
            return McpHelper::error('not_found', 'No post has this id.', [ 'fields' => [ 'post_id' ] ]);
        }

        if ($dryRun) {
            // Only someone who may delete the post sees what it says.
            if (!$user->canDeleteFeed($feed)) {
                return McpHelper::envelope('Dry run: you cannot delete this post.', [ 'dry_run' => true, 'allowed' => false, 'post_id' => $feedId ]);
            }

            return McpHelper::envelope('Dry run: this post and its comments would be deleted.', [
                'dry_run'   => true,
                'allowed'   => true,
                'post_id'   => $feedId,
                'title'     => (string) $feed->title,
                'author_id' => (int) $feed->user_id,
                'comments'  => (int) $feed->comments_count,
                'excerpt'   => McpHelper::excerpt($feed->message),
            ]);
        }

        $result = RestBridge::call('DELETE', '/feeds/' . $feedId);

        if (is_wp_error($result)) {
            return $result;
        }

        return McpHelper::envelope(sprintf('Post %d was deleted.', $feedId), [ 'deleted' => true, 'post_id' => $feedId ]);
    }






    private static function movePost($feedId, $toSpace)
    {
        $space = $toSpace ? Space::where('slug', $toSpace)->first() : null;
        if (!$space) {
            return McpHelper::error('invalid_param', 'move needs to_space, the slug of the target space.', [ 'fields' => [ 'to_space' ] ]);
        }

        $existing = RestBridge::call('GET', '/feeds/' . $feedId . '/by-id', [ 'context' => 'edit' ]);
        if (is_wp_error($existing)) {
            return $existing;
        }

        $body = (array) Arr::get($existing, 'feed', []);
        if (!$body) {
            return McpHelper::error('forbidden', 'You cannot move this post.');
        }

        $body['new_space_id'] = (int) $space->id;

        $result = RestBridge::call('POST', '/feeds/' . $feedId, $body);
        if (is_wp_error($result)) {
            return $result;
        }

        return McpHelper::envelope(sprintf('Post %d was moved to %s.', $feedId, $toSpace), [
            'post_id' => $feedId,
            'space'   => Arr::get($result, 'feed.space.slug'),
            'url'     => Arr::get($result, 'feed.permalink'),
        ]);
    }

}
