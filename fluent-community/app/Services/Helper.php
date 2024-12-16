<?php

namespace FluentCommunity\App\Services;

use FluentCommunity\App\App;
use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Hooks\Handlers\ActivationHandler;
use FluentCommunity\App\Models\BaseSpace;
use FluentCommunity\App\Models\Space;
use FluentCommunity\App\Models\Media;
use FluentCommunity\App\Models\Meta;
use FluentCommunity\App\Models\SpaceUserPivot;
use FluentCommunity\App\Models\User;
use FluentCommunity\App\Models\XProfile;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\App\Models\SpaceGroup;

/**
 * Helper class for various utility functions.
 */
class Helper
{

    public static function isRtl()
    {
        return apply_filters('fluent_community/is_rtl', is_rtl());
    }

    /**
     * Get the portal slug.
     *
     * @return string The portal slug.
     */
    /**
     * Get the portal slug.
     *
     * @return string The portal slug.
     */
    public static function getPortalSlug($forRoute = false)
    {
        $settings = get_option('fluent_community_settings', []);
        if (isset($settings['slug'])) {
            $slug = $settings['slug'];
        } else {
            $slug = 'portal';
        }

        if (defined('FLUENT_COMMUNITY_PORTAL_SLUG')) {
            $slug = \FLUENT_COMMUNITY_PORTAL_SLUG;
        }

        $slug = apply_filters('fluent_community/portal_slug', $slug);

        if (!$forRoute) {
            return $slug;
        }

        $siteUrl = get_site_url();

        $poralUrl = self::baseUrl('/');

        $urlPath = parse_url($siteUrl, PHP_URL_PATH);

        if ($urlPath) {
            // get the url without path
            $siteUrl = str_replace($urlPath, '', $siteUrl);
        }

        $slug = str_replace($siteUrl, '', $poralUrl);
        // remove the first and last slashes
        return trim($slug, '/');
    }

    /**
     * Get the portal route type.
     *
     * @return string The portal route type.
     */
    public static function getPortalRouteType()
    {
        return apply_filters('fluent_community/portal_route_type', 'WebHistory');
    }

    /**
     * Check if the portal is headless.
     *
     * @return bool True if headless, false otherwise.
     */
    public static function isHeadless()
    {
        return apply_filters('fluent_community/portal_page_headless', false);
    }

    /**
     * Check if the portal has a color scheme.
     *
     * @return bool True if has color scheme, false otherwise.
     */
    public static function hasColorScheme()
    {
        $status = Utility::isCustomizationEnabled('dark_mode');
        return apply_filters('fluent_community/has_color_scheme', $status);
    }

    /**
     * Check if the user is a site admin.
     *
     * @param int|null $userId The user ID to check. If null, checks the current user.
     * @return bool True if the user is a site admin, false otherwise.
     */
    public static function isSiteAdmin($userId = null)
    {
        $capability = apply_filters('fluent_community/super_admin_capability', 'manage_options');

        if (!$capability) {
            return false;
        }

        if ($userId === null) {
            $userId = get_current_user_id();
        }

        if (!$userId) {
            return false;
        }

        return user_can($userId, $capability);
    }

    public static function isModerator($user = null)
    {
        if (!$user) {
            $user = self::getCurrentUser();
        }
        return $user && $user->isCommunityModerator();
    }

    /**
     * Get the URL for an asset file.
     *
     * @param string $file The file name.
     * @return string The full URL to the asset.
     */
    public static function assetUrl($file = '')
    {
        return FLUENT_COMMUNITY_PLUGIN_URL . 'assets/' . $file;
    }

    /**
     * Get the base URL for the portal.
     *
     * @param string $path The path to append to the base URL.
     * @return string The full base URL.
     */
    public static function baseUrl($path = '')
    {
        $baseUrl = apply_filters('fluent_community/base_url', home_url(self::getPortalSlug()));
        $baseUrl = rtrim($baseUrl, '/');

        if (self::getPortalRouteType() != 'hash') {
            return $baseUrl . '/' . ltrim($path, '/');
        }

        if (!$path) {
            return $baseUrl . '/';
        }

        return $baseUrl . '/#/' . ltrim($path, '/');
    }

    public static function getAuthUrl()
    {
        $settings = self::generalSettings();

        return Arr::get($settings, 'cutsom_auth_url', '');
    }

    /**
     * Get the space IDs for a user.
     *
     * @param int|null $userId The user ID. If null, uses the current user.
     * @return array An array of space IDs.
     */
    public static function getUserSpaceIds($userId = null)
    {
        if (!$userId) {
            $userId = get_current_user_id();
        }

        return SpaceUserPivot::where('user_id', $userId)
            ->where('status', 'active')
            ->pluck('space_id')
            ->toArray();
    }

