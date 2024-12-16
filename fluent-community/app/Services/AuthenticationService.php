<?php

namespace FluentCommunity\App\Services;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\Framework\Support\Arr;

class AuthenticationService
{
    public static function getAuthForm($view = 'login')
    {
        $settings = self::getAuthSettings();

        return Arr::get($settings, $view, []);
    }

    public static function getAuthSettings()
    {
        return Utility::getFromCache('auth_settings', function () {

            $siteSettings = Helper::generalSettings();

            $siteLogo  = Arr::get($siteSettings, 'logo');
            $siteTitle = Arr::get($siteSettings, 'site_title');

            $defaults = [
                'login'  => [
                    'banner' => [
                        'hidden'           => false,
                        'type'             => 'banner',
                        'position'         => 'left',
                        'logo'             => $siteLogo,
                        'title'            => 'Welcome to ' . $siteTitle,
                        'description'      => 'Join our community and start your journey to success',
                        'title_color'      => '#19283a',
                        'text_color'       => '#525866',
                        'background_image' => '',
                        'background_color' => '#F5F7FA'
                    ],
                    'form' => [
                        'type'               => 'form',
                        'position'           => 'right',
                        'title'              => 'Login to ' . $siteTitle,
                        'description'        => 'Enter your email and password to login',
                        'title_color'        => '#19283a',
                        'text_color'         => '#525866',
                        'button_label'       => 'Login',
                        'button_color'       => '#2B2E33',
                        'button_label_color' => '#ffffff',
                        'background_image'   => '',
                        'background_color'   => '#ffffff'
                    ]
                ],
                'signup' => [
                    'banner' => [
                        'hidden'           => false,
                        'type'             => 'banner',
                        'position'         => 'left',
                        'logo'             => $siteLogo,
                        'title'            => 'Welcome to ' . $siteTitle,
                        'description'      => 'Join our community and start your journey to success',
                        'title_color'      => '#19283a',
                        'text_color'       => '#525866',
                        'background_image' => '',
                        'background_color' => '#F5F7FA',
                    ],
                    'form' => [
                        'type'               => 'form',
                        'position'           => 'right',
                        'title'              => 'Sign Up to ' . $siteTitle,
                        'description'        => 'Create an account to get started',
                        'button_label'       => 'Sign up',
                        'title_color'        => '#19283a',
                        'text_color'         => '#525866',
                        'button_color'       => '#2B2E33',
                        'button_label_color' => '#ffffff',
                        'background_image'   => '',
                        'background_color'   => '#ffffff',
                    ]
                ]
            ];

            $settings = Utility::getOption('auth_settings', []);

            return wp_parse_args($settings, $defaults);

        }, WEEK_IN_SECONDS);
    }

    public static function getFormattedAuthSettings($view = 'login')
    {
        $authSettings = self::getAuthSettings();
        $settings = Arr::get($authSettings, $view, []);

        foreach ($settings as &$setting) {
            if (Arr::get($setting, 'description_rendered')) {
                $setting['description'] = $setting['description_rendered'];
                unset($setting['description_rendered']);
            }
        }

        return $settings;
    }

    public static function formatAuthSettings($settingFields)
    {
        $currentSettings = self::getAuthSettings();

        $textFields = ['type', 'title', 'button_label', 'position', 'title_color', 'text_color', 'button_color', 'button_label_color', 'background_color'];
        $mediaFields = ['logo', 'background_image'];

        $formattedFields = [];
        foreach ($settingFields as $section => $settings) {
            foreach ($settings as $key => $setting) {
                $textValues = array_map('sanitize_text_field', Arr::only($setting, $textFields));
                $mediaUrls = array_map('sanitize_url', Arr::only($setting, $mediaFields));

                $mediaUrls = self::handleMediaUrls($mediaUrls, $currentSettings[$section][$key], $section);

                $formattedField = array_merge($textValues, $mediaUrls);

                $formattedField['hidden'] = Arr::isTrue($setting, 'hidden');

                $formattedField['description'] = wp_kses_post(Arr::get($setting, 'description'));

                $formattedField['description_rendered'] = FeedsHelper::mdToHtml($formattedField['description']);

                $formattedFields[$section][$key] = $formattedField;
            }
        }

        return $formattedFields;
    }

    protected static function handleMediaUrls($mediaUrls, $currentSetting, $section)
    {
        foreach ($mediaUrls as $key => $url) {
            $currentImgUrl = Arr::get($currentSetting, $key);
            if ($url) {
                $media = Helper::getMediaFromUrl($url);
                if (!$media || $media->is_active) {
                    $mediaUrls[$key] = $currentImgUrl;
                    continue;
                }

                $mediaUrls[$key] = $media->public_url;

                $media->update([
                    'is_active'     => true,
                    'user_id'       => get_current_user_id(),
                    'sub_object_id' => null,
                    'object_source' => 'auth_' . $section . '_' . $key
                ]);
            }
        }

        return $mediaUrls;
    }
}
