<?php

namespace FluentCommunity\Modules\Migrations\Helpers;

use FluentCommunity\App\Models\Comment;
use FluentCommunity\App\Models\Feed;
use FluentCommunity\App\Models\Reaction;
use FluentCommunity\App\Models\User;
use FluentCommunity\App\Services\FeedsHelper;
use FluentCommunity\Framework\Support\Arr;

class BPMigratorHelper
{

    public static function getBbDataStats()
    {
        return [
            'total_posts'           => fluentCommunityApp('db')->table('bp_activity')->where('type', 'activity_update')->count(),
            'total_comments'        => fluentCommunityApp('db')->table('bp_activity')->where('type', 'activity_comment')->count(),
            'total_reactions'       => class_exists('\BB_Reaction') ? fluentCommunityApp('db')->table('bb_user_reactions')->whereIn('item_type', ['activity_comment', 'activity'])->count() : 0,
            'total_community_users' => User::count(),
        ];
    }

    public static function migratePost($post, $spaceId = null)
    {
        $content = self::cleanUpContent($post->content);

        if (!$content) {
            return null;
        }

        $feedData = [
            'user_id'          => $post->user_id,
            'title'            => '',
            'message'          => self::toMarkdown($content),
            'message_rendered' => $content,
            'type'             => 'text',
            'content_type'     => 'text',
            'privacy'          => 'public',
            'status'           => 'published',
            'space_id'         => $spaceId
        ];

        [$feedData, $media] = FeedsHelper::processFeedMetaData($feedData, []);

        $mediaMeta = self::getActivityMediaPreview($post->id);

        if ($mediaMeta) {
            $feedData['meta'] = $mediaMeta;
        }

        $feed = new Feed();
        $feed->fill($feedData);
        $feed->created_at = $post->date_recorded;
        $feed->updated_at = $post->date_recorded;
        $feed->save();

        self::syncPostReactions($post->id, $feed->id);

        // let's migrate the comments
        $commentIdMaps = self::syncComments($post->id, $feed);
        self::syncCommentsReactions($commentIdMaps, $feed->id);

        $feed = $feed->recountStats();

        return $feed;
    }

    public static function syncComments($activityId, Feed $feed)
    {
        // Let's manage the comments
        $comments = fluentCommunityApp('db')->table('bp_activity')
            ->where('type', 'activity_comment')
            ->where('item_id', $activityId)
            ->orderBy('id', 'ASC')
            ->get();

        $comments = self::buildCommentsTree($comments);
        $commentMaps = [];

        foreach ($comments as $comment) {
            $newComment = self::insertBBComment($comment, $feed);
            $commentMaps[$comment['id']] = $newComment->id;
            if ($comment['children']) {
                foreach ($comment['children'] as $child) {
                    $childComment = self::insertBBComment($child, $feed, $newComment->id);
                    $commentMaps[$child['id']] = $childComment->id;
                }
            }
        }

        return $commentMaps;
    }

    private static function insertBBComment($comment, $feed, $parentId = null)
    {
        $comemntData = [
            'user_id'          => $comment['user_id'],
            'post_id'          => $feed->id,
            'message'          => self::toMarkdown($comment['content']),
            'message_rendered' => $comment['content'],
            'type'             => 'comment'
        ];

        $media = self::getActivityMediaPreview($comment['id']);

        if ($media) {
            $comemntData['meta'] = $media;
        }

        if ($parentId) {
            $comemntData['parent_id'] = $parentId;
        }

        $newComment = new Comment();
        $newComment->fill($comemntData);
        $newComment->created_at = $comment['date_recorded'];
        $newComment->updated_at = $comment['date_recorded'];
        $newComment->save();

        return $newComment;
    }

