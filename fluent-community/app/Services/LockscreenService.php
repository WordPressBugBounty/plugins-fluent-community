<?php

namespace FluentCommunity\App\Services;

use FluentCommunity\App\Models\BaseSpace;
use FluentCommunity\Framework\Support\Arr;

class LockscreenService
{
    public static function getLockscreenSettings(BaseSpace $space)
    {
        $defaultSettings = [
            [
                'hidden'            => false,
                'type'              => 'image',
                'label'             => 'Banner',
                'name'              => 'banner',
                'heading'           => 'Banner Heading',
                'heading_color'     => '#FFFFFF',
                'description'       => 'Banner Description',
                'text_color'        => '#FFFFFF',
                'button_text'       => 'Buy Now',
                'button_link'       => '',
                'button_color'      => '#2B2E33',
                'button_text_color' => '#FFFFFF',
                'background_image'  => '',
                'overlay_color'     => '#798398'
            ],
            [
                'hidden'  => false,
                'type'    => 'block',
                'label'   => 'Description',
                'name'    => 'description',
                'content' => 'Description Test'
            ]
        ];

        if ($space->isCourseSpace()) {
            $defaultSettings[] = [
                'hidden' => true,
                'type'   => 'lesson',
                'label'  => 'Lessons',
                'name'   => 'lesson'
            ];
        }

        $defaultSettings[] = [
            'hidden'            => false,
            'type'              => 'image',
            'label'             => 'Call to action',
            'name'              => 'action',
            'heading'           => 'Call to Action Heading',
            'heading_color'     => '#FFFFFF',
            'description'       => 'Call to Action Description',
            'text_color'        => '#FFFFFF',
            'button_text'       => 'Buy Now',
            'button_link'       => '',
            'button_color'      => '#2B2E33',
            'button_text_color' => '#FFFFFF',
            'background_image'  => '',
            'overlay_color'     => '#798398'
        ];

        $settings = $space->getCustomMeta('lockscreen_settings', $defaultSettings);

        foreach ($settings as &$setting) {
            if ($setting['type'] === 'block' && !empty($setting['content'])) {
                $setting['content'] = do_blocks($setting['content']);
            }
        }

        return $settings;
    }

    public static function formatLockscreenFields($settingFields)
    {
        $textFields = ['type', 'name', 'label', 'heading', 'description', 'button_text', 'el_icon'];
        $colorFields = ['heading_color', 'text_color', 'button_color', 'button_text_color', 'overlay_color'];
        $urlFields = ['button_link', 'background_image'];

        $formattedFields = [];

        foreach ($settingFields as $value) {
            $textValues = array_map('sanitize_text_field', Arr::only($value, $textFields));
            $colorValues = array_map('sanitize_hex_color', Arr::only($value, $colorFields));
            $urlValues = array_map('sanitize_url', Arr::only($value, $urlFields));

            $formattedField = array_merge($textValues, $colorValues, $urlValues);

            $formattedField['hidden'] = Arr::isTrue($value, 'hidden');

            if ($value['type'] == 'block') {
                $formattedField['content'] = wp_kses_post(Arr::get($value, 'content'));
            }

            $formattedFields[] = $formattedField;
        }

        return $formattedFields;
    }

    public static function getLockscreenConfig(BaseSpace $space, $membership = null)
    {
        if ($space->privacy != 'private') {
            return null;
        }

        if($membership) {
            if($membership->pivot->status) {
                if($membership->pivot->status == 'pending') {
                    return [
                        'is_pending' => true
                    ];
                }
                return null;
            }
        }

        $showCustom = Arr::get($space->settings, 'custom_lock_screen', 'no') === 'yes';
        $canSendRequest = Arr::get($space->settings, 'can_request_join', 'no') === 'yes';

        return [
            'showCustom'     => $showCustom,
            'canSendRequest' => $canSendRequest,
            'lockScreen'     => $showCustom ? self::getLockscreenSettings($space) : null
        ];
    }
}
