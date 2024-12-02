<?php

namespace FluentCommunity\App\Http\Controllers;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Models\Space;
use FluentCommunity\App\Models\Feed;
use FluentCommunity\App\Models\BaseSpace;
use FluentCommunity\App\Models\SpaceGroup;
use FluentCommunity\App\Models\User;
use FluentCommunity\App\Services\CustomSanitizer;
use FluentCommunity\App\Services\FeedsHelper;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Services\LockscreenService;
use FluentCommunity\App\Services\ProfileHelper;
use FluentCommunity\Framework\Http\Request\Request;
use FluentCommunity\App\Models\Comment;
use FluentCommunity\App\Models\Reaction;
use FluentCommunity\App\Models\SpaceUserPivot;
use FluentCommunity\Framework\Support\Arr;

class SpaceController extends Controller
{
    public function get()
    {
        $spaces = Space::orderBy('title', 'ASC')
            ->whereHas('space_pivot', function ($q) {
                $q->where('user_id', get_current_user_id());
            })
            ->get();

        return [
            'spaces' => $spaces
        ];
    }

    public function create(Request $request)
    {
        $currentUser = $this->getUser(true);
        $currentUser->verifyCommunityPermission('community_admin');

        $data = $request->get('space', []);

        if (empty($data['slug'])) {
            $data['slug'] = sanitize_title($data['title'], '');
        } else {
            $data['slug'] = sanitize_title($data['slug'], '');
        }

        $data['title'] = sanitize_text_field($data['title']);
        $data['privacy'] = sanitize_text_field($data['privacy']);

        $this->validate($data, [
            'title'   => 'required',
            'slug'    => 'required|unique:fcom_spaces,slug',
            'privacy' => 'required|in:public,private,secret'
        ]);

        $spaceGroup = null;
        if (!empty($data['parent_id'])) {
            $spaceGroup = SpaceGroup::findOrFail($data['parent_id']);
            $serial = BaseSpace::where('parent_id', $spaceGroup->id)->max('serial') + 1;
        } else {
            $serial = BaseSpace::max('serial') + 1;
        }

        $spaceData = apply_filters('fluent_community/space/create_data', [
            'title'       => sanitize_text_field($data['title']),
            'slug'        => $data['slug'],
            'privacy'     => $data['privacy'],
            'description' => sanitize_textarea_field($data['description']),
            'settings'    => [
                'restricted_post_only' => Arr::get($data, 'settings.restricted_post_only', 'no'),
                'emoji'                => CustomSanitizer::sanitizeEmoji(Arr::get($data, 'settings.emoji', '')),
                'can_request_join'     => Arr::get($data, 'settings.can_request_join', 'no'),
                'custom_lock_screen'   => Arr::get($data, 'settings.custom_lock_screen', 'no'),
                'layout_style'         => Arr::get($data, 'settings.layout_style', 'timeline'),
                'show_sidebar'         => Arr::get($data, 'settings.show_sidebar', 'yes'),
                'shape_svg'            => CustomSanitizer::sanitizeSvg(Arr::get($data, 'settings.shape_svg', '')),
                'hide_members_count'   => $serial
            ],
            'parent_id'   => $spaceGroup ? $spaceGroup->id : null,
            'serial'      => $spaceGroup ?: 1
        ]);

        $ogImage = Arr::get($data, 'settings.og_image', '');
        $ogMedia = null;
        if ($ogImage) {
            $ogMedia = Helper::getMediaFromUrl($ogImage);
            if ($ogMedia && !$ogMedia->is_active) {
                $spaceData['settings']['og_image'] = $ogMedia->public_url;
            }
        }

        $space = Space::create($spaceData);
        if ($ogMedia && !$ogMedia->is_active) {
            $ogMedia->update([
                'is_active'     => true,
                'user_id'       => get_current_user_id(),
                'sub_object_id' => $space->id,
                'object_source' => 'space_og_image'
            ]);
        }

        $imageTypes = ['cover_photo', 'logo'];
        $metaData = [];
        foreach ($imageTypes as $type) {
            if (!empty($data[$type])) {
                $media = Helper::getMediaFromUrl($data[$type]);
                if (!$media || $media->is_active) {
                    continue;
                }
                $metaData[$type] = $media->public_url;
                $media->update([
                    'is_active'     => true,
                    'user_id'       => get_current_user_id(),
                    'sub_object_id' => $space->id,
                    'object_source' => 'space_' . $type
                ]);
            }
        }

        if ($metaData) {
            $space->updateCustomData($metaData, false);
        }

        $space->members()->attach(get_current_user_id(), [
            'role' => 'admin'
        ]);

        $currentUser->cacheAccessSpaces();
        do_action('fluent_community/space/created', $space, $data);

        return [
            'message' => __('Space has been created successfully', 'fluent-community'),
            'space'   => $space
        ];
    }