    public static function buildCommentsTree($comments)
    {
        $commentTree = [];
        $commentMap = [];

        // First pass: create a map of all comments
        foreach ($comments as $comment) {
            $commentMap[$comment->id] = [
                'id'            => $comment->id,
                'content'       => self::cleanUpContent($comment->content),
                'user_id'       => $comment->user_id,
                'date_recorded' => $comment->date_recorded,
                'mptt_left'     => $comment->mptt_left,
                'mptt_right'    => $comment->mptt_right,
                'children'      => []
            ];
        }

        // Second pass: build the tree structure
        foreach ($comments as $comment) {
            $parentId = $comment->secondary_item_id;

            if ($parentId == $comment->item_id) {
                // This is a top-level comment
                $commentTree[] = &$commentMap[$comment->id];
            } else {
                // This is a reply to another comment
                $commentMap[$parentId]['children'][] = &$commentMap[$comment->id];
            }
        }

        // Sort the tree based on mptt_left values
        usort($commentTree, function ($a, $b) {
            return $a['mptt_left'] - $b['mptt_left'];
        });

        // Recursive function to sort children
        $sortChildren = function (&$node) use (&$sortChildren) {
            usort($node['children'], function ($a, $b) {
                return $a['mptt_left'] - $b['mptt_left'];
            });

            foreach ($node['children'] as &$child) {
                $sortChildren($child);
            }
        };

        // Sort children for each top-level comment
        foreach ($commentTree as &$topLevelComment) {
            $sortChildren($topLevelComment);
        }

        foreach ($commentTree as &$comment) {
            foreach ($comment['children'] as &$child) {
                $childComments = $child['children'];
                if ($childComments) {
                    $comment['children'] = array_merge($comment['children'], $childComments);
                    unset($child['children']);
                }
            }

            // short the children
            usort($comment['children'], function ($a, $b) {
                return $a['id'] - $b['id'];
            });
        }

        return $commentTree;
    }

    private static function cleanUpContent($content)
    {
        $pattern = '/<a class=\'bp-suggestions-mention\'[^>]*>(@[\w-]+)<\/a>/is';
        $replacement = '$1';
        $content = preg_replace($pattern, $replacement, $content);

        // replace \" or \' with " or '
        $content = str_replace(['\"', "\'"], ['"', "'"], $content);

        return trim($content);
    }

    private static function toMarkdown($html)
    {
        // Replace header tags
        $html = preg_replace('/<h1>(.*?)<\/h1>/', '# $1', $html);
        $html = preg_replace('/<h2>(.*?)<\/h2>/', '## $1', $html);
        $html = preg_replace('/<h3>(.*?)<\/h3>/', '### $1', $html);
        $html = preg_replace('/<h4>(.*?)<\/h4>/', '#### $1', $html);
        $html = preg_replace('/<h5>(.*?)<\/h5>/', '##### $1', $html);
        $html = preg_replace('/<h6>(.*?)<\/h6>/', '###### $1', $html);

        // Replace bold and italic tags
        $html = preg_replace('/<strong>(.*?)<\/strong>/', '**$1**', $html);
        $html = preg_replace('/<b>(.*?)<\/b>/', '**$1**', $html);
        $html = preg_replace('/<em>(.*?)<\/em>/', '*$1*', $html);
        $html = preg_replace('/<i>(.*?)<\/i>/', '*$1*', $html);

        // Replace unordered lists
        $html = preg_replace('/<ul>(.*?)<\/ul>/', "\n$0\n", $html);
        $html = preg_replace('/<li>(.*?)<\/li>/', "- $1\n", $html);

        // Replace ordered lists
        $html = preg_replace('/<ol>(.*?)<\/ol>/', "\n$0\n", $html);
        $html = preg_replace('/<li>(.*?)<\/li>/', "1. $1\n", $html);

        // Replace links
        $html = preg_replace('/<a href="(.*?)".*?>(.*?)<\/a>/', '[$2]($1)', $html);

        // Replace images
        $html = preg_replace('/<img src="(.*?)".*?\/?>/', '![](=$1)', $html);

        // Replace blockquotes
        $html = preg_replace('/<blockquote>(.*?)<\/blockquote>/', "> $1\n", $html);

        // Replace horizontal rules
        $html = preg_replace('/<hr\/>/', "---\n", $html);

        // Replace pre and code tags
        $html = preg_replace('/<pre><code>(.*?)<\/code><\/pre>/', "```\n\$1\n```", $html);
        $html = preg_replace('/<code>(.*?)<\/code>/', "`$1`", $html);

        return trim($html);
    }

