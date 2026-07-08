<?php

namespace FluentCommunity\App\Http\Policies;

use FluentCommunity\App\Models\User;
use FluentCommunity\App\Models\Space;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\Framework\Http\Request\Request;

class SpacePolicy extends BasePolicy
{
    /**
     * Check user permission for any method
     * @param \FluentCommunity\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        $xProfile = Helper::getCurrentProfile(true);
        if ($xProfile && $xProfile->status != 'active') {
            return false;
        }

        $userId = get_current_user_id();

        if ($request->getMethod() == 'GET') {
            return !!Helper::canAccessPortal($userId);
        }

        if (!$userId) {
            return false;
        }

        return $this->canManageCommunity($request);
    }

    public function join(Request $request)
    {
        return !!get_current_user_id();
    }

    public function leave(Request $request)
    {
        return !!get_current_user_id();
    }

    public function create(Request $request)
    {
        return $this->canManageCommunity($request, null);
    }

    public function patchBySlug(Request $request, $slug)
    {
        return $this->canManageCommunity($request, Space::where('slug', $slug)->first());
    }

    public function patchById(Request $request, $id)
    {
        return $this->canManageCommunity($request, Space::find($id));
    }

    public function updateLinks(Request $request, $slug)
    {
        return $this->canManageCommunity($request, Space::where('slug', $slug)->first());
    }

    public function deleteBySlug(Request $request, $slug)
    {
        return $this->canManageCommunity($request, Space::where('slug', $slug)->first());
    }

    public function deleteById(Request $request, $id)
    {
        return $this->canManageCommunity($request, Space::find($id));
    }

    public function addMember(Request $request, $slug)
    {
        $space = Space::where('slug', $slug)->first();

        $user = User::find(get_current_user_id());

        return $user && $user->verifySpacePermission('can_add_member', $space);
    }

    public function removeMember(Request $request, $slug)
    {
        $space = Space::where('slug', $slug)->first();

        $user = User::find(get_current_user_id());

        return $user && $user->verifySpacePermission('can_remove_member', $space);
    }

    public function getSpaceGroups(Request $request)
    {
        if ($request->get('options_only')) {
            return true;
        }

        return $this->canManageSpace($request, null);
    }

    public function createSpaceGroup(Request $request)
    {
        return $this->canManageSpace($request, null);
    }

    public function updateSpaceGroup(Request $request)
    {
        return $this->canManageSpace($request, null);
    }

    public function deleteSpaceGroup(Request $request)
    {
        return $this->canManageSpace($request, null);
    }

    public function updateSpaceGroupIndexes(Request $request)
    {
        return $this->canManageSpace($request, null);
    }

    public function updateSpaceIndexes(Request $request)
    {
        return $this->canManageSpace($request, null);
    }

    public function moveSpace(Request $request)
    {
        return $this->canManageCommunity($request);
    }

    public function getMetaSettings(Request $request, $spaceSlug)
    {
        return $this->canManageSpace($request, Space::where('slug', $spaceSlug)->first());
    }

    protected function canManageCommunity(Request $request, $space = false)
    {
        $user = User::find(get_current_user_id());

        if (!$user) {
            return false;
        }

        if ($user->hasCommunityAdminAccess()) {
            return true;
        }

        if ($space === false) {
            $space = Space::find($request->get('space_id'));
        }

        return $space && $user->getSpaceRole($space) === 'admin';
    }

    protected function canManageSpace(Request $request, $space = false)
    {
        $user = User::find(get_current_user_id());

        if (!$user) {
            return false;
        }

        if ($user->hasSpaceManageAccess()) {
            return true;
        }

        if ($space === false) {
            $space = Space::find($request->get('space_id'));
        }

        return $space && $user->getSpaceRole($space) === 'admin';
    }
}