    public function discover(Request $request)
    {
        $currentUser = $this->getUser();

        $spaces = Space::orderBy('title', 'ASC')
            ->with(['space_pivot' => function ($q) {
                $q->where('user_id', get_current_user_id());
            }])
            ->where(function ($q) {
                $q->whereHas('space_pivot', function ($q) {
                    $q->where('user_id', get_current_user_id());
                })
                    ->orWhereIn('privacy', ['public', 'private']);
            })
            ->get();

        foreach ($spaces as $space) {
            if (Arr::get($space->settings, 'hide_members_count') == 'yes' && (!$currentUser || !$space->verifyUserPermisson($currentUser, 'can_view_members', false))) {
                $space->members_count = 0;
                continue;
            }

            $space->members_count = SpaceUserPivot::where('space_id', $space->id)->where('status', 'active')
                ->whereHas('xprofile', function ($q) {
                    $q->where('status', 'active');
                })
                ->whereHas('user')
                ->count();
        }

        return [
            'spaces' => $spaces
        ];
    }

    public function getBySlug(Request $request, $spaceSlug)
    {
        $user = $this->getUser();
        $space = Space::where('slug', $spaceSlug)
            ->firstOrFail();

        $space->permissions = $space->getUserPermissions($user);
        $space->description_rendered = FeedsHelper::mdToHtml($space->description);
        $space->membership = $space->getMembership(get_current_user_id());
        $space->topics = Utility::getTopicsBySpaceId($space->id);

        if (!Helper::isSiteAdmin()) {
            $space->lockscreen_config = LockscreenService::getLockscreenConfig($space, $space->membership);
        }

        if ($space->privacy == 'secret' && !$space->membership) {
            return $this->sendError([
                'message'    => __('You are not allowed to view this space', 'fluent-community'),
                'error_type' => 'restricted'
            ]);
        }

        do_action_ref_array('fluent_community/space', [&$space]);

        return [
            'space' => $space
        ];
    }

    public function patchBySlug(Request $request, $slug)
    {
        $space = Space::where('slug', $slug)
            ->first();

        if (!$space) {
            return $this->sendError([
                'message' => 'Space not found'
            ]);
        }

        $space->verifyUserPermisson($this->getUser(), 'community_admin');

        $data = $request->get('data', []);

        if (!empty($data['title'])) {
            $taken = Space::where('title', $data['title'])
                ->where('id', '!=', $space->id)
                ->first();
            if ($taken) {
                return $this->sendError([
                    'message' => 'Space title is already taken. Please use a different title'
                ]);
            }
        }

        $mediaTypes = ['cover_photo', 'logo'];
        foreach ($mediaTypes as $type) {
            if (!empty($data[$type])) {
                $media = Helper::getMediaFromUrl($data[$type]);
                if (!$media) {
                    unset($data[$type]);
                    continue;
                }

                if (!$media || $media->is_active) {
                    return $this->sendError([
                        'message' => 'Invalid media image. Please upload a new one.'
                    ]);
                }

                $data[$type] = $media->public_url;

                $media->update([
                    'is_active'     => true,
                    'user_id'       => get_current_user_id(),
                    'sub_object_id' => $space->id,
                    'object_source' => 'space_' . $type
                ]);
            } else if (isset($data[$type])) {
                $data[$type] = '';
            }
        }

        $data = apply_filters('fluent_community/space/update_data', $data, $space);

        if (empty($data['parent_id'])) {
            $data['parent_id'] = '';
        }

        $space = $space->updateCustomData($data, true);

        if (Arr::has($data, 'topic_ids')) {
            $topicIds = (array)Arr::get($data, 'topic_ids', []);
            $space->syncTopics($topicIds);
        }

        do_action('fluent_community/space/updated', $space, $data);

        $slugUpdated = $slug != $space->slug;

        return [
            'message'      => __('Settings has been updated', 'fluent-community'),
            'redirect_url' => $slugUpdated ? $space->getPermalink() : ''
        ];
    }

    public function patchById(Request $request, $id)
    {
        $space = Space::findOrFail($id);
        return $this->patchBySlug($request, $space->slug);
    }

