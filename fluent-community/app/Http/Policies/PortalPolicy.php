<?php

namespace FluentCommunity\App\Http\Policies;

use FluentCommunity\App\Services\Helper;
use FluentCommunity\Framework\Http\Request\Request;

class PortalPolicy extends BasePolicy
{
    /**
     * Check user permission for any method
     * @param \FluentCommunity\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        $method = $request->getMethod();
        $userId = get_current_user_id();

        $xProfile = Helper::getCurrentProfile(true);
        if($xProfile && $xProfile->status != 'active') {
            return false;
        }

        if ($method != 'GET' && !$userId) {
            return false;
        }

        return !!Helper::canAccessPortal($userId);
    }

    public function getBookmarks(Request $request)
    {
        return is_user_logged_in() && $this->verifyRequest($request);
    }
}
