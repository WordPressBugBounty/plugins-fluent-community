<?php

namespace FluentCommunity\App\Services\Search\Providers;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Models\XProfile;
use FluentCommunity\App\Services\ProfileHelper;
use FluentCommunity\App\Services\Search\SearchQuery;

/**
 * Global search results for members ("people").
 *
 * Follows MembersController::getMembers: nothing at all unless the Members page privacy
 * setting allows this user (Utility::canViewMembersPage), active profiles only, public
 * profile fields only, matching on display name and username (never email), and the
 * fluent_community/members_query_ref action.
 */
class MemberSearchProvider
{
    public function search(SearchQuery $query, $user, array $args)
    {
        if (!Utility::canViewMembersPage()) {
            return [ 'items' => [], 'has_more' => false ];
        }

        $limit = (int) $args['limit'];
        $page = (int) $args['page'];

        $membersQuery = XProfile::select(ProfileHelper::getXProfilePublicFields())
            ->whereHas('user')
            ->where('status', 'active');

        $query->whereAllTerms($membersQuery, [ 'display_name', 'username' ]);

        $requestData = [
            'search'           => $query->text,
            'is_global_search' => true,
        ];

        // Same action as MembersController::getMembers; from search, $requestData holds only
        // search and is_global_search (true)
        do_action_ref_array('fluent_community/members_query_ref', [ &$membersQuery, $requestData ]);

        $query->orderByAllTermsIn($membersQuery, 'display_name');
        $membersQuery->orderBy('last_activity', 'DESC')->orderBy('user_id', 'DESC');

        $members = $membersQuery->offset(($page - 1) * $limit)->limit($limit + 1)->get();

        $items = [];
        foreach ($members->slice(0, $limit) as $member) {
            $items[] = [
                'user_id'      => (int) $member->user_id,
                'username'     => $member->username,
                'display_name' => $member->display_name,
                'avatar'       => $member->avatar,
                'is_verified'  => (int) $member->is_verified,
            ];
        }

        return [ 'items' => $items, 'has_more' => $members->count() > $limit ];
    }
}