    public function getMembers(Request $request, $slug)
    {
        $space = Space::where('slug', $slug)
            ->firstOrFail();

        $user = $this->getUser();

        if (!$space->verifyUserPermisson($user, 'can_view_members', false)) {
            return $this->sendError([
                'message'           => __('You are not allowed to view members of this space', 'fluent-community'),
                'permission_failed' => true
            ]);
        }
        $search = $request->getSafe('search', 'sanitize_text_field');

        $pendingCount = 0;
        if ($user && $user->can('can_add_member', $space)) {
            $pendingCount = SpaceUserPivot::bySpace($space->id)
                ->where('status', 'pending')
                ->count();

            if ($request->get('status') == 'pending') {
                $pendingRequests = SpaceUserPivot::bySpace($space->id)
                    ->whereHas('xprofile', function ($q) use ($search) {
                        return $q->searchBy($search)
                            ->where('status', 'active');
                    })
                    ->with(['xprofile' => function ($q) {
                        $q->select(ProfileHelper::getXProfilePublicFields());
                    }])
                    ->where('status', 'pending')
                    ->paginate();

                return [
                    'members'       => $pendingRequests,
                    'pending_count' => $pendingCount
                ];
            }
        }

        $spaceMembers = SpaceUserPivot::bySpace($space->id)
            ->whereHas('xprofile', function ($q) use ($search) {
                return $q->searchBy($search)
                    ->where('status', 'active');
            })
            ->with(['xprofile' => function ($q) {
                $q->select(ProfileHelper::getXProfilePublicFields());
            }])
            ->where('status', 'active')
            ->paginate();

        return [
            'members'       => $spaceMembers,
            'pending_count' => $pendingCount
        ];
    }

    public function join(Request $request, $slug)
    {
        $space = Space::where('slug', $slug)->first();

        if (!$space) {
            return $this->sendError([
                'message' => 'Space not found'
            ]);
        }

        $user = $this->getUser();

        $membership = $space->getMembership(get_current_user_id());

        if ($membership) {
            return $this->sendError([
                'message' => 'You are already a member of this space. Please reload this page'
            ]);
        }

        $roles = $user->getCommunityRoles();

        if (!$roles && $space->privacy == 'secret') {
            return $this->sendError([
                'message' => 'You are not allowed to join this space'
            ]);
        }

        $status = 'active';
        if (!$roles) {
            if ($space->privacy != 'public') {
                $status = apply_filters('fluent_community/space/join_status_for_private', 'pending', $space, $user);

                if (!in_array($status, ['pending', 'active'])) {
                    $status = 'pending';
                }
            }
            $role = 'member';
        } else {
            $role = $user->isCommunityAdmin() ? 'admin' : 'moderator';
        }


        $space->members()->attach(get_current_user_id(), [
            'role'   => $role,
            'status' => $status
        ]);

        $space->membership = $space->getMembership(get_current_user_id());

        if ($status == 'pending') {
            do_action('fluent_community/space/join_requested', $space, $user->ID, 'self');
        } else {
            do_action('fluent_community/space/joined', $space, $user->ID, 'self');
        }

        $user->cacheAccessSpaces();

        return [
            'message'    => ($status == 'active') ? __('You have joined this Space', 'fluent-community') : __('Your join request has been sent to the Space admin.', 'fluent-community'),
            'membership' => $space->membership
        ];
    }

    public function leave(Request $request, $slug)
    {
        $user = $this->getUser(true);
        $space = Space::where('slug', $slug)->first();

        if (!$space) {
            return $this->sendError([
                'message' => 'Space not found'
            ]);
        }

        $membership = $space->getMembership($user->ID);

        if (!$membership) {
            return $this->sendError([
                'message' => 'You are not a member of this community'
            ]);
        }

        Helper::removeFromSpace($space, $user->ID, 'self');

        return [
            'message' => __('You have left this space', 'fluent-community')
        ];
    }

    public function delete(Request $request, $slug)
    {
        $space = Space::where('slug', $slug)->first();

        if (!$space) {
            return $this->sendError([
                'message' => 'Space not found'
            ]);
        }

        if (!Helper::isSiteAdmin()) {
            return $this->sendError([
                'message' => 'You are not allowed to delete this community'
            ]);
        }

        do_action('fluent_community/space/before_delete', $space);

        Comment::whereHas('post', function ($q) use ($space) {
            $q->where('space_id', $space->id);
        })->delete();

        Reaction::whereHas('feed', function ($q) use ($space) {
            $q->where('space_id', $space->id);
        })->delete();

        Feed::where('space_id', $space->id)->delete();

        SpaceUserPivot::where('space_id', $space->id)->delete();

        $spaceId = $space->id;
        $space->delete();

        do_action('fluent_community/space/deleted', $spaceId);

        return [
            'message' => __('Space has been deleted successfully', 'fluent-community')
        ];
    }

