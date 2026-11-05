<?php

namespace FluentCommunity\App\Services\Search\Providers;

use FluentCommunity\App\Models\Space;
use FluentCommunity\App\Services\Search\SearchQuery;
use FluentCommunity\Framework\Support\Arr;

/**
 * Global search results for community spaces ("spaces").
 *
 * Follows SpaceController::discover exactly: a secret space shows only to its active
 * members and to community moderators, and, like discover, no status filter applies.
 * Matches title and description.
 */
class SpaceSearchProvider
{
    public function search(SearchQuery $query, $user, array $args)
    {
        $limit = (int) $args['limit'];
        $page = (int) $args['page'];
        $userId = $user ? (int) $user->ID : 0;
        $isModerator = $user && $user->hasCommunityModeratorAccess();

        $spacesQuery = Space::query()
            ->select([ 'id', 'title', 'slug', 'logo', 'privacy', 'settings', 'parent_id' ])
            ->with([
                'space_pivot' => function ($q) use ($userId) {
                    $q->where('user_id', $userId)->where('status', 'active');
                },
            ])
            ->when(!$isModerator, function ($q) use ($userId) {
                $q->where(function ($q) use ($userId) {
                    $q->whereIn('privacy', [ 'public', 'private' ]);
                    if ($userId) {
                        $q->orWhereHas('space_pivot', function ($q) use ($userId) {
                            $q->where('user_id', $userId)->where('status', 'active');
                        });
                    }
                });
            });

        $query->whereAllTerms($spacesQuery, [ 'title', 'description' ]);
        $query->orderByAllTermsIn($spacesQuery, 'title');
        $spacesQuery->orderBy('title', 'ASC')->orderBy('id', 'ASC');

        $spaces = $spacesQuery->offset(($page - 1) * $limit)->limit($limit + 1)->get();

        $items = [];
        foreach ($spaces->slice(0, $limit) as $space) {
            $settings = (array) $space->settings;
            $items[] = [
                'id'        => (int) $space->id,
                'title'     => $space->title,
                'slug'      => $space->slug,
                'privacy'   => $space->privacy,
                'logo'      => $space->logo,
                'settings'  => [
                    'emoji'     => Arr::get($settings, 'emoji', ''),
                    'shape_svg' => Arr::get($settings, 'shape_svg', ''),
                ],
                'is_member' => (bool) $space->space_pivot,
            ];
        }

        return [ 'items' => $items, 'has_more' => $spaces->count() > $limit ];
    }
}
