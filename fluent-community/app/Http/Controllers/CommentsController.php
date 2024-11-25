<?php

namespace FluentCommunity\App\Http\Controllers;

use FluentCommunity\App\Models\Media;
use FluentCommunity\App\Models\User;
use FluentCommunity\App\Services\CustomSanitizer;
use FluentCommunity\App\Services\FeedsHelper;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Services\ProfileHelper;
use FluentCommunity\Framework\Http\Request\Request;
use FluentCommunity\App\Models\Comment;
use FluentCommunity\App\Models\Feed;
use FluentCommunity\App\Models\Reaction;
use FluentCommunity\Framework\Support\Arr;

class CommentsController extends Controller
{
    public function getComments(Request $request, $feed_id)
    {
        $feed = Feed::withoutGlobalScopes()->findOrFail($feed_id);
        $canViewComments = apply_filters('fluent_community/can_view_comments_' . $feed->type, true, $feed);

        if (!$canViewComments) {
            return [
                'comments' => []
            ];
        }

        $comments = Comment::where('post_id', $feed->id)
            ->orderBy('created_at', 'asc')
            ->with([
                'xprofile' => function ($q) {
                    $q->select(ProfileHelper::getXProfilePublicFields());
                }
            ])
            ->get();

        $userId = $this->getUserId();

        if ($userId) {
            $likedIds = FeedsHelper::getLikedIdsByUserFeedId($feed->id, get_current_user_id());

            if ($likedIds) {
                $comments->each(function ($comment) use ($likedIds) {
                    if (in_array($comment->id, $likedIds)) {
                        $comment->liked = 1;
                    }
                });
            }
        }

        return [
            'comments' => $comments
        ];
    }

    public function store(Request $request, $feedId)
    {
        $user = $this->getUser(true);
        do_action('fluent_community/check_rate_limit/create_comment', $user);

        $text = $this->validateCommentText($request->all());
        $feed = Feed::withoutGlobalScopes()->findOrFail($feedId);

        $this->verifyCreateCommentPermission($feed);

        $requestData = $request->all();

        // Check for duplicate
        $exist = Comment::where('user_id', get_current_user_id())
            ->where('message', $text)
            ->where('post_id', $feed->id)
            ->first();

        if ($exist) {
            return $this->sendError([
                'message' => __('No duplicate comment please!', 'fluent-community')
            ]);
        }

        $mentions = FeedsHelper::getMentions($text, $feed->space_id);
        $commentHtml = $this->generateCommentHtml($text, $mentions);
        $commentData = $this->prepareCommentData($feed->id, $text, $commentHtml);

        if ($parentId = $request->get('parent_id')) {
            $parentId = (int) $parentId;
            $parentComment = Comment::where('id', $parentId)
                ->where('post_id', $feed->id)
                ->first();

            if (!$parentComment) {
                return $this->sendError([
                    'message' => __('Invalid parent comment', 'fluent-community')
                ]);
            }

            $commentData['parent_id'] = $parentId;
        }

        [$commentData, $media] = $this->prepareCommentMedia($commentData, $requestData);

        do_action('fluent_community/before_comment_create', $commentData, $feed);

        $commentData = apply_filters('fluent_community/comment/comment_data', $commentData, $feed, $requestData);

        $comment = Comment::create($commentData);

        $feed->comments_count = $feed->comments_count + 1;
        $feed->save();

        if ($media) {
            $media->fill([
                'is_active'     => 1,
                'feed_id'       => $feed->id,
                'object_source' => 'comment',
                'sub_object_id' => $comment->id
            ]);
            $media->save();
        }

        $this->loadCommentRelations($comment);

        $mentionedUsers = $mentions ? $mentions['users'] : null;
        do_action('fluent_community/comment_added_' . $feed->type, $comment, $feed, $mentionedUsers);
        do_action('fluent_community/comment_added', $comment, $feed, $mentionedUsers);

        return [
            'comment' => $comment,
            'message' => __('Comment has been added', 'fluent-community'),
        ];
    }