    public function addMember(Request $request, $slug)
    {
        $space = Space::where('slug', $slug)->first();

        if (!$space) {
            return $this->sendError([
                'message' => 'Space not found'
            ]);
        }

        $this->validate($request->all(), [
            'user_id' => 'required|exists:users,ID'
        ]);

        $userId = $request->get('user_id');
        $targetUser = User::findOrFail($userId);
        $xprofile = $targetUser->syncXProfile();

        if ($xprofile && $xprofile->status != 'active') {
            return $this->sendError([
                'message' => __('Selected user is not active', 'fluent-community')
            ]);
        }

        $admin = User::find(get_current_user_id());
        $admin->verifySpacePermission('can_add_member', $space);

        $pivot = SpaceUserPivot::bySpace($space->id)
            ->byUser($userId)
            ->first();

        $role = $request->get('role', 'member');

        if ($pivot) {
            if ($pivot->status == 'active') {
                if ($role != $pivot->role) {
                    $pivot->role = $role;
                    $pivot->save();

                    do_action('fluent_community/space/member/role_updated', $space, $pivot);

                    return [
                        'message' => 'Member role updated'
                    ];
                }

                return $this->sendError([
                    'message' => 'Selected user is already a member of this community'
                ]);
            }

            $pivot->status = 'active';
            $pivot->save();
            do_action('fluent_community/space/joined', $space, $userId, 'by_admin');

            if ($role != 'member') {
                do_action('fluent_community/space/member/role_updated', $space, $pivot);
            }

            return [
                'message' => 'Member approved'
            ];
        }

        $space->members()->attach($userId, [
            'role'   => $role,
            'status' => 'active'
        ]);

        $targetUser->cacheAccessSpaces();

        do_action('fluent_community/space/joined', $space, $userId, 'by_admin');

        return [
            'message' => 'User has been added to this community'
        ];
    }

    public function removeMember(Request $request, $slug)
    {
        $space = Space::where('slug', $slug)->first();

        if (!$space) {
            return $this->sendError([
                'message' => 'Space not found'
            ]);
        }

        $userId = $request->get('user_id');

        $admin = User::find(get_current_user_id());
        $admin->verifySpacePermission('can_remove_member', $space);

        $pivot = SpaceUserPivot::bySpace($space->id)
            ->byUser($userId)
            ->first();

        if (!$pivot) {
            return $this->sendError([
                'message' => 'Selected user is not a member of this community'
            ]);
        }

        $pivot->delete();

        $targetUser = User::find($userId);

        if ($targetUser) {
            $targetUser->cacheAccessSpaces();
        }

        do_action('fluent_community/space/user_left', $space, $userId, 'by_admin');

        return [
            'message' => __('User has been removed from this community', 'fluent-community')
        ];
    }

    public function getOtherUsers(Request $request)
    {
        $currentUser = $this->getUser(true);

        $this->validate($request->all(), [
            'space_id' => 'required|exists:fcom_spaces,id'
        ]);

        $isMod = $currentUser->isCommunityModerator() && current_user_can('list_users');

        $spaceId = $request->get('space_id');

        $selects = ['ID', 'display_name'];

        if ($isMod) {
            $selects[] = 'user_email';
        }

        $users = User::whereDoesntHave('spaces', function ($q) use ($spaceId) {
            return $q->where('space_id', $spaceId);
        })
            ->select($selects)
            ->searchBy($request->get('search'))
            ->paginate();

        return [
            'users' => $users
        ];
    }

    public function updateLinks(Request $request, $slug)
    {
        $space = Space::where('slug', $slug)->first();

        if (!$space) {
            return $this->sendError([
                'message' => 'Space not found'
            ]);
        }

        $space->verifyUserPermisson($this->getUser(), 'community_admin');

        $links = $request->get('links', []);

        $links = array_map(function ($link) {
            return CustomSanitizer::santizeLinkItem($link);
        }, $links);

        $settings = $space->settings;
        $settings['links'] = $links;
        $space->settings = $settings;
        $space->save();

        return [
            'message' => __('Links have been updated for the space', 'fluent-community'),
            'links'   => $links
        ];
    }

    public function getSpaceGroups(Request $request)
    {
        $user = $this->getUser(true);
        if (!$user->isCommunityModerator()) {
            return $this->sendError([
                'message' => 'You are not allowed to create space group'
            ]);
        }

        if ($request->get('options_only')) {
            $groups = SpaceGroup::orderBy('serial', 'ASC')
                ->select(['id', 'title'])
                ->get();
            return [
                'groups' => $groups
            ];
        }

        $user = $this->getUser();
        $groups = Helper::getAllCommunityGroups($user, false);

        foreach ($groups as $group) {
            foreach ($group->spaces as $space) {
                $space->permalink = $space->getPermalink();
            }
        }

        return [
            'groups' => $groups
        ];
    }

