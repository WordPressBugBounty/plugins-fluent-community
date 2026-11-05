<?php

namespace FluentCommunity\App\Services\Search\Providers;

use FluentCommunity\App\Models\BaseSpace;
use FluentCommunity\App\Models\Comment;
use FluentCommunity\App\Models\Feed;
use FluentCommunity\App\Services\FeedsHelper;
use FluentCommunity\App\Services\ProfileHelper;
use FluentCommunity\App\Services\Search\SearchQuery;

/**
 * Global search results for posts ("posts": title and text) and for posts with a
 * matching comment ("comments").
 *
 * Visibility follows FeedsController::get: the same moderation status rule, the same
 * space access rule, active authors only, and the fluent_community/feeds_query action,
 * so filters such as the pro block list apply to search too.
 */
class PostSearchProvider
{
    public function searchPosts(SearchQuery $query, $user, array $args)
    {
        return $this->run($query, $user, $args, false);
    }

    public function searchComments(SearchQuery $query, $user, array $args)
    {
        return $this->run($query, $user, $args, true);
    }

    private function run(SearchQuery $query, $user, array $args, $inComments)
    {
        $limit = (int) $args['limit'];
        $page = (int) $args['page'];
        $space = null;

        if (!empty($args['space'])) {
            $space = BaseSpace::where('slug', $args['space'])->first();
            if (!$space) {
                return [ 'items' => [], 'has_more' => false ];
            }
        }

        $feedsQuery = $this->visibleFeeds($user, $space);

        if ($inComments) {
            // A post whose comments are switched off serves no thread (CommentsController::getComments),
            // so its comments must not match either. The full check runs again in PHP below.
            $feedsQuery->where(function ($metaQuery) {
                $metaQuery->whereNull('meta')
                    ->orWhere('meta', 'NOT LIKE', '%"comments_disabled";s:3:"yes"%');
            });
            $feedsQuery->whereHas('comments', function ($commentQuery) use ($query, $user) {
                $this->visibleComments($commentQuery, $user);
                $query->whereAllTerms($commentQuery, [ 'message' ]);
            });
        } else {
            // Same as Feed::scopeSearchBy: a document post also matches on its file titles
            $query->whereAllTerms($feedsQuery, [ 'title', 'message' ], function ($group, $escapedTerm) {
                $group->orWhere(function ($documentQuery) use ($escapedTerm) {
                    $documentQuery->where('content_type', 'document')
                        ->where('meta', 'LIKE', '%document_lists%title%' . $escapedTerm . '%');
                });
            });
        }

        $requestData = [
            'search'        => $query->text,
            'order_by_type' => $args['sort'],
            'space'         => $space ? $space->slug : '',
        ];

        $queryArgs = [
            'search'           => $query->text,
            'space_slug'       => $space ? $space->slug : '',
            'per_page'         => $limit,
            'page'             => $page,
            'is_main_query'    => false,
            'is_global_search' => true,
        ];

        /*
         * Same action as FeedsController::get, so listeners such as the pro block list apply.
         * From search, $requestData holds only search, order_by_type (relevance|latest|popular)
         * and space, and $queryArgs['is_global_search'] is true.
         */
        do_action_ref_array('fluent_community/feeds_query', [ &$feedsQuery, $requestData, $queryArgs ]);

        $this->applySort($feedsQuery, $query, $args['sort'], $inComments);

        $feeds = $feedsQuery->offset(($page - 1) * $limit)->limit($limit + 1)->get();

        $hasMore = $feeds->count() > $limit;
        $feeds = $feeds->slice(0, $limit)->values();

        if ($inComments) {
            $feeds = $feeds->filter(function ($feed) {
                return apply_filters('fluent_community/can_view_comments_' . $feed->type, FeedsHelper::commentsEnabled($feed), $feed);
            })->values();
        }

        $matchedComments = $inComments ? $this->firstMatchingComments($query, $user, $feeds->pluck('id')->toArray()) : [];

        $items = [];
        foreach ($feeds as $feed) {
            $comment = $matchedComments[$feed->id] ?? null;
            $text = SearchQuery::plainText($feed->message_rendered);

            $items[] = [
                'id'              => (int) $feed->id,
                'slug'            => $feed->slug,
                'title'           => (string) $feed->title,
                'snippet'         => $comment ? '' : $query->snippet($text),
                'excerpt'         => $feed->title ? '' : mb_substr($text, 0, 140),
                'content_type'    => $feed->content_type,
                'created_at'      => $this->dateString($feed->created_at),
                'comments_count'  => (int) $feed->comments_count,
                'reactions_count' => (int) $feed->reactions_count,
                'space'           => $feed->space ? [
                    'id'    => (int) $feed->space->id,
                    'title' => $feed->space->title,
                    'slug'  => $feed->space->slug,
                ] : null,
                'author'          => $this->author($feed->xprofile),
                'matched_comment' => $comment ? [
                    'id'      => (int) $comment->id,
                    'author'  => $this->author($comment->xprofile),
                    'snippet' => $query->snippet(SearchQuery::plainText($comment->message_rendered ? $comment->message_rendered : $comment->message)),
                ] : null,
            ];
        }

        return [ 'items' => $items, 'has_more' => $hasMore ];
    }

