<?php

namespace FluentCommunity\App\Models;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Services\FeedsHelper;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Services\LockscreenService;
use FluentCommunity\Framework\Support\Arr;

/**
 *  Space Model - DB Model for Individual Space
 *
 *  Database Model
 *
 * @package FluentCrm\App\Models
 *
 * @version 1.1.0
 */
class Space extends BaseSpace
{
    protected static $type = 'community';


    public function defaultSettings()
    {
        return [
            'restricted_post_only' => 'no',
            'emoji'                => '',
            'shape_svg'            => '',
            'custom_lock_screen'   => 'no',
            'can_request_join'     => 'no',
            'layout_style'         => 'timeline',
            'show_sidebar'         => 'yes',
            'og_image'             => '',
            'links'                => [],
            'document_library'     => 'no',
            'document_access'      => 'members_only',
            'document_upload'      => 'admin_only',
            'topic_required'       => 'no',
            'hide_members_count'   => 'no', // yes / no
            'members_page_status'  => 'members_only', // members_only, everybody, logged_in, admin_only
        ];
    }

    public function formatSpaceData($user)
    {
        $userId = $user ? $user->ID : null;

        $this->permissions = $this->getUserPermissions($user);
        $this->description_rendered = wpautop($this->description);
        $this->membership = $this->getMembership($userId);
        $this->topics = Utility::getTopicsBySpaceId($this->id);

        if (!Helper::isSiteAdmin()) {
            $this->lockscreen_config = LockscreenService::getLockscreenConfig($this, $this->membership);
        }

        $headerLinks = [
            [
                'title' => __('Posts', 'fluent-community'),
                'route' => [
                    'name' => 'space_feeds',
                ]
            ]
        ];

        if (Arr::get($this->permissions, 'can_view_members')) {
            $headerLinks[] = [
                'title' => __('Members', 'fluent-community'),
                'route' => [
                    'name' => 'space_members',
                ]
            ];
        }

        $this->header_links = apply_filters('fluent_community/space_header_links', $headerLinks, $this);

        return $this;
    }
}
