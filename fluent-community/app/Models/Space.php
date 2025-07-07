<?php

namespace FluentCommunity\App\Models;

use FluentCommunity\App\Functions\Utility;
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
            'restricted_post_only'  => 'no',
            'emoji'                 => '',
            'shape_svg'             => '',
            'custom_lock_screen'    => 'no',
            'can_request_join'      => 'no',
            'layout_style'          => 'timeline',
            'show_sidebar'          => 'yes',
            'og_image'              => '',
            'links'                 => [],
            'document_library'      => 'no',
            'document_access'       => 'members_only',
            'disable_post_sort_by'  => 'no',
            'default_post_sort_by'  => '',
            'document_upload'       => 'admin_only',
            'topic_required'        => 'no',
            'hide_members_count'    => 'no', // yes / no
            'onboard_redirect_url'  => '',
            'members_page_status'   => 'members_only', // members_only, everybody, logged_in, admin_only
        ];
    }
}