    private function visibleFeeds($user, $space)
    {
        $feedsQuery = Feed::select(Feed::$publicColumns)
            ->with([
                'xprofile' => function ($q) {
                    $q->select(ProfileHelper::getXProfilePublicFields());
                },
                'space'    => function ($q) {
                    $q->select([ 'id', 'title', 'slug', 'type' ]);
                },
            ])
            ->byContentModerationAccessStatus($user, $space)
            ->byUserAccess($user ? $user->ID : 0)
            ->byActiveAuthor();

        if ($space) {
            $feedsQuery->filterBySpaceSlug($space->slug);
        }

        return $feedsQuery;
    }

    /**
     * The comments a searcher could see in the comment list (see Feed::scopeSearchBy).
     */
    private function visibleComments($commentQuery, $user)
    {
        return $commentQuery->byContentModerationAccessStatus($user)
            ->whereHas('xprofile', function ($q) {
                $q->where('status', 'active');
            });
    }

    private function applySort($feedsQuery, SearchQuery $query, $sort, $inComments)
    {
        if ($sort === 'popular') {
            $feedsQuery->customOrderBy('popular');
            return;
        }

        if ($sort === 'relevance' && !$inComments) {
            $query->orderByAllTermsIn($feedsQuery, 'title');
        }

        $feedsQuery->orderBy('created_at', 'DESC')->orderBy('id', 'DESC');
    }

    private function firstMatchingComments(SearchQuery $query, $user, array $feedIds)
    {
        if (!$feedIds) {
            return [];
        }

        // Only the id of the first matching comment per post, then load just those rows
        $idQuery = Comment::query()
            ->selectRaw('MIN(id) as first_id')
            ->whereIn('post_id', $feedIds)
            ->groupBy('post_id');

        $this->visibleComments($idQuery, $user);
        $query->whereAllTerms($idQuery, [ 'message' ]);

        $commentIds = $idQuery->pluck('first_id')->toArray();
        if (!$commentIds) {
            return [];
        }

        $comments = Comment::query()
            ->select([ 'id', 'post_id', 'user_id', 'message', 'message_rendered', 'status' ])
            ->whereIn('id', $commentIds)
            ->with([
                'xprofile' => function ($q) {
                    $q->select(ProfileHelper::getXProfilePublicFields());
                },
            ])
            ->get();

        $firstByFeed = [];
        foreach ($comments as $comment) {
            $firstByFeed[$comment->post_id] = $comment;
        }

        return $firstByFeed;
    }

    private function dateString($date)
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d H:i:s');
        }

        return (string) $date;
    }

    private function author($xprofile)
    {
        if (!$xprofile) {
            return null;
        }

        return [
            'user_id'      => (int) $xprofile->user_id,
            'username'     => $xprofile->username,
            'display_name' => $xprofile->display_name,
            'avatar'       => $xprofile->avatar,
        ];
    }
}
