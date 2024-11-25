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
        $textFields = ['type', 'title', 'button_label', 'position'];
        $colorFields = ['title_color', 'text_color', 'button_color', 'button_label_color', 'background_color'];
        $urlFields = ['logo', 'background_image'];

        $formattedFields = [];

        foreach ($settingFields as $section => $settings) {
            foreach ($settings as $key => $setting) {
                $textValues = array_map('sanitize_text_field', Arr::only($setting, $textFields));
                $colorValues = array_map('sanitize_hex_color', Arr::only($setting, $colorFields));
                $urlValues = array_map('sanitize_url', Arr::only($setting, $urlFields));

                $formattedField = array_merge($textValues, $colorValues, $urlValues);

                $formattedField['hidden'] = Arr::isTrue($setting, 'hidden');

                $formattedField['description'] = wp_kses_post(Arr::get($setting, 'description'));

                $formattedField['description_rendered'] = FeedsHelper::mdToHtml($formattedField['description']);

                if (Arr::has($setting, 'fields')) {
                    $formattedField['fields'] = array_map('sanitize_text_field', $setting['fields']);
                }

                $formattedFields[$section][$key] = $formattedField;
            }
        }

        return $formattedFields;
    }
}