    public function update(Request $request, $feedId, $commentId)
    {
        $text = $this->validateCommentText($request->all());
        $feed = Feed::withoutGlobalScopes()->findOrFail($feedId);
        $this->verifySpacePermission($feed);

        $requestData = $request->all();
        $comment = Comment::findOrFail($commentId);
        $user = User::find(get_current_user_id());

        if (!$user->can('edit_any_comment') && $comment->user_id != get_current_user_id()) {
            return $this->sendError([
                'message' => __('You are not allowed to edit this comment', 'fluent-community')
            ]);
        }

        $mentions = FeedsHelper::getMentions($text, $feed->space_id);
        $commentHtml = $this->generateCommentHtml($text, $mentions);

        $commentData = $this->prepareCommentData($feed->id, $text, $commentHtml);

        [$commentData, $media] = $this->prepareCommentMedia($commentData, $requestData, $comment);

        $commentData = apply_filters('fluent_community/comment/update_comment_data', $commentData, $feed, $requestData);

        $comment->fill($commentData);

        $dirty = $comment->getDirty();

        if ($dirty) {
            $comment->save();
        }

        if ($media) {
            $media->fill([
                'is_active'     => 1,
                'feed_id'       => $feed->id,
                'object_source' => 'comment',
                'sub_object_id' => $comment->id
            ]);
            $media->save();

            // remove other media
            $otherMedias = Media::where('object_source', 'comment')
                ->where('sub_object_id', $comment->id)
                ->where('id', '!=', $media->id)
                ->get();

            if (!$otherMedias->isEmpty()) {
            //    do_action('fluent_community/comment/media_deleted', $otherMedias);
            }
        } else {
            // remove other media
            $otherMedias = Media::where('object_source', 'comment')
                ->where('sub_object_id', $comment->id)
                ->get();

            if (!$otherMedias->isEmpty()) {
             //   do_action('fluent_community/comment/media_deleted', $otherMedias);
            }
        }

        $this->loadCommentRelations($comment);

        if ($dirty) {
            do_action('fluent_community/comment_updated', $comment, $feed);
            do_action('fluent_community/comment_updated_' . $feed->type, $comment, $feed);
        }

        return [
            'comment' => $comment,
            'message' => __('Comment has been updated', 'fluent-community'),
        ];
    }

    private function prepareCommentMedia($commentData, $requestData, $exisitngComment = null)
    {
        $mediaImages = Arr::get($requestData, 'media_images', []);

        if ($mediaImages) {
            $uploadedImages = Helper::getMediaByProvider($mediaImages);
            if ($uploadedImages) {
                $mediaItems = Helper::getMediaItemsFromUrl($uploadedImages);
                if ($mediaItems) {
                    $firstMedia = $mediaItems[0];
                    $commentData['meta']['media_preview'] = [
                        'image'    => $firstMedia->public_url,
                        'type'     => 'image',
                        'provider' => 'upload',
                        'height'   => $firstMedia->settings ? Arr::get($firstMedia->settings, 'height', 0) : 0,
                        'width'    => $firstMedia->settings ? Arr::get($firstMedia->settings, 'width', 0) : 0,
                    ];
                    return [$commentData, $firstMedia];
                }
            }
        }

        if (empty($requestData['meta']['media_preview']['image'])) {
            return [$commentData, null];
        }

        if ($exisitngComment) {
            $image = sanitize_url(Arr::get($requestData, 'meta.media_preview.image', ''));
            $existingMedia = Media::where('media_url', $image)
                ->where('object_source', 'comment')
                ->where('sub_object_id', $exisitngComment->id)
                ->first();

            if ($existingMedia) {
                $commentData['meta'] = $exisitngComment->meta;
                return [$commentData, $existingMedia];
            }
        }

        $commentData['meta']['media_preview'] = array_filter([
            'image'    => sanitize_url(Arr::get($requestData, 'meta.media_preview.image', '')),
            'type'     => Arr::get($requestData, 'meta.media_preview.type', 'image'),
            'provider' => Arr::get($requestData, 'meta.media_preview.provider', ''),
            'height'   => Arr::get($requestData, 'meta.media_preview.height', 0),
            'width'    => Arr::get($requestData, 'meta.media_preview.width', 0),
        ]);

        return [$commentData, null];
    }

    private function validateCommentText($data)
    {
        $text = trim(Arr::get($data, 'comment'));
        $text = CustomSanitizer::unslashMarkdown($text);

        $hasMedia = Arr::get($data, 'media_images', []) || Arr::get($data, 'meta.media_preview.image', false);

        if (!$text && !$hasMedia) {
            throw new \Exception(esc_html__('Please provide your reply text', 'fluent-community'), 422);
        }

        $maxCommentLength = apply_filters('fluent_community/max_comment_char_length', 10000);
        if ($text && strlen($text) > $maxCommentLength) {
            throw new \Exception(esc_html__('Comment text is too long', 'fluent-community'), 422);
        }

        return $text;
    }

    private function verifyCreateCommentPermission($feed)
    {
        if (Arr::get($feed->meta, 'comments_disabled') === 'yes') {
            throw new \Exception(esc_html__('Comments are disabled for this post', 'fluent-community'));
        }

        $this->verifySpacePermission($feed);
    }

    private function verifySpacePermission($feed)
    {
        if ($feed->space_id && $feed->space) {
            $user = $this->getUser(true);
            $user->verifySpacePermission('registered', $feed->space);
        }
    }

    private function generateCommentHtml($text, $mentions)
    {
        $htmlText = $mentions ? $mentions['text'] : $text;
        return wp_kses_post(FeedsHelper::mdToHtml($htmlText));
    }