    public static function getUserSpaces($userId = null)
    {
        if (!$userId) {
            $userId = get_current_user_id();
        }

        return Space::whereHas('members', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })->get();
    }

    /**
     * Check if a user is in a specific space.
     *
     * @param int $userId The user ID.
     * @param int $spaceId The space ID.
     * @return bool True if the user is in the space, false otherwise.
     */
    public static function isUserInSpace($userId, $spaceId)
    {
        return SpaceUserPivot::where('user_id', $userId)
            ->where('space_id', $spaceId)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Generate HTML attributes from an array.
     *
     * @param array $atts An array of attribute key-value pairs.
     * @return string The generated HTML attributes string.
     */
    public static function attrs($atts = [])
    {
        $text = '';

        foreach ($atts as $key => $value) {
            $text .= "$key=\"$value\" ";
        }

        return $text;
    }

    /**
     * Get media from a URL.
     *
     * @param string|array $url The URL or an array containing URL information.
     * @return Media|null The Media object if found, null otherwise.
     */
    public static function getMediaFromUrl($url)
    {
        if (is_array($url) && isset($url['provider'])) {
            $provider = Arr::get($url, 'provider');

            if ($provider == 'giphy') {
                return null;
            }

            $url = Arr::get($url, 'url');
        }

        if (!$url) {
            return null;
        }

        $parsedUrl = wp_parse_url($url, PHP_URL_QUERY);

        if (!$parsedUrl) {
            return null;
        }

        // Parse the query string to get the media_key value
        parse_str($parsedUrl, $queryParams);

        $key = Arr::get($queryParams, 'media_key');

        if (!$key) {
            return null;
        }

        return Media::where('media_key', $key)->first();
    }

    /**
     * Get media items from multiple URLs.
     *
     * @param array $urls An array of URLs.
     * @return array An array of Media objects.
     */
    public static function getMediaItemsFromUrl($urls)
    {
        $mediaItems = [];

        foreach ($urls as $url) {
            $media = self::getMediaFromUrl($url);

            if ($media) {
                $mediaItems[] = $media;
            }
        }

        return $mediaItems;
    }

    /**
     * Get general settings for the community.
     *
     * @param bool $cached Whether to use cached settings.
     * @return array The general settings.
     */
    public static function generalSettings($cached = true)
    {
        static $settings = null;

        if ($cached && $settings) {
            return $settings;
        }

        $settings = get_option('fluent_community_settings', []);

        $defaults = [
            'site_title'              => get_bloginfo('name'),
            'slug'                    => 'portal',
            'logo'                    => '',
            'white_logo'              => '',
            'featured_image'          => '',
            'access'                  => [
                'acess_level'  => 'public', // logged_in, public, role_based
                'access_roles' => []
            ],
            'auth_form_type'          => 'default',
            'explicit_registration'   => 'no',
            'disable_global_posts'    => 'yes',
            'auth_content'            => 'Please login first to access this page',
            'auth_redirect'           => '',
            'restricted_role_content' => 'Sorry, you can not access to this page. Only authorized users can access this page.',
            'auth_url'                => '',
            'cutsom_auth_url'         => self::baseUrl('?fcom_action=auth')
        ];

        $settings = wp_parse_args($settings, $defaults);
        if ($settings['auth_form_type'] != 'custom' || empty($settings['auth_form_type'])) {
            $settings['cutsom_auth_url'] = self::baseUrl('?fcom_action=auth');
        }

        if (defined('FLUENT_COMMUNITY_PORTAL_SLUG')) {
            $settings['slug'] = \FLUENT_COMMUNITY_PORTAL_SLUG;
            $settings['is_slug_defined'] = true;
        } else {
            unset($settings['is_slug_defined']);
        }

        return $settings;
    }

    public static function hasGlobalPost()
    {
        $settings = self::generalSettings();
        $status = Arr::get($settings, 'disable_global_posts', '') != 'yes';

        return apply_filters('fluent_community/has_global_post', $status);
    }

    /**
     * Check if a user can access the portal.
     *
     * @param int|null $userId The user ID. If null, uses the current user.
     * @return bool True if the user can access the portal, false otherwise.
     */
    public static function canAccessPortal($userId = null)
    {
        $settings = self::generalSettings();
        $accessLevel = Arr::get($settings, 'access.acess_level');

        if ($accessLevel == 'public') {
            return apply_filters('fluent_community/can_access_portal', true);
        }

        if (!$userId) {
            $userId = get_current_user_id();
        }

        if (!$userId) {
            return apply_filters('fluent_community/can_access_portal', false);
        }

        if ($accessLevel == 'logged_in') {
            return apply_filters('fluent_community/can_access_portal', true);
        }

        if (user_can($userId, 'edit_pages')) {
            return apply_filters('fluent_community/can_access_portal', true);
        }

        $roles = Arr::get($settings, 'access.access_roles', []);

        $user = get_user_by('ID', $userId);

        if (!$user) {
            return apply_filters('fluent_community/can_access_portal', false);
        }

        $result = !!array_intersect(array_values($user->roles), $roles);

        if (!$result) {
            return apply_filters('fluent_community/can_access_portal', false);
        }

        $xProfile = Helper::getCurrentProfile();

        $result = $xProfile && $xProfile->status == 'active';

        return apply_filters('fluent_community/can_access_portal', $result);
    }

    /**
     * Get the portal route paths.
     *
     * @return array An array of portal route paths.
     */
    public static function portalRoutePaths()
    {
        return apply_filters('fluent_community/app_route_paths', [
            'portal_home',
            'members',
            'bookmarks',
            'chat',
            'courses',
            'dashboard',
            'leaderboards',
            'notifications',
            'space',
            'discover',
            'courses',
            'u',
            'post',
            'admin',
            'course'
        ]);
    }

    /**
     * Get the current user's profile.
     *
     * @param bool $cached Whether to use cached profile.
     * @return XProfile|null The user's profile or null if not found.
     */
    public static function getCurrentProfile($cached = true)
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return null;
        }

        static $profile;

        if ($profile && $cached) {
            return $profile;
        }

        if (!$userId) {
            return null;
        }

        $profile = XProfile::where('user_id', $userId)->first();

        return $profile;
    }

    /**
     * Get the current user Model.
     *
     * @param bool $cached Whether to use cached user.
     * @return User|false The User model or false if not found.
     */
    public static function getCurrentUser($cached = true)
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return false;
        }

        static $user;
        if ($user && $cached) {
            return $user;
        }

        $user = User::find($userId);

        return $user;
    }

    /**
     * Get the route paths for the community.
     *
     * @return array An array of route paths.
     */
    private static function getRoutePaths()
    {
        return [
            'dashboard'          => '/dashboard',
            'all_feeds'          => '/',
            'single_feed'        => '/post/:feed_slug',
            'space_feeds'        => '/space/:space/home',
            'space_feed'         => '/space/:space/post/:feed_slug',
            'space_members'      => '/space/:space/members',
            'spaces'             => '/discover/spaces',
            'settings'           => '/admin/settings',
            'admin_moderators'   => '/admin/settings/moderators',
            'all_members'        => '/members',
            'user_profile'       => '/u/:username/',
            'user_communities'   => '/u/:username/spaces',
            'update_profile'     => '/u/:username/update',
            'discussions'        => '/discussions',
            'create_topic'       => '/discussions/create-topic',
            'topic'              => '/discussions/topic/:slug',
            'notifications'      => '/notifications',
            'bookmarks'          => '/bookmarks',
            'courses'            => '/courses',
            'view_course'        => '/courses/view/:course_id/lessons',
            'view_lesson'        => '/courses/view/:course_id/lessons/:lesson_slug/view',
            'manage_courses'     => '/admin/manage-courses',
            'edit_lessons'       => '/admin/manage-courses/edit/:course_id/lessons',
            'course_students'    => '/admin/manage-courses/edit/:course_id/students',
            'course_overview'    => '/admin/manage-courses/edit/:course_id/overview',
            'manage_leaderboard' => '/admin/manage-leaderboard',
        ];
    }

    /**
     * Get the URL for a JavaScript route.
     *
     * @param array $route The route information.
     * @return string The URL for the route.
     */
    public static function getUrlByJsRoute($route = [])
    {
        $routePaths = self::getRoutePaths();

        $routeName = Arr::get($route, 'name', '');

        if (!$routeName || !isset($routePaths[$routeName])) {
            return self::baseUrl();
        }

        $path = $routePaths[$routeName];

        $params = (array)Arr::get($route, 'params', []);

        if (!$params) {
            return self::baseUrl($path);
        }

        $replaces = [];

        foreach ($params as $paramKey => $paramValue) {
            $replaces[':' . $paramKey] = $paramValue;
        }

        $path = str_replace(array_keys($replaces), array_values($replaces), $path);

        return self::baseUrl($path);

    }

    /**
     * Get the route name from a request path.
     *
     * @param string $path The request path.
     * @return string|false The route name or false if not found.
     */
    public static function getRouteNameByRequestPath($path)
    {
        $path = '//' . $path;

        if (strpos($path, '/u/')) {
            return 'user_profile';
        }

        if (strpos($path, '/post/')) {
            return 'feed_view';
        }

        if (strpos($path, '/lessons/')) {
            return 'lesson_view';
        }

        if (strpos($path, '/course/')) {
            return 'course_view';
        }

        if (strpos($path, '/space/') && !strpos($path, '/discover/spaces')) {
            return 'community_view';
        }

        if (strpos($path, '/admin')) {
            return 'admin';
        }

        return false;
    }

    /**
     * Get a human-readable excerpt from content.
     *
     * @param string $content The content to extract from.
     * @param int $length The maximum length of the excerpt.
     * @return string The human-readable excerpt.
     */
    public static function getHumanExcerpt($content, $length = 100)
    {
        if ($content) {
            $patterns = [
                '/^#{1,6}\s+/m'                         => '',
                // Bold and Italic: remove '*' and '_' symbols
                '/(\*\*|__)(.*?)\1/'                    => '$2',
                '/(\*|_)(.*?)\1/'                       => '$2',
                // Code blocks: remove triple backticks
                '/^```\s*\w*\s*\n([\s\S]*?)\n```\s*$/m' => '$1',
                // Inline code: remove single backticks
                '/`([^`]+)`/'                           => '$1',
                // Blockquotes: remove '>' symbol
                '/^\s*>\s?/m'                           => '',
                // Horizontal rules: replace with empty line
                '/^\s*([-*_])\1{2,}\s*$/m'              => "\n",
                // Links: keep only the link text
                '/\[([^\]]+)\]\([^\)]+\)/'              => '$1',
                // Images: keep only the alt text
                '/!\[([^\]]+)\]\([^\)]+\)/'             => '$1',
                // Strikethrough: remove '~~' symbols
                '/~~(.*?)~~/'                           => '$1',
                // Task lists: remove checkbox syntax
                '/^\s*[-*+]\s+\[[ xX]\]\s+/m'           => '',
            ];

            $content = preg_replace(array_keys($patterns), array_values($patterns), $content);

            // remove all tags
            $content = wp_strip_all_tags($content);
            // remove new lines and tabs
            $content = str_replace(["\r", "\n", "\t"], ' ', $content);
            // remove multiple spaces
            $content = preg_replace('/\s+/', ' ', $content);

            // trim
            $content = trim($content);
        }

        if (!$content) {
            return '';
        }

        if (mb_strlen($content) <= $length) {
            return $content;
        }

        // return the first $length chars of the content with ... at the end
        return mb_substr($content, 0, $length) . '...';
    }

    /**
     * Check if the portal is publicly accessible.
     *
     * @return bool True if publicly accessible, false otherwise.
     */
    public static function isPublicAccessible()
    {
        $settings = self::generalSettings();
        return Arr::get($settings, 'access.acess_level') == 'public';
    }

    /**
     * Get media by provider.
     *
     * @param array $images The array of images.
     * @param string $provider The provider to filter by.
     * @return array The filtered array of images.
     */
    public static function getMediaByProvider($images, $provider = 'uploader')
    {
        if (is_array($images)) {
            return array_filter($images, function ($image) use ($provider) {
                if (is_array($image)) {
                    if (isset($image['provider'])) {
                        return Arr::get($image, 'provider') == $provider;
                    }

                    return $provider === 'uploader'; // for existing images when no provider was set.
                }
            });
        }

        return [];
    }

    /**
     * Get the community menu groups.
     *
     * @param User|null $user The user to get menu groups for.
     * @return array The community menu groups.
     */
    public static function getCommunityMenuGroups($user = null)
    {
        if (!$user) {
            $user = self::getCurrentUser();
        }

        $communityGroups = self::getAllCommunityGroups($user);

        if ($communityGroups->isEmpty()) {
            return [];
        }

        $isComModerator = $user && $user->hasCommunityModeratorAccess();
        $isCourseCreator = $user && $user->hasCourseCreatorAccess();

        $formattedGroups = [];

        foreach ($communityGroups as $communityGroup) {
            $spaces = $communityGroup->spaces;
            $validSpaces = [];
            $isShowAll = Arr::get($communityGroup->settings, 'always_show_spaces') === 'yes';

            foreach ($spaces as $space) {
                if ($isComModerator && $space->type != 'course') {
                    $validSpaces[] = self::transformSpaceToLink($space);
                    continue;
                }

                if ($isCourseCreator && $space->type == 'course') {
                    $validSpaces[] = self::transformSpaceToLink($space);
                    continue;
                }

                if ($space->privacy === 'secret') {
                    if (!$user || !$space->getMembership($user->ID)) {
                        continue;
                    }
                    $validSpaces[] = self::transformSpaceToLink($space);
                    continue;
                }

                if ($isShowAll || $space->privacy = 'public') {
                    $validSpace = self::transformSpaceToLink($space);

                    if ($space->privacy == 'private') {
                        if (!$user || !$space->getMembership($user->ID)) {
                            $validSpace['show_lock'] = true;
                        }
                    }

                    $validSpaces[] = $validSpace;
                    continue;
                }

                if (!$user || $space->getMembership($user->ID)) {
                    continue;
                }

                $validSpaces[] = self::transformSpaceToLink($space);
            }

            if (!$validSpaces && !$isComModerator && !$isCourseCreator) {
                continue;
            }

            $formattedGroups[] = [
                'id'       => $communityGroup->id,
                'title'    => $communityGroup->title,
                'slug'     => $communityGroup->slug,
                'logo'     => $communityGroup->logo,
                'children' => $validSpaces
            ];
        }

        return apply_filters('fluent_community/menu_groups_for_user', $formattedGroups, $user);
    }

    /**
     * Transform a space to a link array.
     *
     * @param Space $space The space to transform.
     * @return array The transformed space link array.
     */
    private static function transformSpaceToLink($space)
    {

        $logo = $space->logo;

        $title = $space->title;

        if ($space->status == 'draft') {
            $title = $title . ' ' . __('(Draft)', 'fluent-community');
        }

        return [
            'title'        => $title,
            'icon_image'   => $logo,
            'shape_svg'    => !$logo ? Arr::get($space->settings, 'shape_svg', '') : '',
            'emoji'        => !$logo ? Arr::get($space->settings, 'emoji', '') : '',
            'permalink'    => $space->getPermalink(),
            'link_classes' => 'space_menu_item route_url fcom_space_id_' . $space->id . ' fcom_space_' . $space->slug
        ];
    }

    public static function isAlreadyOnboarded()
    {
        $communitySettings = get_option('fluent_community_settings', []);

        return !empty($communitySettings);
    }

    /**
     * Get all community groups.
     *
     * @param User $user The user to get groups for.
     * @param bool $willCreate Whether to create a default group if none exist.
     * @return \FluentCommunity\Framework\Support\Collection Collection of Groups
     */
    public static function getAllCommunityGroups($user, $willCreate = true)
    {
        $isModerator = $user && $user->isCommunityModerator();

        $communityGroups = SpaceGroup::query()->orderBy('serial', 'ASC')
            ->with([
                'spaces' => function ($query) use ($isModerator) {
                    if ($isModerator) {
                        $query->orderBy('serial', 'ASC');
                    } else {
                        $query->where('status', 'published')
                            ->orderBy('serial', 'ASC');
                    }
                }
            ])
            ->get();

        if ($communityGroups->isEmpty() && $willCreate) {
            $createdSpace = (new ActivationHandler(App::make()))->maybeCreateDefaultSpaceGroup();

            if ($createdSpace) {
                Space::where('type', 'community')->update([
                    'parent_id' => $createdSpace->id
                ]);
            }

            return self::getAllCommunityGroups($user, false);
        }

        return $communityGroups;
    }

    /**
     * Check if a feature is enabled.
     *
     * @param string $feature The feature to check.
     * @return bool True if the feature is enabled, false otherwise.
     */
    public static function isFeatureEnabled($feature)
    {
        $features = Utility::getFeaturesConfig();

        return isset($features[$feature]) && $features[$feature] === 'yes';
    }

    /**
     * Get the menu items group.
     *
     * @param string $context The context for getting menu items.
     * @return array The menu items group.
     */
    public static function getMenuItemsGroup($context = 'view')
    {
        static $menuGroups;

        if ($menuGroups && $context === 'view') {
            return $menuGroups;
        }

        $menuGroups = Utility::getOption('fluent_community_menu_groups', []);

        $membersPageStatus = Utility::canViewMembersPage() ? 'yes' : 'no';

        $leaderboardPageVisibility = (Utility::canViewLeaderboardMembers() || is_user_logged_in()) ? 'yes' : 'no';

        $defaultMainMenuItems = [
            'all_feeds'   => [
                'slug'         => 'all_feeds',
                'title'        => __('Feed', 'fluent-community'),
                'is_system'    => 'yes',
                'is_locked'    => 'yes',
                'enabled'      => 'yes',
                'permalink'    => self::baseUrl('/'),
                'link_classes' => 'fcom_dashboard route_url',
                'shape_svg'    => '<svg width="20" height="18" viewBox="0 0 20 18" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M10 13.166H10.0075H10Z" fill="currentColor"/><path d="M10 13.166H10.0075" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M16.6666 6.08301V10.2497C16.6666 13.3924 16.6666 14.9637 15.6903 15.94C14.714 16.9163 13.1426 16.9163 9.99992 16.9163C6.85722 16.9163 5.28587 16.9163 4.30956 15.94C3.33325 14.9637 3.33325 13.3924 3.33325 10.2497V6.08301" stroke="currentColor" stroke-width="1.5"/><path d="M18.3333 7.74967L14.714 4.27925C12.4918 2.14842 11.3807 1.08301 9.99996 1.08301C8.61925 1.08301 7.50814 2.14842 5.28592 4.27924L1.66663 7.74967" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>'
            ],
            'spaces'      => [
                'slug'         => 'spaces',
                'title'        => __('Spaces', 'fluent-community'),
                'is_system'    => 'yes',
                'is_locked'    => 'yes',
                'enabled'      => 'yes',
                'permalink'    => self::baseUrl('discover/spaces'),
                'link_classes' => 'fcom_spaces route_url',
                'shape_svg'    => '<svg version="1.1" viewBox="0 0 128 128" xml:space="preserve"><g><path d="M64,42c-13.2,0-24,10.8-24,24s10.8,24,24,24s24-10.8,24-24S77.2,42,64,42z M64,82c-8.8,0-16-7.2-16-16s7.2-16,16-16   s16,7.2,16,16S72.8,82,64,82z"/><path d="M64,100.8c-14.9,0-29.2,6.2-39.4,17.1l-2.7,2.9l5.8,5.5l2.7-2.9c8.8-9.4,20.7-14.6,33.6-14.6s24.8,5.2,33.6,14.6l2.7,2.9   l5.8-5.5l-2.7-2.9C93.2,107.1,78.9,100.8,64,100.8z"/><path d="M97,47.9v8c9.4,0,18.1,3.8,24.6,10.7l5.8-5.5C119.6,52.7,108.5,47.9,97,47.9z"/><path d="M116.1,20c0-10.5-8.6-19.1-19.1-19.1S77.9,9.5,77.9,20S86.5,39.1,97,39.1S116.1,30.5,116.1,20z M85.9,20   c0-6.1,5-11.1,11.1-11.1s11.1,5,11.1,11.1s-5,11.1-11.1,11.1S85.9,26.1,85.9,20z"/><path d="M31,47.9c-11.5,0-22.6,4.8-30.4,13.2l5.8,5.5c6.4-6.9,15.2-10.7,24.6-10.7V47.9z"/><path d="M50.1,20C50.1,9.5,41.5,0.9,31,0.9S11.9,9.5,11.9,20S20.5,39.1,31,39.1S50.1,30.5,50.1,20z M31,31.1   c-6.1,0-11.1-5-11.1-11.1S24.9,8.9,31,8.9s11.1,5,11.1,11.1S37.1,31.1,31,31.1z"/></g></svg>'
            ],
            'all_courses' => [
                'slug'           => 'all_courses',
                'title'          => 'Courses',
                'link_classes'   => 'fcom_courses route_url',
                'is_system'      => 'yes',
                'is_locked'      => 'yes',
                'enabled'        => 'yes',
                'is_unavailable' => self::isFeatureEnabled('course_module') ? 'no' : 'yes',
                'permalink'      => self::baseUrl('courses'),
                'shape_svg'      => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M10.734 5.84746L14.7114 6.9072M9.88139 9.01146L11.8701 9.54132M9.98031 14.9723L10.7758 15.1843C13.0258 15.7838 14.1508 16.0835 15.037 15.5747C15.9233 15.0659 16.2247 13.9473 16.8276 11.71L17.6802 8.54599C18.2831 6.3087 18.5845 5.19006 18.0728 4.30879C17.5611 3.42752 16.4362 3.12778 14.1862 2.52831L13.3907 2.31636C11.1407 1.71688 10.0157 1.41714 9.12948 1.92594C8.24322 2.43474 7.94178 3.55338 7.3389 5.79067L6.4863 8.95466C5.88342 11.1919 5.58198 12.3106 6.09367 13.1919C6.60536 14.0731 7.73034 14.3729 9.98031 14.9723Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M9.99996 17.4559L9.20634 17.672C6.96165 18.2832 5.83931 18.5889 4.95512 18.0701C4.07093 17.5513 3.7702 16.4107 3.16874 14.1295L2.31814 10.9035C1.71668 8.62232 1.41595 7.48174 1.92643 6.58318C2.36802 5.80591 3.33329 5.83421 4.58329 5.83411" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>'
            ],
            'all_members' => [
                'slug'           => 'all_members',
                'title'          => __('Members', 'fluent-community'),
                'is_system'      => 'yes',
                'is_locked'      => 'yes',
                'is_unavailable' => $membersPageStatus == 'yes' ? 'no' : 'yes',
                'enabled'        => $membersPageStatus,
                'permalink'      => self::baseUrl('members'),
                'link_classes'   => 'fcom_all_members route_url',
                'shape_svg'      => '<svg width="20" height="16" viewBox="0 0 20 16" fill="none"><path d="M17.3116 13C17.936 13 18.4327 12.6071 18.8786 12.0576C19.7915 10.9329 18.2927 10.034 17.721 9.59383C17.1399 9.14635 16.4911 8.89285 15.8332 8.83333M14.9999 7.16667C16.1505 7.16667 17.0832 6.23393 17.0832 5.08333C17.0832 3.93274 16.1505 3 14.9999 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M2.68822 13C2.0638 13 1.56714 12.6071 1.12121 12.0576C0.208326 10.9329 1.70714 10.034 2.27879 9.59383C2.8599 9.14635 3.50874 8.89285 4.16659 8.83333M4.58325 7.16667C3.43266 7.16667 2.49992 6.23393 2.49992 5.08333C2.49992 3.93274 3.43266 3 4.58325 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M6.73642 10.592C5.88494 11.1185 3.65241 12.1936 5.01217 13.5389C5.6764 14.196 6.41619 14.666 7.34627 14.666H12.6536C13.5837 14.666 14.3234 14.196 14.9877 13.5389C16.3474 12.1936 14.1149 11.1185 13.2634 10.592C11.2667 9.35735 8.73313 9.35735 6.73642 10.592Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M12.9166 4.24967C12.9166 5.86051 11.6107 7.16634 9.99992 7.16634C8.38909 7.16634 7.08325 5.86051 7.08325 4.24967C7.08325 2.63884 8.38909 1.33301 9.99992 1.33301C11.6107 1.33301 12.9166 2.63884 12.9166 4.24967Z" stroke="currentColor" stroke-width="1.5"/></svg>',
            ],
            'leaderboard' => [
                'slug'           => 'leaderboard',
                'is_system'      => 'yes',
                'is_locked'      => 'yes',
                'enabled'        => $leaderboardPageVisibility,
                'is_unavailable' => self::isFeatureEnabled('leader_board_module') && $leaderboardPageVisibility == 'yes' ? 'no' : 'yes',
                'title'          => __('Leaderboard', 'fluent-community'),
                'link_classes'   => 'fcom_leaderboards route_url',
                'permalink'      => self::baseUrl('leaderboards'),
                'shape_svg'      => '<svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="currentColor"><path d="M160-200h160v-320H160v320Zm240 0h160v-560H400v560Zm240 0h160v-240H640v240ZM80-120v-480h240v-240h320v320h240v400H80Z"/></svg>'
            ]
        ];

        $mainItems = Arr::get($menuGroups, 'mainMenuItems', []);

        if ($mainItems && is_array($mainItems)) {
            if (isset($mainItems['all_communities'])) {
                $mainItems['spaces'] = $defaultMainMenuItems['spaces'];
                unset($mainItems['all_communities']);
            }

            foreach ($mainItems as $index => &$item) {
                if (empty($item['slug'])) {
                    unset($mainItems[$index]);
                    continue;
                }
                $defaultItem = Arr::get($defaultMainMenuItems, $item['slug'], []);
                if ($defaultItem) {
                    $preservedKeys = ['is_system', 'is_locked', 'is_unavailable', 'slug'];
                    foreach ($preservedKeys as $key) {
                        if (isset($defaultItem[$key])) {
                            $item[$key] = Arr::get($defaultItem, $key);
                        }
                    }
                    if (Arr::get($defaultItem, 'is_system') === 'yes') {
                        $item['permalink'] = $defaultItem['permalink'];
                        $item['link_classes'] = $defaultItem['link_classes'];
                        if (empty($item['shape_svg'])) {
                            $item['shape_svg'] = $defaultItem['shape_svg'];
                        }
                    }
                }
            }
        } else {
            $mainItems = $defaultMainMenuItems;
        }

        $defaultProfileDropDownItems = [
            'my_spaces' => [
                'slug'      => 'my_spaces',
                'title'     => __('My Spaces', 'fluent-community'),
                'is_system' => 'yes',
                'is_locked' => 'yes',
                'enabled'   => 'yes',
                'permalink' => '#{{user_url}}/spaces',
                'shape_svg' => '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M0.5 16.5C0.5 14.9087 1.13214 13.3826 2.25736 12.2574C3.38258 11.1321 4.9087 10.5 6.5 10.5C8.0913 10.5 9.61742 11.1321 10.7426 12.2574C11.8679 13.3826 12.5 14.9087 12.5 16.5H11C11 15.3065 10.5259 14.1619 9.68198 13.318C8.83807 12.4741 7.69347 12 6.5 12C5.30653 12 4.16193 12.4741 3.31802 13.318C2.47411 14.1619 2 15.3065 2 16.5H0.5ZM6.5 9.75C4.01375 9.75 2 7.73625 2 5.25C2 2.76375 4.01375 0.75 6.5 0.75C8.98625 0.75 11 2.76375 11 5.25C11 7.73625 8.98625 9.75 6.5 9.75ZM6.5 8.25C8.1575 8.25 9.5 6.9075 9.5 5.25C9.5 3.5925 8.1575 2.25 6.5 2.25C4.8425 2.25 3.5 3.5925 3.5 5.25C3.5 6.9075 4.8425 8.25 6.5 8.25ZM12.713 11.0273C13.767 11.5019 14.6615 12.2709 15.2889 13.2418C15.9164 14.2126 16.2501 15.344 16.25 16.5H14.75C14.7502 15.633 14.4999 14.7844 14.0293 14.0562C13.5587 13.328 12.8878 12.7512 12.0972 12.3953L12.7123 11.0273H12.713ZM12.197 2.55975C12.9526 2.87122 13.5987 3.40015 14.0533 4.07942C14.5078 4.75869 14.7503 5.55768 14.75 6.375C14.7503 7.40425 14.3658 8.39642 13.6719 9.15662C12.978 9.91682 12.025 10.3901 11 10.4835V8.97375C11.5557 8.89416 12.0713 8.63851 12.471 8.24434C12.8707 7.85017 13.1335 7.33824 13.2209 6.7837C13.3082 6.22916 13.2155 5.66122 12.9563 5.16327C12.6971 4.66531 12.2851 4.26356 11.7808 4.017L12.197 2.55975Z" fill="currentColor"/></svg>'
            ],
            'bookmarks' => [
                'slug'      => 'bookmarks',
                'title'     => __('Bookmarks', 'fluent-community'),
                'is_system' => 'yes',
                'is_locked' => 'yes',
                'enabled'   => 'yes',
                'permalink' => self::baseUrl('bookmarks'),
                'shape_svg' => '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M0.75 0.5H11.25C11.4489 0.5 11.6397 0.579018 11.7803 0.71967C11.921 0.860322 12 1.05109 12 1.25V15.6073C12.0001 15.6743 11.9822 15.7402 11.9482 15.7979C11.9142 15.8557 11.8653 15.9033 11.8066 15.9358C11.7479 15.9683 11.6816 15.9844 11.6146 15.9826C11.5476 15.9807 11.4823 15.9609 11.4255 15.9252L6 12.5225L0.5745 15.9245C0.517776 15.9601 0.452541 15.9799 0.385576 15.9818C0.318612 15.9837 0.252365 15.9676 0.193721 15.9352C0.135078 15.9029 0.0861801 15.8554 0.0521121 15.7977C0.0180441 15.74 4.98531e-05 15.6742 0 15.6073V1.25C0 1.05109 0.0790178 0.860322 0.21967 0.71967C0.360322 0.579018 0.551088 0.5 0.75 0.5ZM10.5 2H1.5V13.574L6 10.7533L10.5 13.574V2Z" fill="currentColor"/></svg>'
            ],
            'logout'    => [
                'slug'      => 'logout',
                'title'     => __('Logout', 'fluent-community'),
                'is_system' => 'yes',
                'is_locked' => 'yes',
                'enabled'   => 'yes',
                'permalink' => '#{{logout_url}}',
                'shape_svg' => '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M0.75 15.5C0.551088 15.5 0.360322 15.421 0.21967 15.2803C0.0790178 15.1397 0 14.9489 0 14.75V1.25C0 1.05109 0.0790178 0.860322 0.21967 0.71967C0.360322 0.579018 0.551088 0.5 0.75 0.5H11.25C11.4489 0.5 11.6397 0.579018 11.7803 0.71967C11.921 0.860322 12 1.05109 12 1.25V3.5H10.5V2H1.5V14H10.5V12.5H12V14.75C12 14.9489 11.921 15.1397 11.7803 15.2803C11.6397 15.421 11.4489 15.5 11.25 15.5H0.75ZM10.5 11V8.75H5.25V7.25H10.5V5L14.25 8L10.5 11Z" fill="currentColor"/></svg>'
            ]
        ];

        $profileDropDownItems = Arr::get($menuGroups, 'profileDropdownItems', []);

        if ($profileDropDownItems && is_array($profileDropDownItems)) {

            unset($profileDropDownItems['profile']);

            foreach ($profileDropDownItems as $index => &$item) {
                if (empty($item['slug'])) {
                    unset($profileDropDownItems[$index]);
                    continue;
                }
                $defaultItem = Arr::get($defaultProfileDropDownItems, $item['slug'], []);
                if ($defaultItem) {
                    $preservedKeys = ['is_system', 'is_locked', 'is_unavailable', 'slug'];
                    foreach ($preservedKeys as $key) {
                        if (isset($defaultItem[$key])) {
                            $item[$key] = Arr::get($defaultItem, $key);
                        }
                    }
                    if (Arr::get($defaultItem, 'is_system') === 'yes') {
                        $item['permalink'] = $defaultItem['permalink'];
                        if (empty($item['shape_svg'])) {
                            $item['shape_svg'] = $defaultItem['shape_svg'];
                        }
                    }
                }
            }
        } else {
            $profileDropDownItems = $defaultProfileDropDownItems;
        }

        $beforeCommunityMenuItems = Arr::get($menuGroups, 'beforeCommunityMenuItems', []);
        $afterCommunityMenuGroups = Arr::get($menuGroups, 'afterCommunityLinkGroups', []);

        if (!is_array($beforeCommunityMenuItems)) {
            $beforeCommunityMenuItems = [];
        }

        if (!is_array($afterCommunityMenuGroups)) {
            $afterCommunityMenuGroups = [];
        }

        if ($context == 'view') {
            $mainItems = array_filter($mainItems, function ($item) {
                return Arr::get($item, 'enabled') === 'yes' && Arr::get($item, 'is_unavailable') !== 'yes';
            });

            $profileDropDownItems = array_filter($profileDropDownItems, function ($item) {
                return Arr::get($item, 'enabled') === 'yes' && Arr::get($item, 'is_unavailable') !== 'yes';
            });

            $beforeCommunityMenuItems = array_filter($beforeCommunityMenuItems, function ($item) {
                return Arr::get($item, 'enabled') === 'yes' && Arr::get($item, 'is_unavailable') !== 'yes';
            });

            $validGroups = [];
            foreach ($afterCommunityMenuGroups as $group) {
                if (empty($group['items']) || !is_array($group['items'])) {
                    continue;
                }

                $group['items'] = array_filter($group['items'], function ($item) {
                    return Arr::get($item, 'enabled') === 'yes' && Arr::get($item, 'is_unavailable') !== 'yes';
                });

                if ($group['items']) {
                    $validGroups[] = $group;
                }
            }

            $afterCommunityMenuGroups = $validGroups;
        }

        $menuGroups['mainMenuItems'] = $mainItems;
        $menuGroups['profileDropdownItems'] = $profileDropDownItems;
        $menuGroups['beforeCommunityMenuItems'] = $beforeCommunityMenuItems;
        $menuGroups['afterCommunityLinkGroups'] = $afterCommunityMenuGroups;

        if ($context == 'view') {
            $menuGroups = apply_filters('fluent_community/menu_groups', $menuGroups);
        }

        return $menuGroups;
    }

    /**
     * Get the meta data for a space.
     *
     * @param int $spaceId The ID of the space.
     * @param string $key The meta key.
     * @param mixed $default The default value if the meta key is not found.
     * @return mixed The meta value or the default value if not found.
     */
    public static function getSpaceMeta($spaceId, $key, $default = null)
    {
        $meta = Meta::where('object_type', 'space')
            ->where('meta_key', $key)
            ->where('object_id', $spaceId)
            ->first();

        if (!$meta) {
            return $default;
        }

        return $meta->value;
    }

    /**
     * Update the meta data for a space.
     *
     * @param int $spaceId The ID of the space.
     * @param string $key The meta key.
     * @param mixed $value The meta value.
     * @return Meta The updated meta object.
     */
    public static function updateSpaceMeta($spaceId, $key, $value)
    {
        $meta = Meta::where('object_type', 'space')
            ->where('meta_key', $key)
            ->where('object_id', $spaceId)
            ->first();

        if ($meta) {
            $meta->value = $value;
            $meta->save();
        } else {
            $meta = Meta::create([
                'object_type' => 'space',
                'object_id'   => $spaceId,
                'meta_key'    => $key,
                'value'       => $value
            ]);
        }

        return $meta;
    }


    /**
     * Encrypt or decrypt a value.
     *
     * @param string $value The value to encrypt or decrypt.
     * @param string $type The type of operation ('e' for encrypt, 'd' for decrypt).
     * @return string|false The encrypted or decrypted value or false if an error occurs.
     */
    public static function encryptDecrypt($value, $type = 'e')
    {
        if (!$value) {
            return $value;
        }

        if (!extension_loaded('openssl')) {
            return $value;
        }

        if (defined('FLUENT_COM_ENCRYPT_SALT')) {
            $salt = FLUENT_COM_ENCRYPT_SALT;
        } else {
            $salt = (defined('LOGGED_IN_SALT') && '' !== LOGGED_IN_SALT) ? LOGGED_IN_SALT : 'this-is-a-fallback-salt-but-not-secure';
        }

        if (defined('FLUENT_COM__ENCRYPT_KEY')) {
            $key = FLUENT_COM__ENCRYPT_KEY;
        } else {
            $key = (defined('LOGGED_IN_KEY') && '' !== LOGGED_IN_KEY) ? LOGGED_IN_KEY : 'this-is-a-fallback-key-but-not-secure';
        }

        if ($type == 'e') {
            $method = 'aes-256-ctr';
            $ivlen = openssl_cipher_iv_length($method);
            $iv = openssl_random_pseudo_bytes($ivlen);

            $raw_value = openssl_encrypt($value . $salt, $method, $key, 0, $iv);
            if (!$raw_value) {
                return false;
            }

            return base64_encode($iv . $raw_value); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        }

        $raw_value = base64_decode($value, true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

        $method = 'aes-256-ctr';
        $ivlen = openssl_cipher_iv_length($method);
        $iv = substr($raw_value, 0, $ivlen);

        $raw_value = substr($raw_value, $ivlen);

        $newValue = openssl_decrypt($raw_value, $method, $key, 0, $iv);
        if (!$newValue || substr($newValue, -strlen($salt)) !== $salt) {
            return false;
        }

        return substr($newValue, 0, -strlen($salt));
    }


    /**
     * Get the welcome banner configuration.
     *
     * @return array The welcome banner configuration.
     */
    public static function getWelcomeBannerSettings()
    {
        return Utility::getFromCache('welcome_banner_settings', function () {
            $defaults = [
                'login'  => [
                    'enabled'     => 'no',
                    'description' => '',
                    'mediaType'   => 'image',
                    'allowClose'  => 'no',
                    'bannerImage' => '',
                    'bannerVideo' => [
                        'type'         => 'oembed',
                        'url'          => '',
                        'content_type' => '',
                        'provider'     => '',
                        'title'        => '',
                        'author_name'  => '',
                        'html'         => ''
                    ],
                    'ctaButtons'  => []
                ],
                'logout' => [
                    'enabled'      => 'no',
                    'description'  => '',
                    'mediaType'    => 'image',
                    'useCustomUrl' => 'no',
                    'bannerImage'  => '',
                    'bannerVideo'  => [
                        'type'         => 'oembed',
                        'url'          => '',
                        'content_type' => '',
                        'provider'     => '',
                        'title'        => '',
                        'author_name'  => '',
                        'html'         => ''
                    ],
                    'ctaButtons'   => []
                ]
            ];

            $settings = Utility::getOption('welcome_banner_settings', []);

            $settings = wp_parse_args($settings, $defaults);

            if (empty(Arr::get($settings, 'login.bannerVideo'))) {
                $settings['login']['bannerVideo'] = $defaults['login']['bannerVideo'];
            }

            if (empty(Arr::get($settings, 'logout.bannerVideo'))) {
                $settings['logout']['bannerVideo'] = $defaults['logout']['bannerVideo'];
            }

            return $settings;
        }, WEEK_IN_SECONDS);
    }

    public static function getWelcomeBanner($view = 'login')
    {
        $settings = self::getWelcomeBannerSettings();
        $welcomeBanner = Arr::get($settings, $view, []);
        if (Arr::get($welcomeBanner, 'enabled') != 'yes') {
            return null;
        }

        unset($welcomeBanner['description']);

        if ($view == 'login') {
            return apply_filters('fluent_community/welcome_banner_for_logged_in', $welcomeBanner);
        }

        return apply_filters('fluent_community/welcome_banner_for_guests', $welcomeBanner);
    }

    public static function getEnabledFeedLinks()
    {
        $links = array_filter(self::getFeedLinks(), function ($item) {
            return Arr::get($item, 'enabled') == 'yes' && Arr::get($item, 'is_unavailable') != 'yes';
        });

        return array_values($links);
    }

    public static function getFeedLinks()
    {
        return Utility::getFromCache('feed_links', function () {
            return Utility::getOption('feed_links', []);
        }, WEEK_IN_SECONDS);
    }

    public static function updateFeedLinks($links)
    {
        Utility::updateOption('feed_links', $links);
        Utility::setCache('feed_links', $links, WEEK_IN_SECONDS);
    }

    /**
     * Get the full name of a WordPress user.
     *
     * @param int|null $id The ID of the user.
     * @return string The full name of the user.
     */
    public static function getWpUserFullName($id = null)
    {
        $id = $id ?: get_current_user_id();
        $user = get_user_by('ID', $id);

        $fullName = $user->display_name;
        if ($user->first_name && $user->last_name) {
            $fullName = $user->first_name . ' ' . $user->last_name;
        }

        return $fullName;
    }

    /**
     * Get the onboarding settings.
     *
     * @return array The onboarding settings.
     */
    public static function getOnboardingSettings()
    {
        $default = [
            'is_onboarding_enabled' => 'no',
            'registration_page_url' => '',
        ];

        $settings = Utility::getOption('onboarding_settings', $default);

        return wp_parse_args($settings, $default);
    }

    /**
     * Get all WordPress published pages.
     *
     * @return array An array of page data.
     */
    public static function getAllWpPublishedPage()
    {
        $posts = get_posts(array(
            'post_status' => 'publish',
            'numberposts' => -1,
            'post_type'   => 'any',
        ));

        return array_map(function ($post) {
            return array(
                'id'        => $post->ID,
                'permalink' => get_permalink($post),
                'title'     => get_the_title($post),
            );
        }, $posts);
    }

    /**
     * Add a user to a space.
     *
     * @param Space | int $space space to add the user to.
     * @param int $userId The ID of the user to add.
     * @param string $role The role of the user in the space.
     * @param string $by The source of the action.
     * @return bool True if the user was added, false otherwise.
     */
    public static function addToSpace($space, $userId, $role = 'member', $by = 'self')
    {
        if (is_numeric($space)) {
            $space = BaseSpace::withoutGlobalScopes()->find($space);
        }

        if (!$space || !$space instanceof BaseSpace) {
            return false;
        }

        $user = User::find($userId);

        if (!$user) {
            return false;
        }

        $user->syncXProfile();

        if ($role == 'member' && $space->type == 'course') {
            $role = 'student';
        }

        $exist = SpaceUserPivot::where('user_id', $userId)
            ->where('space_id', $space->id)
            ->first();

        if ($exist) {
            if ($exist->status != 'active') {
                $exist->status = 'active';

                if (!in_array($exist->role, ['admin', 'moderator'])) {
                    $exist->role = $role;
                }

                $exist->save();

                if ($space->type == 'course') {
                    do_action('fluent_community/course/enrolled', $space, $userId, $by);
                } else {
                    do_action('fluent_community/space/joined', $space, $userId, $by);
                }

                return true;
            }

            return false;
        }

        $created = SpaceUserPivot::create([
            'space_id' => $space->id,
            'role'     => $role,
            'user_id'  => $userId
        ]);

        if ($space->type == 'course') {
            do_action('fluent_community/course/enrolled', $space, $userId, $by);
        } else {
            do_action('fluent_community/space/joined', $space, $userId, $by);
        }

        return true;
    }

    /**
     * Remove a user from a space if exist.
     *
     * @param int $userId The ID of the user.
     * @param int $spaceId The ID of the space.
     * @param string $by The source of the action. self | by_admin
     * @return bool True if the user is in the space, false otherwise.
     */
    public static function removeFromSpace($space, $userId, $by = 'self')
    {
        $user = User::find($userId);
        if (!$user) {
            return false;
        }

        if (is_numeric($space)) {
            $space = BaseSpace::query()->withoutGlobalScopes()->find($space);
        }

        if (!$space || !$space instanceof BaseSpace) {
            return false;
        }

        if (!self::isUserInSpace($userId, $space->id)) {
            return false;
        }

        SpaceUserPivot::where('space_id', $space->id)
            ->where('user_id', $userId)
            ->delete();

        $user->cacheAccessSpaces();

        if ($space->type == 'course') {
            do_action('fluent_community/course/student_left', $space, $userId, $by);
        } else {
            do_action('fluent_community/space/user_left', $space, $userId, $by);
        }

        return true;
    }

    /**
     * Render a link with icon.
     *
     * @param array $link The link data.
     * @param string $linkClass Additional classes for the link.
     * @param string $fallback The fallback content if no icon is found.
     * @param bool $renderIcon Whether to render the icon or not.
     */
    public static function renderLink($link, $linkClass = '', $fallback = '<span class="fcom_no_avatar"></span>', $renderIcon = true)
    {
        $linkAtts = array_filter([
            'class'  => trim($linkClass . ' ' . Arr::get($link, 'link_classes')) . ' fcom_compt_link',
            'target' => Arr::get($link, 'new_tab') === 'yes' ? '_blank' : '',
            'rel'    => Arr::get($link, 'new_tab') === 'yes' ? 'noopener noreferrer' : '',
        ]);
        ?>
        <a aria-label="Go to <?php echo esc_attr(Arr::get($link, 'title')); ?> page"
           href="<?php echo esc_url($link['permalink']); ?>" <?php foreach ($linkAtts as $key => $value) {
            echo esc_attr($key) . '="' . esc_attr($value) . '"';
        } ?>>
            <?php $renderIcon && self::printLinkIcon($link, $fallback); ?>
            <span class="community_name"><?php echo wp_kses_post(Arr::get($link, 'title')); ?></span>
            <?php if (Arr::get($link, 'show_lock')): ?>
                <span class="fcom_space_lock">
                    <i class="el-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024">
                            <path fill="currentColor"
                                  d="M224 448a32 32 0 0 0-32 32v384a32 32 0 0 0 32 32h576a32 32 0 0 0 32-32V480a32 32 0 0 0-32-32zm0-64h576a96 96 0 0 1 96 96v384a96 96 0 0 1-96 96H224a96 96 0 0 1-96-96V480a96 96 0 0 1 96-96"></path>
                            <path fill="currentColor"
                                  d="M512 544a32 32 0 0 1 32 32v192a32 32 0 1 1-64 0V576a32 32 0 0 1 32-32m192-160v-64a192 192 0 1 0-384 0v64zM512 64a256 256 0 0 1 256 256v128H256V320A256 256 0 0 1 512 64"></path>
                        </svg>
                    </i>
                </span>
            <?php endif; ?>

        </a>
        <?php
    }

    /**
     * Print a link icon.
     *
     * @param array $link The link data.
     * @param string $fallback The fallback content if no icon is found.
     */
    public static function printLinkIcon($link, $fallback = '<span class="fcom_no_avatar"></span>')
    {
        ?>
        <?php if ($img = Arr::get($link, 'icon_image')): ?>
        <div class="community_avatar">
            <img alt="" src="<?php echo esc_url($img); ?>"/>
        </div>
    <?php elseif ($emoji = Arr::get($link, 'emoji')): ?>
        <div class="community_avatar">
            <span class="fcom_emoji"><?php echo esc_html($emoji); ?></span>
        </div>
    <?php elseif ($svg = Arr::get($link, 'shape_svg')): ?>
        <div class="community_avatar">
            <span class="fcom_shape"><i
                    class="el-icon"><?php echo \FluentCommunity\App\Services\CustomSanitizer::sanitizeSvg($svg); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped  ?></i></span>
        </div>
    <?php else:
        echo '<div class="community_avatar">' . wp_kses_post($fallback) . '</div>';
    endif;
    }

    /**
     * Get the IP address of the user.
     *
     * @param bool $anonymize Whether to anonymize the IP address.
     * @return string The IP address.
     */
    public static function getIp($anonymize = false)
    {
        static $ipAddress;

        if ($ipAddress) {
            return $ipAddress;
        }

        if (empty($_SERVER['REMOTE_ADDR'])) {
            // It's a local cli request
            return '127.0.0.1';
        }

        $ipAddress = '';
        if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
            $ipAddress = sanitize_text_field(wp_unslash($_SERVER["REMOTE_ADDR"]));
            //If it's a valid Cloudflare request
            if (self::isCfIp($ipAddress)) {
                //Use the CF-Connecting-IP header.
                $ipAddress = sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
            }
        } else if ($_SERVER['REMOTE_ADDR'] == '127.0.0.1') {
            // most probably it's local reverse proxy
            if (isset($_SERVER["HTTP_CLIENT_IP"])) {
                $ipAddress = sanitize_text_field(wp_unslash($_SERVER["HTTP_CLIENT_IP"]));
            } else if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ipAddress = (string)rest_is_ip_address(trim(current(preg_split('/,/', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']))))));
            }
        }

        if (!$ipAddress) {
            $ipAddress = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        $ipAddress = preg_replace('/^(\d+\.\d+\.\d+\.\d+):\d+$/', '\1', $ipAddress);

        $ipAddress = apply_filters('fluent_auth/user_ip', $ipAddress);

        if ($anonymize) {
            return wp_privacy_anonymize_ip($ipAddress);
        }

        return $ipAddress;
    }

    /**
     * Check if the IP address is from Cloudflare.
     *
     * @param string $ip The IP address to check.
     * @return bool True if the IP is from Cloudflare, false otherwise.
     */
    public static function isCfIp($ip = '')
    {
        if (!$ip && isset($_SERVER["REMOTE_ADDR"])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER["REMOTE_ADDR"]));
        }

        if (!$ip) {
            return false;
        }

        $cloudflareIPRanges = array(
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
        );

        //Make sure that the request came via Cloudflare.
        foreach ($cloudflareIPRanges as $range) {
            //Use the ip_in_range function from Joomla.
            if (self::ipInRange($ip, $range)) {
                //IP is valid. Belongs to Cloudflare.
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the IP address is in the given range.
     *
     * @param string $ip The IP address to check.
     * @param string $range The range to check against.
     * @return bool True if the IP is in the range, false otherwise.
     */
    private static function ipInRange($ip, $range)
    {
        if (strpos($range, '/') !== false) {
            // $range is in IP/NETMASK format
            list($range, $netmask) = explode('/', $range, 2);
            if (strpos($netmask, '.') !== false) {
                // $netmask is a 255.255.0.0 format
                $netmask = str_replace('*', '0', $netmask);
                $netmask_dec = ip2long($netmask);
                return ((ip2long($ip) & $netmask_dec) == (ip2long($range) & $netmask_dec));
            } else {
                // $netmask is a CIDR size block
                // fix the range argument
                $x = explode('.', $range);
                while (count($x) < 4) $x[] = '0';
                list($a, $b, $c, $d) = $x;
                $range = sprintf("%u.%u.%u.%u", empty($a) ? '0' : $a, empty($b) ? '0' : $b, empty($c) ? '0' : $c, empty($d) ? '0' : $d);
                $range_dec = ip2long($range);
                $ip_dec = ip2long($ip);

                # Strategy 1 - Create the netmask with 'netmask' 1s and then fill it to 32 with 0s
                #$netmask_dec = bindec(str_pad('', $netmask, '1') . str_pad('', 32-$netmask, '0'));

                # Strategy 2 - Use math to create it
                $wildcard_dec = pow(2, (32 - $netmask)) - 1;
                $netmask_dec = ~$wildcard_dec;

                return (($ip_dec & $netmask_dec) == ($range_dec & $netmask_dec));
            }
        } else {
            // range might be 255.255.*.* or 1.2.3.0-1.2.3.255
            if (strpos($range, '*') !== false) { // a.b.*.* format
                // Just convert to A-B format by setting * to 0 for A and 255 for B
                $lower = str_replace('*', '0', $range);
                $upper = str_replace('*', '255', $range);
                $range = "$lower-$upper";
            }

            if (strpos($range, '-') !== false) { // A-B format
                list($lower, $upper) = explode('-', $range, 2);
                $lower_dec = (float)sprintf("%u", ip2long($lower));
                $upper_dec = (float)sprintf("%u", ip2long($upper));
                $ip_dec = (float)sprintf("%u", ip2long($ip));
                return (($ip_dec >= $lower_dec) && ($ip_dec <= $upper_dec));
            }
            return false;
        }
    }

    public static function getPortalRequestPath($requestUri)
    {
        $portalSlug = self::getPortalSlug();

        if ($portalSlug == $requestUri) {
            return 'portal_home';
        }
        if (!$requestUri) {
            return false;
        }

        if ($portalSlug) {
            // remove the portal slug from the request uri. Don't use str_replace as it will replace all occurrences
            $requestUri = substr($requestUri, strlen($portalSlug));
        }

        $parts = explode('/', $requestUri);
        $start = $parts[0];

        $routeStats = self::portalRoutePaths();

        if (in_array($start, $routeStats)) {
            return $requestUri;
        }
        return false;
    }

    public static function getTopicsConfig()
    {
        return Utility::getFromCache('topics_config', function () {
            $config = Utility::getOption('topics_config', []);
            $default = [
                'max_topics_per_post'  => 1,
                'max_topics_per_space' => 20,
                'show_on_post_card'    => 'yes'
            ];
            return wp_parse_args($config, $default);
        }, WEEK_IN_SECONDS);
    }
}
