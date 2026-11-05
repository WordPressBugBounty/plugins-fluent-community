<?php

namespace FluentCommunity\Modules\Integrations\FluentCRM;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Services\Helper;

class ProActionPlaceholders
{
    public function register()
    {
        add_filter('fluentcrm_funnel_blocks', [ $this, 'pushBlocks' ], 100);
    }

    public function pushBlocks($blocks)
    {
        $actions = [
            'add_to_fluent_community'                   => [
                __('Add to Space', 'fluent-community'),
                esc_html__('Add user to a space', 'fluent-community'),
            ],
            'remove_from_fluent_community'              => [
                __('Remove from Space', 'fluent-community'),
                esc_html__('Remove user from the Space', 'fluent-community'),
            ],
            'fluent_community_membership_status_change' => [
                __('Change Space Membership Status', 'fluent-community'),
                esc_html__('Activate or Block a user from a Space', 'fluent-community'),
            ],
            'add_or_remove_community_verification'      => [
                __('Add or Remove Verification Sign (Blue Badge) To User', 'fluent-community'),
                esc_html__('Add or Remove Verification Sign (Blue Badge) user\'s profile', 'fluent-community'),
            ],
            'add_badge_to_user'                         => [
                __('Add Badge To User', 'fluent-community'),
                esc_html__('Add Badge to user\'s profile', 'fluent-community'),
            ],
            'remove_badge_from_user'                    => [
                __('Remove Community Badge', 'fluent-community'),
                esc_html__('Remove Badge from user\'s profile', 'fluent-community'),
            ],
        ];

        if (Helper::isFeatureEnabled('course_module')) {
            $actions['add_to_fluent_community_course'] = [
                __('Enroll to Courses', 'fluent-community'),
                esc_html__('Add user to the selected courses', 'fluent-community'),
            ];
            $actions['remove_from_fluent_community_course'] = [
                __('Remove Courses', 'fluent-community'),
                esc_html__('Remove user from the selected courses', 'fluent-community'),
            ];
        }

        $upgradeUrl = esc_url_raw(Utility::getProUpgradeUrl('fluentcrm_automation'));

        foreach ($actions as $actionName => [$title, $description]) {
            if (isset($blocks[$actionName])) {
                continue;
            }

            $blocks[$actionName] = [
                'is_pro'          => true,
                'type'            => 'action',
                'category'        => __('Community', 'fluent-community'),
                'title'           => $title,
                'description'     => $description,
                'icon'            => 'fc-icon-apply_list',
                'pro_heading'     => __('Upgrade to FluentCommunity Pro', 'fluent-community'),
                'pro_description' => __('This block is available in FluentCommunity Pro. Please download and install FluentCommunity Pro.', 'fluent-community'),
                'pro_url'         => $upgradeUrl,
            ];
        }

        return $blocks;
    }
}