    private static function getActivityMediaPreview($activityId)
    {
        return self::getMediaItems($activityId);
    }

    private static function getMediaItems($activityId)
    {
        $mediaPreviews = [];
        $mediaMetas = fluentCommunityApp('db')->table('bp_activity_meta')
            ->select(['meta_value', 'meta_key'])
            ->where('activity_id', $activityId)
            ->whereIn('meta_key', ['bp_media_ids', '_gif_raw_data'])
            ->get()
            ->keyBy('meta_key')
            ->toArray();

        if (!$mediaMetas) {
            return null;
        }

        if (!empty($mediaMetas['bp_media_ids']) && !empty($mediaMetas['bp_media_ids']->meta_value)) {
            $mediaMeta = $mediaMetas['bp_media_ids'];
            $mediaIds = explode(',', $mediaMeta->meta_value);

            $mediaItems = fluentCommunityApp('db')->table('bp_media')
                ->select(['attachment_id'])
                ->whereIn('id', $mediaIds)
                ->get()
                ->pluck('attachment_id')
                ->toArray();

            $mediaPosts = fluentCommunityApp('db')->table('posts')
                ->whereIn('id', $mediaItems)
                ->where('post_type', 'attachment')
                ->get();

            foreach ($mediaPosts as $mediaPost) {
                if (strpos($mediaPost->post_mime_type, 'image/') === false) {
                    continue;
                }
                $meta = (array)get_post_meta($mediaPost->ID, '_wp_attachment_metadata', true);
                $mediaPreviews[] = [
                    'media_id' => NULL,
                    'url'      => $mediaPost->guid,
                    'type'     => 'image',
                    'width'    => Arr::get($meta, 'width'),
                    'height'   => Arr::get($meta, 'height'),
                    'provider' => 'external'
                ];
            }
        } else if (!empty($mediaMetas['_gif_raw_data'])) {
            $giphyMeta = maybe_unserialize($mediaMetas['_gif_raw_data']->meta_value, true);

            $giphyMedia = Arr::get($giphyMeta, 'images.downsized_medium', []);
            if (!$giphyMedia || empty($giphyMedia['url'])) {
                return null;
            }

            return [
                'media_preview' => array_filter([
                    'image'    => sanitize_url($giphyMedia['url']),
                    'type'     => 'image',
                    'provider' => 'giphy',
                    'height'   => (int)Arr::get($giphyMedia, 'height', 0),
                    'width'    => (int)Arr::get($giphyMedia, 'width', 0),
                ])
            ];
        }

        if (!$mediaPreviews) {
            return null;
        }

        if (count($mediaPreviews) == 1) {
            $media = $mediaPreviews[0];
            $media['image'] = $media['url'];
            unset($media['url']);
            return [
                'media_preview' => $media
            ];
        }

        return [
            'media_items' => $mediaPreviews
        ];
    }

    public static function isBuddyBoss()
    {
        return defined('BP_PLATFORM_VERSION');
    }

    private static function syncPostReactions($postsId, $feedId)
    {
        if (!self::isBuddyBoss()) {
            return;
        }

        // feed likes
        $reactions = fluentCommunityApp('db')->table('bb_user_reactions')
            ->select(['user_id', 'date_created', 'item_id'])
            ->where('item_id', $postsId)
            ->where('item_type', 'activity')
            ->get();

        $likesArray = [];
        foreach ($reactions as $reaction) {
            $likesArray[] = [
                'user_id'     => $reaction->user_id,
                'object_id'   => $feedId,
                'object_type' => 'feed',
                'type'        => 'like',
                'created_at'  => $reaction->date_created,
                'updated_at'  => $reaction->date_created
            ];
        }

        if ($likesArray) {
            Reaction::insert($likesArray);
        }
    }