    private function prepareCommentData($feedId, $text, $commentHtml)
    {
        return [
            'post_id'          => $feedId,
            'message'          => $text,
            'message_rendered' => $commentHtml,
            'type'             => 'comment',
            'meta'             => [],
        ];
    }

    private function loadCommentRelations($comment)
    {
        $comment->load('media');
        $comment->load([
            'xprofile' => function ($q) {
                $q->select(ProfileHelper::getXProfilePublicFields());
            }
        ]);
    }

    public function addOrRemovePostReact(Request $request, $feed_id)
    {
        $feed = Feed::withoutGlobalScopes()->findOrFail($feed_id);
        $type = $request->get('react_type', 'like');
        $willRemove = $request->get('remove');

        $react = Reaction::where('user_id', get_current_user_id())
            ->where('object_id', $feed->id)
            ->where('type', $type)
            ->objectType('feed')
            ->first();

        if ($willRemove) {
            if ($react) {
                $react->delete();
                if ($type == 'like') {
                    $feed->reactions_count = $feed->reactions_count - 1;
                    $feed->save();
                }
            }

            return [
                'message'   => 'Reaction has been removed',
                'new_count' => $feed->reactions_count
            ];
        }

        if ($react) {
            return [
                'message'   => 'You have already reacted to this post',
                'new_count' => $feed->reactions_count
            ];
        }

        $react = Reaction::create([
            'user_id'     => get_current_user_id(),
            'object_id'   => $feed->id,
            'type'        => $type,
            'object_type' => 'feed'
        ]);

        if ($type == 'like') {
            $feed->reactions_count = $feed->reactions_count + 1;
            $feed->save();

            $react->load('xprofile');
            do_action('fluent_community/feed/react_added', $react, $feed);
        }

        return [
            'message'   => 'Reaction has been added',
            'new_count' => $feed->reactions_count
        ];
    }

    public function deleteComment(Request $request, $feedId, $commentId)
    {
        $feed = Feed::withoutGlobalScopes()->findOrFail($feedId);
        $comment = Comment::findOrFail($commentId);

        if ($comment->post_id != $feed->id) {
            return $this->sendError([
                'message' => 'Invalid comment'
            ]);
        }

        $user = User::find(get_current_user_id());
        if (!$user->can('delete_any_comment') && $comment->user_id != get_current_user_id()) {
            return $this->sendError([
                'message' => 'You are not allowed to delete this comment'
            ]);
        }

        do_action('fluent_community/before_comment_delete', $comment);

        if ($comment->media) {
            do_action('fluent_community/comment/media_deleted', $comment->media);
        }

        $comment->delete();

        $feed->comments_count = Comment::where('post_id', $feed->id)->count();
        $feed->save();

        do_action('fluent_community/comment_deleted_' . $feed->type, $commentId, $feed);
        do_action('fluent_community/comment_deleted', $commentId, $feed);

        return [
            'message' => __('Selected comment has been deleted', 'fluent-community')
        ];
    }

    public function toggoleReaction(Request $request, $feedId, $commentId)
    {
        $feed = Feed::withoutGlobalScopes()->findOrFail($feedId);
        $comment = Comment::findOrFail($commentId);

        if ($comment->post_id != $feed->id) {
            return $this->sendError([
                'message' => 'Invalid comment'
            ]);
        }

        $user = User::findOrFail(get_current_user_id());

        if ($feed->space_id) {
            $user->verifySpacePermission('registered', $feed->space);
        }

        $reactionState = !!$request->get('state', false);

        if ($reactionState) {
            // add or update the reaction
            $reaction = Reaction::firstOrCreate([
                'user_id'     => get_current_user_id(),
                'object_id'   => $comment->id,
                'object_type' => 'comment',
                'parent_id'   => $feed->id
            ]);

            if ($reaction->wasRecentlyCreated) {
                $comment->reactions_count = $comment->reactions_count + 1;
                $comment->save();
            }
        } else {
            // remove the reaction
            $deleted = Reaction::where('user_id', get_current_user_id())
                ->where('object_id', $comment->id)
                ->where('object_type', 'comment')
                ->delete();

            if ($deleted) {
                $comment->reactions_count = $comment->reactions_count - 1;
                $comment->save();
            }
        }

        return [
            'message'         => 'Reaction has been toggled',
            'reactions_count' => $comment->reactions_count,
            'liked'           => $reactionState
        ];
    }

    public function show(Request $request, $id)
    {
        $comment = Comment::with([
            'xprofile' => function ($q) {
                return $q->select(ProfileHelper::getXProfilePublicFields());
            }
        ])->findOrFail($id);

        // Just to verify the permission
        $feed = Feed::withoutGlobalScopes()
            ->byUserAccess($this->getUserId())
            ->findOrFail($comment->post_id);

        return [
            'comment' => $comment
        ];
    }
}