    public function createSpaceGroup(Request $request)
    {
        $user = $this->getUser(true);
        if (!$user->isCommunityModerator()) {
            return $this->sendError([
                'message' => 'You are not allowed to create space group'
            ]);
        }

        $data = $request->all();

        $this->validate($data, [
            'title' => 'required|unique:fcom_spaces,title',
            'slug'  => 'required|unique:fcom_spaces,slug'
        ]);


        $formattedData = [
            'title'       => sanitize_text_field($data['title']),
            'slug'        => sanitize_title($data['slug']),
            'description' => sanitize_textarea_field($data['description']),
            'status'      => 'active',
            'type'        => 'space_group',
            'settings'    => [
                'always_show_spaces' => Arr::get($data, 'settings.always_show_spaces', 'yes'),
            ],
            'serial'      => SpaceGroup::max('serial') + 1
        ];

        $group = SpaceGroup::create($formattedData);

        return [
            'message' => __('Space group has been created successfully', 'fluent-community'),
            'group'   => $group
        ];
    }

    public function updateSpaceGroup(Request $request, $groupId)
    {
        $user = $this->getUser();
        if (!$user || !$user->isCommunityModerator()) {
            return $this->sendError([
                'message' => 'You are not allowed to create space group'
            ]);
        }

        $group = SpaceGroup::findOrFail($groupId);
        $data = $request->all();

        $this->validate($data, [
            'title' => 'required'
        ]);

        $taken = BaseSpace::where('title', $data['title'])
            ->where('id', '!=', $group->id)
            ->first();

        if ($taken) {
            return $this->sendError([
                'message' => 'The title is already taken. Please use a different title'
            ]);
        }

        $formattedData = [
            'title'       => sanitize_text_field($data['title']),
            'description' => sanitize_textarea_field($data['description']),
            'status'      => 'active',
            'type'        => 'space_group',
            'settings'    => [
                'always_show_spaces' => Arr::get($data, 'settings.always_show_spaces', 'yes'),
            ]
        ];

        $group->fill($formattedData)->save();

        return [
            'message' => __('Space group has been created updated', 'fluent-community'),
            'group'   => $group
        ];
    }

    public function deleteSpaceGroup(Request $request, $groupId)
    {

        $user = $this->getUser();
        if (!$user || !$user->isCommunityModerator()) {
            return $this->sendError([
                'message' => 'You are not allowed to create space group'
            ]);
        }

        $group = SpaceGroup::findOrFail($groupId);

        if (!$group->spaces->isEmpty()) {
            return $this->sendError([
                'message' => 'You can not delete this group. It has spaces'
            ]);
        }

        $group->delete();

        return [
            'message' => __('Space group has been deleted successfully', 'fluent-community')
        ];
    }

    public function updateSpaceGroupIndexes(Request $request)
    {
        $indexes = $request->get('indexes', []);

        foreach ($indexes as $groupId => $indexNumber) {
            $group = SpaceGroup::findOrFail($groupId);
            $group->update([
                'serial' => $indexNumber + 1
            ]);
        }

        return [
            'message' => __('Space group indexes have been updated.', 'fluent-community')
        ];

    }

    public function updateSpaceIndexes(Request $request)
    {
        $indexes = $request->get('indexes', []);

        foreach ($indexes as $index => $spaceId) {
            $space = BaseSpace::withoutGlobalScopes()->findOrFail($spaceId);
            $space->update([
                'serial' => $index + 1
            ]);
        }

        return [
            'message' => __('Space indexes have been updated.', 'fluent-community')
        ];
    }

    public function moveSpace(Request $request)
    {
        $spaceId = $request->getSafe('space_id', 'intval');
        $groupId = $request->getSafe('group_id', 'intval');

        $space = BaseSpace::withoutGlobalScopes()->findOrFail($spaceId);
        $group = SpaceGroup::findOrFail($groupId);

        $space->update([
            'parent_id' => $groupId
        ]);

        return [
            'message' => __('Space has been moved successfully', 'fluent-community')
        ];
    }

    public function getLockScreenSettings(Request $request, $spaceSlug)
    {
        $space = Space::where('slug', $spaceSlug)->firstOrFail();
        $lockscreen = $space->getLockscreen();

        $lockscreen = apply_filters('fluent_community/get_lockscreen_settings', $lockscreen, $space);

        return [
            'lockscreen' => $lockscreen
        ];
    }
}