    private static function syncCommentsReactions($commentIdMaps, $feedId)
    {
        if (!self::isBuddyBoss()) {
            return;
        }

        $bbCommentIds = array_keys($commentIdMaps);

        if (!$bbCommentIds) {
            return false;
        }

        $reactions = fluentCommunityApp('db')->table('bb_user_reactions')
            ->select(['user_id', 'date_created', 'item_id'])
            ->whereIn('item_id', $bbCommentIds)
            ->where('item_type', 'activity_comment')
            ->get();

        $likesArray = [];
        $likesCount = [];
        foreach ($reactions as $reaction) {
            $commentId = (int)Arr::get($commentIdMaps, $reaction->item_id);
            if ($commentId) {
                if (empty($likesCount[$commentId])) {
                    $likesCount[$commentId] = 0;
                }
                $likesCount[$commentId] = $likesCount[$commentId] + 1;
                $likesArray[] = [
                    'user_id'     => $reaction->user_id,
                    'object_id'   => $commentId,
                    'object_type' => 'comment',
                    'parent_id'   => $feedId,
                    'type'        => 'like',
                    'created_at'  => $reaction->date_created,
                    'updated_at'  => $reaction->date_created
                ];
            }
        }

        if ($likesArray) {
            Reaction::insert($likesArray);
            foreach ($likesCount as $commentId => $count) {
                Comment::where('id', $commentId)->update(['reactions_count' => $count]);
            }
        }
    }

    public static function syncUser(User $user)
    {
        $xprofile = $user->syncXProfile();
        // Let's sync the
        if (!$xprofile->hasCustomAvatar()) {
            $avatar = get_avatar_url($user->ID, 'full', true);
            if ($avatar) {
                if (strpos($avatar, 'wp-content/plugins')) {
                    $avatar = null;
                }
            }

            $coverPhoto = bp_attachments_get_attachment(
                'url',
                array(
                    'object_dir' => 'members',
                    'item_id'    => $user->ID,
                )
            );

            $hasChange = false;
            if ($coverPhoto) {
                $meta = $xprofile->meta;
                $meta['cover_photo'] = $coverPhoto;
                $xprofile->meta = $meta;
                $hasChange = true;
            }

            if ($avatar && !strpos($avatar, 'gravatar.com')) {
                $xprofile->avatar = $avatar;
                $hasChange = true;
            }

            if ($hasChange) {
                $xprofile->save();
            }
        }

        if (!self::isBuddyBoss()) {
            $favIds = get_user_meta($user->ID, 'bp_favorite_activities', true);
            if ($favIds) {
                $favFeedIds = fluentCommunityApp('db')->table('bp_activity_meta')
                    ->whereIn('activity_id', $favIds)
                    ->select(['meta_value'])
                    ->where('meta_key', '_fcom_feed_id')
                    ->get()
                    ->pluck('meta_value')
                    ->toArray();

                if ($favFeedIds) {
                    $favPosts = Feed::whereIn('id', $favFeedIds)->get();
                    foreach ($favPosts as $favPost) {
                        $exist = Reaction::where('user_id', $user->ID)
                            ->where('object_id', $favPost->id)
                            ->where('type', 'like')
                            ->objectType('feed')
                            ->exists();

                        if (!$exist) {
                            Reaction::create([
                                'user_id'     => get_current_user_id(),
                                'object_id'   => $favPost->id,
                                'type'        => 'like',
                                'object_type' => 'feed'
                            ]);

                            $favPost->reactions_count = (int)$favPost->reactions_count + 1;
                            $favPost->save();
                        }
                    }
                }
            }
        }

        return true;
    }
}
