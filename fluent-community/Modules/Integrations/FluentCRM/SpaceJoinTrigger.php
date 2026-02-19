<?php

namespace FluentCommunity\Modules\Integrations\FluentCRM;

use FluentCommunity\App\Models\Space;
use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class SpaceJoinTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'fluent_community/space/joined';
        $this->priority = 20;
        $this->actionArgNum = 2;
        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('Community', 'fluent-community'),
            'label'       => __('Joined in a Space', 'fluent-community'),
            'description' => __('This automation will be initiated when a user joins a space.', 'fluent-community'),
            'icon'        => 'fc-icon-wp_new_user_signup',
            'svg_icon' => '<svg version="1.1" viewBox="0 0 128 128" xml:space="preserve"><g><path d="M64,42c-13.2,0-24,10.8-24,24s10.8,24,24,24s24-10.8,24-24S77.2,42,64,42z M64,82c-8.8,0-16-7.2-16-16s7.2-16,16-16   s16,7.2,16,16S72.8,82,64,82z"></path><path d="M64,100.8c-14.9,0-29.2,6.2-39.4,17.1l-2.7,2.9l5.8,5.5l2.7-2.9c8.8-9.4,20.7-14.6,33.6-14.6s24.8,5.2,33.6,14.6l2.7,2.9   l5.8-5.5l-2.7-2.9C93.2,107.1,78.9,100.8,64,100.8z"></path><path d="M97,47.9v8c9.4,0,18.1,3.8,24.6,10.7l5.8-5.5C119.6,52.7,108.5,47.9,97,47.9z"></path><path d="M116.1,20c0-10.5-8.6-19.1-19.1-19.1S77.9,9.5,77.9,20S86.5,39.1,97,39.1S116.1,30.5,116.1,20z M85.9,20   c0-6.1,5-11.1,11.1-11.1s11.1,5,11.1,11.1s-5,11.1-11.1,11.1S85.9,26.1,85.9,20z"></path><path d="M31,47.9c-11.5,0-22.6,4.8-30.4,13.2l5.8,5.5c6.4-6.9,15.2-10.7,24.6-10.7V47.9z"></path><path d="M50.1,20C50.1,9.5,41.5,0.9,31,0.9S11.9,9.5,11.9,20S20.5,39.1,31,39.1S50.1,30.5,50.1,20z M31,31.1   c-6.1,0-11.1-5-11.1-11.1S24.9,8.9,31,8.9s11.1,5,11.1,11.1S37.1,31.1,31,31.1z"></path></g></svg>'
        ];
    }

    public function getSettingsFields($funnel)
    {
        return [
            'title'     => __('Joined in a Space', 'fluent-community'),
            'sub_title' => __('This automation will be initiated when a user join in a Space', 'fluent-community'),
            'fields'    => [
                'subscription_status'      => [
                    'type'        => 'option_selectors',
                    'option_key'  => 'editable_statuses',
                    'is_multiple' => false,
                    'label'       => __('Subscription Status', 'fluent-community'),
                    'placeholder' => __('Select Status', 'fluent-community')
                ],
                'subscription_status_info' => [
                    'type'       => 'html',
                    'info'       => '<b>' . __('An Automated double-optin email will be sent for new subscribers', 'fluent-community') . '</b>',
                    'dependency' => [
                        'depends_on' => 'subscription_status',
                        'operator'   => '=',
                        'value'      => 'pending'
                    ]
                ]
            ]
        ];
    }

    public function getFunnelSettingsDefaults()
    {
        return [
            'subscription_status' => 'subscribed'
        ];
    }

    public function getConditionFields($funnel)
    {
        $communities = Space::orderBy('title', 'ASC')->select(['id', 'title'])->get();

        return [
            'update_type'   => [
                'type'    => 'radio',
                'label'   => __('If Contact Already Exist?', 'fluent-community'),
                'help'    => __('Please specify what will happen if the subscriber already exists in the database', 'fluent-community'),
                'options' => FunnelHelper::getUpdateOptions()
            ],
            'community_ids' => [
                'type'        => 'multi-select',
                'is_multiple' => true,
                'label'       => __('Targeted Spaces', 'fluent-community'),
                'help'        => __('Select which spaces this automation funnel is for.', 'fluent-community'),
                'placeholder' => __('Select Spaces', 'fluent-community'),
                'options'     => $communities,
                'inline_help' => __('Leave blank to run for all Spaces', 'fluent-community')
            ],
            'run_multiple'  => [
                'type'        => 'yes_no_check',
                'label'       => '',
                'check_label' => __('Restart automation multiple times for a contact for this event. (Enable only if you want to restart automation for the same contact.)', 'fluent-community'),
                'inline_help' => __('If you enable this, it will restart the automation for a contact even if they are already in the automation. Otherwise, it will skip if the contact already exists.', 'fluent-community')
            ]
        ];
    }

    public function getFunnelConditionDefaults($funnel)
    {
        return [
            'update_type'   => 'update', // skip_all_actions, skip_update_if_exist
            'community_ids' => [],
            'run_multiple'  => 'yes'
        ];
    }

    public function handle($funnel, $originalArgs)
    {
        $space = $originalArgs[0];
        $userId = $originalArgs[1];

        $user = get_user_by('ID', $userId);

        if (!$user || !$this->isProcessable($funnel, $user, $space)) {
            return false;
        }

        $subscriberData = FunnelHelper::prepareUserData($user);

        $subscriberData = wp_parse_args($subscriberData, $funnel->settings);
        $subscriberData['status'] = $subscriberData['subscription_status'];
        unset($subscriberData['subscription_status']);

        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriberData, [
            'source_trigger_name' => $this->triggerName,
            'source_ref_id'       => $space->id
        ]);
    }

    private function isProcessable($funnel, $user, $space)
    {
        $conditions = $funnel->conditions;
        // check update_type
        $updateType = Arr::get($conditions, 'update_type');

        $subscriber = FunnelHelper::getSubscriber($user->user_email);
        if ($updateType == 'skip_all_if_exist' && $subscriber) {
            return false;
        }

        // check user roles
        if ($checkIds = Arr::get($conditions, 'community_ids', [])) {
            $checkIds = (array) $checkIds;
            if (!in_array($space->id, $checkIds)) {
                return false;
            }
        }

        // check run_only_one
        if ($subscriber && FunnelHelper::ifAlreadyInFunnel($funnel->id, $subscriber->id)) {
            $multipleRun = Arr::get($conditions, 'run_multiple') == 'yes';
            if ($multipleRun) {
                FunnelHelper::removeSubscribersFromFunnel($funnel->id, [$subscriber->id]);
            }
            return $multipleRun;
        }

        return true;
    }
}
