<?php

namespace FluentCommunity\Modules\Mcp\Support;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Services\Helper;

defined('ABSPATH') || exit;

/**
 * MCP on/off state and who may connect. Permissions reuse
 * the community roles; each tool's REST route applies its own policy on top.
 */
class McpGate
{
    const OPTION_KEY = 'mcp_settings';

    const REQUIREMENTS = [ 'manager', 'moderator', 'site_admin', 'course_creator' ];

    /**
     * @return array{active: string}
     */
    public static function settings()
    {
        $settings = Utility::getOption(self::OPTION_KEY, []);

        if (!is_array($settings)) {
            $settings = [];
        }

        return [
            'active' => isset($settings['active']) && $settings['active'] === 'yes' ? 'yes' : 'no',
        ];
    }

    public static function isEnabled()
    {
        return self::settings()['active'] === 'yes';
    }

    /**
     * The FluentHub toggle leaves authorization to the caller, so it is checked here.
     *
     * @param bool $enabled
     * @return bool The new state, or false when the user may not change it.
     */
    public static function setEnabled($enabled)
    {
        if (!Helper::isSiteAdmin()) {
            return false;
        }

        $settings = self::settings();
        $settings['active'] = $enabled ? 'yes' : 'no';
        Utility::updateOption(self::OPTION_KEY, $settings);

        return (bool) $enabled;
    }

    /**
     * Runs on every MCP request.
     *
     * @param mixed $request
     * @return true|\WP_Error
     */
    public static function transport($request = null) // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the adapter passes the request
    {
        if (!self::isEnabled()) {
            return new \WP_Error('fluent_community_mcp_disabled', 'The FluentCommunity MCP server is disabled.');
        }

        if (!is_user_logged_in()) {
            return new \WP_Error('fluent_community_mcp_unauthorized', 'Authentication is required to use the FluentCommunity MCP server.');
        }

        if (!self::allows('manager')) {
            return new \WP_Error('fluent_community_mcp_forbidden', 'Only community managers can use the FluentCommunity MCP server.');
        }

        return true;
    }

    /**
     * @param string $requirement One of self::REQUIREMENTS.
     * @param \FluentCommunity\App\Models\User|null $user Null uses the current user.
     * @return bool
     */
    public static function allows($requirement, $user = null)
    {
        if (!$user) {
            $user = Helper::getCurrentUser();
        }

        if (!$user || !self::profileIsActive($user)) {
            return false;
        }

        $isSiteAdmin = Helper::isSiteAdmin($user->ID, $user);

        if ($requirement === 'site_admin') {
            return $isSiteAdmin;
        }

        if ($requirement === 'moderator') {
            return $isSiteAdmin || $user->hasCommunityModeratorAccess();
        }

        if ($requirement === 'course_creator') {
            return $isSiteAdmin || $user->hasCourseCreatorAccess();
        }

        return $isSiteAdmin || (bool) array_filter($user->getCommunityRoles());
    }

    /**
     * A blocked or pending profile keeps its community roles, but PortalPolicy refuses it on
     * every route. The gate refuses it too, so tools that also read directly stay closed.
     *
     * @param \FluentCommunity\App\Models\User $user
     * @return bool
     */
    private static function profileIsActive($user)
    {
        $profile = $user->xprofile;

        return !$profile || $profile->status === 'active';
    }
}
