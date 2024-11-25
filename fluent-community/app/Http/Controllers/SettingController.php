<?php

namespace FluentCommunity\App\Http\Controllers;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\Framework\Http\Request\Request;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\Framework\Support\Sanitizer;

class SettingController extends Controller
{
    public function getFeatures()
    {
        return [
            'features' => Utility::getFeaturesConfig(),
            'addOns'   => $this->getAddons()
        ];
    }

    public function setFeatures(Request $request)
    {
        $request->validate([
            'features.giphy_api_key' => 'required_if:features.giphy_module,yes|string',
        ]);

        $data = $request->get('features', []);

        $data = Sanitizer::sanitize($data, [
            'leader_board_module' => 'sanitize_text_field',
            'course_module'       => 'sanitize_text_field',
            'giphy_module'        => 'sanitize_text_field',
            'giphy_api_key'       => 'sanitize_text_field',
            'cloud_storage'       => 'sanitize_text_field',
            'emoji_module'        => 'sanitize_text_field',
            'user_badge'          => 'sanitize_text_field',
        ]);

        Utility::updateOption('fluent_community_features', $data);

        return [
            'message' => __('Features saved successfully.', 'fluent-community')
        ];
    }

    public function getMenuSettings(Request $request)
    {
        $menuGroups = Helper::getMenuItemsGroup('edit');
        $formattedGroups = [];

        foreach ($menuGroups as $indexName => $menuGroup) {
            if (is_array($menuGroup)) {
                $formattedGroups[$indexName] = array_values($menuGroup);
            } else {
                $formattedGroups[$indexName] = [];
            }
        }


        if (empty($formattedGroups['afterCommunityLinkGroups']) || !is_array($formattedGroups['afterCommunityLinkGroups'])) {
            $formattedGroups['afterCommunityLinkGroups'] = [
                [
                    'title' => __('Links', 'fluent-community'),
                    'slug'  => 'custom_footer_links',
                    'items' => []
                ]
            ];
        } else {
            foreach ($formattedGroups['afterCommunityLinkGroups'] as &$group) {
                if (is_array($group) && !empty($group['items'])) {
                    $group['items'] = array_values($group['items']);
                } else {
                    $group = [
                        'items' => []
                    ];
                }
            }
        }

        return [
            'menuSettings' => $formattedGroups
        ];
    }

    public function saveMenuSettings(Request $request)
    {
        $menuSettings = $request->get('menuSettings', []);

        $mainMenuItems = $menuSettings['mainMenuItems'] ?? [];
        $profileDropdownItems = $menuSettings['profileDropdownItems'] ?? [];
        $beforeCommunityMenuItems = $menuSettings['beforeCommunityMenuItems'] ?? [];
        $afterCommunityLinkGroups = $menuSettings['afterCommunityLinkGroups'] ?? [];

        $previousSettings = Helper::getMenuItemsGroup('edit');

        $menuSettings['mainMenuItems'] = $this->formatMenuGroup($mainMenuItems, $previousSettings['mainMenuItems']);
        $menuSettings['profileDropdownItems'] = $this->formatMenuGroup($profileDropdownItems, $previousSettings['profileDropdownItems']);
        $menuSettings['beforeCommunityMenuItems'] = $this->formatMenuGroup($beforeCommunityMenuItems, $previousSettings['beforeCommunityMenuItems']);

        $formattedAfterCommunityGroups = [];
        foreach ($afterCommunityLinkGroups as $afterCommunityLinkGroup) {
            if (empty($afterCommunityLinkGroup['items']) || empty($afterCommunityLinkGroup['title'])) {
                continue;
            }

            $formattedAfterCommunityGroups[] = [
                'title' => sanitize_text_field($afterCommunityLinkGroup['title']),
                'slug'  => $afterCommunityLinkGroup['slug'] ?: 'custom_footer_group_' . time(),
                'items' => $this->formatMenuGroup($afterCommunityLinkGroup['items'], [])
            ];
        }

        $menuSettings['afterCommunityLinkGroups'] = $formattedAfterCommunityGroups;
        $menuSettings = Arr::only($menuSettings, ['mainMenuItems', 'profileDropdownItems', 'beforeCommunityMenuItems', 'afterCommunityLinkGroups']);

        Utility::updateOption('fluent_community_menu_groups', $menuSettings);

        return [
            'message' => __('Menu settings saved successfully.', 'fluent-community')
        ];
    }

    private function formatMenuGroup($mainMenuItems, $savedMenuItems)
    {
        $formattedMainMenuItems = [];
        foreach ($mainMenuItems as $item) {
            $slug = (string)Arr::get($item, 'slug');
            if (!Arr::get($item, 'title') || !Arr::get($item, 'permalink')) {
                continue;
            }

            if (!$slug) {
                $slug = 'custom_' . sanitize_title(Arr::get($item, 'title')) . time();
                $item['slug'] = $slug;
                $item['is_custom'] = 'yes';
            }

            $defaultItem = Arr::get($savedMenuItems, $slug, []);
            if ($defaultItem) {
                $preservedKeys = ['is_system', 'is_locked', 'is_unavailable', 'slug'];
                foreach ($preservedKeys as $key) {
                    if (isset($defaultItem[$key])) {
                        $item[$key] = Arr::get($defaultItem, $key);
                    }
                }
            }

            $item = \FluentCommunity\App\Services\CustomSanitizer::sanitizeMenuLink($item);

            $formattedMainMenuItems[$slug] = $item;
        }

        return $formattedMainMenuItems;
    }

    public function getAddons()
    {
        return [
            'fluent-messaging' => [
                'is_repo'        => false,
                'title'          => __('FluentCommunity Chat', 'fluent-community'),
                'logo'           => Helper::assetUrl('images/brands/fluent-messages.svg'),
                'is_installed'   => defined('FLUENT_MESSAGING_CHAT_VERSION'),
                'learn_more_url' => 'https://fluentcommunity.co',
                'settings_url'   => Helper::baseUrl('chat'),
                'action_text'    => $this->isPluginInstalled('fluent-messaging/fluent-messaging.php') ? __('Active FluentCommunity Chat', 'fluent-community') : __('Install FluentCommunity Chat', 'fluent-community'),
                'description'    => __('FluentCommunity Chat is a real-time chat plugin for WordPress. It allows you to create a chat room for your community members.', 'fluent-community')
            ],
            'fluent-crm'       => [
                'is_repo'        => true,
                'title'          => __('FluentCRM', 'fluent-community'),
                'logo'           => Helper::assetUrl('images/brands/fluentcrm.svg'),
                'is_installed'   => defined('FLUENTCRM'),
                'learn_more_url' => 'https://fluentcrm.com',
                'settings_url'   => admin_url('admin.php?page=fluentcrm-admin#/'),
                'action_text'    => $this->isPluginInstalled('fluent-crm/fluent-crm.php') ? __('Active FluentCRM', 'fluent-community') : __('Install FluentCRM', 'fluent-community'),
                'description'    => __('The Best Email Marketing Automation Plugin for WordPress. Capture, Segment and Automate your Marketing.', 'fluent-community')
            ],
            'fluentform'       => [
                'is_repo'        => true,
                'title'          => __('Fluent Forms', 'fluent-community'),
                'logo'           => Helper::assetUrl('images/brands/fluentform.png'),
                'is_installed'   => defined('FLUENTFORM'),
                'learn_more_url' => 'https://wordpress.org/plugins/fluentform/',
                'settings_url'   => admin_url('admin.php?page=fluent_forms'),
                'action_text'    => $this->isPluginInstalled('fluent-form/fluent-form.php') ? __('Active Fluent Forms', 'fluent-community') : __('Install Fluent Forms', 'fluent-community'),
                'description'    => __('Collect leads and build any type of forms, accept payments, connect with your CRM with the Fastest Contact Form Builder Plugin for WordPress', 'fluent-community')
            ],
            'fluent-smtp'      => [
                'is_repo'        => true,
                'title'          => __('Fluent SMTP', 'fluent-community'),
                'logo'           => Helper::assetUrl('images/brands/fluent-smtp.svg'),
                'is_installed'   => defined('FLUENTMAIL'),
                'learn_more_url' => 'https://wordpress.org/plugins/fluent-smtp/',
                'settings_url'   => admin_url('options-general.php?page=fluent-mail#/'),
                'action_text'    => $this->isPluginInstalled('fluent-smtp/fluent-smtp.php') ? __('Active Fluent SMTP', 'fluent-community') : __('Install Fluent SMTP', 'fluent-community'),
                'description'    => __('Fluent SMTP is the ultimate SMTP and SES plugin for WordPress. Connect with any SMTP service, including SendGrid, Mailgun, SES, Sendinblue, PepiPost, Google, Microsoft, and more.', 'fluent-community')
            ],
            'fluent-support'   => [
                'is_repo'        => true,
                'title'          => __('Fluent Support', 'fluent-community'),
                'logo'           => Helper::assetUrl('images/brands/fluent-support.svg'),
                'is_installed'   => defined('FLUENT_SUPPORT_VERSION'),
                'learn_more_url' => 'https://wordpress.org/plugins/fluent-connect/',
                'settings_url'   => admin_url('admin.php?page=fluent-support#/'),
                'action_text'    => $this->isPluginInstalled('fluent-support/fluent-support.php') ? __('Active Fluent Support', 'fluent-community') : __('Install Fluent Support', 'fluent-community'),
                'description'    => __('WordPress Helpdesk and Customer Support Ticket Plugin. Provide awesome support and manage customer queries right from your WordPress dashboard.', 'fluent-community')
            ]
        ];
    }

    private function isPluginInstalled($plugin)
    {
        return file_exists(WP_PLUGIN_DIR . '/' . $plugin);
    }

    public function installPlugin(Request $request)
    {
        if (!current_user_can('install_plugins')) {
            return $this->sendError([
                'message' => 'You do not have permission to install plugins'
            ]);
        }

        $pluginSlug = $request->get('plugin');

        $addons = $this->getAddons();

        if (!isset($addons[$pluginSlug])) {
            return $this->sendError('Invalid Plugin');
        }

        $details = $addons[$pluginSlug];

        if ($details['is_repo']) {
            $plugin = [
                'name'      => $details['title'],
                'repo-slug' => $pluginSlug,
                'file'      => $pluginSlug . '.php',
            ];

            $this->backgroundInstaller($plugin, $pluginSlug);
        } else {
            if ($pluginSlug == 'fluent-messaging') {

                if (!defined('FLUENT_COMMUNITY_PRO')) {
                    return $this->sendError('Fluent Messaging is a Pro Plugin. Please install Fluent Space Pro first.');
                }

                do_action('fleunt_community/install_messaging_plugin');
            }
        }

        return [
            'message'      => __('Plugin has been installed Successfully', 'fluent-community'),
            'is_installed' => true
        ];
    }

    private function backgroundInstaller($plugin_to_install, $plugin_id)
    {
        if (!empty($plugin_to_install['repo-slug'])) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/plugin.php';

            WP_Filesystem();

            $skin = new \Automatic_Upgrader_Skin();
            $upgrader = new \WP_Upgrader($skin);
            $installed_plugins = array_reduce(array_keys(\get_plugins()), array($this, 'associate_plugin_file'),
                array());
            $plugin_slug = $plugin_to_install['repo-slug'];
            $plugin_file = isset($plugin_to_install['file']) ? $plugin_to_install['file'] : $plugin_slug . '.php';
            $installed = false;
            $activate = false;

            // See if the plugin is installed already.
            if (isset($installed_plugins[$plugin_file])) {
                $installed = true;
                $activate = !is_plugin_active($installed_plugins[$plugin_file]);
            }

            // Install this thing!
            if (!$installed) {
                // Suppress feedback.
                ob_start();

                try {
                    $plugin_information = plugins_api(
                        'plugin_information',
                        array(
                            'slug'   => $plugin_slug,
                            'fields' => array(
                                'short_description' => false,
                                'sections'          => false,
                                'requires'          => false,
                                'rating'            => false,
                                'ratings'           => false,
                                'downloaded'        => false,
                                'last_updated'      => false,
                                'added'             => false,
                                'tags'              => false,
                                'homepage'          => false,
                                'donate_link'       => false,
                                'author_profile'    => false,
                                'author'            => false,
                            ),
                        )
                    );

                    if (is_wp_error($plugin_information)) {
                        throw new \Exception($plugin_information->get_error_message());
                    }

                    $package = $plugin_information->download_link;
                    $download = $upgrader->download_package($package);

                    if (is_wp_error($download)) {
                        throw new \Exception($download->get_error_message());
                    }

                    $working_dir = $upgrader->unpack_package($download, true);

                    if (is_wp_error($working_dir)) {
                        throw new \Exception($working_dir->get_error_message());
                    }

                    $result = $upgrader->install_package(
                        array(
                            'source'                      => $working_dir,
                            'destination'                 => WP_PLUGIN_DIR,
                            'clear_destination'           => false,
                            'abort_if_destination_exists' => false,
                            'clear_working'               => true,
                            'hook_extra'                  => array(
                                'type'   => 'plugin',
                                'action' => 'install',
                            ),
                        )
                    );

                    if (is_wp_error($result)) {
                        throw new \Exception($result->get_error_message());
                    }

                    $activate = true;
                } catch (\Exception $e) {
                }

                // Discard feedback.
                ob_end_clean();
            }

            wp_clean_plugins_cache();

            // Activate this thing.
            if ($activate) {
                try {
                    $result = activate_plugin($installed ? $installed_plugins[$plugin_file] : $plugin_slug . '/' . $plugin_file);

                    if (is_wp_error($result)) {
                        throw new \Exception($result->get_error_message());
                    }
                } catch (\Exception $e) {
                }
            }
        }
    }

    private function associate_plugin_file($plugins, $key)
    {
        $path = explode('/', $key);
        $filename = end($path);
        $plugins[$filename] = $key;
        return $plugins;
    }


    public function getCustomizationSettings(Request $request)
    {
        return [
            'settings' => Utility::getCustomizationSettings()
        ];
    }

    public function updateCustomizationSettings(Request $request)
    {
        $settings = $request->get('settings', []);

        $yesNoFields = [
            'dark_mode', 'fixed_page_header', 'show_powered_by', 'feed_link_on_sidebar', 'fixed_sidebar', 'icon_on_header_menu'
        ];

        foreach ($settings as $key => $value) {
            if (in_array($key, $yesNoFields)) {
                $settings[$key] = $value == 'yes' ? 'yes' : 'no';
            } else if ($key == 'affiliate_id') {
                $settings[$key] = (int)$value;
                if (!$settings[$key]) {
                    $settings[$key] = '';
                }
            } else {
                $settings[$key] = sanitize_text_field($value);
            }
        }

        Utility::updateCustomizationSettings($settings);

        return [
            'message' => __('Customization settings have been saved successfully.', 'fluent-community')
        ];
    }

    public function getPrivacySettings(Request $request)
    {
        return [
            'settings' => Utility::getPrivacySettings()
        ];
    }

    public function updatePrivacySettings(Request $request)
    {
        $settings = $request->get('settings', []);
        Utility::updatePrivacySettings($settings);

        return [
            'message' => __('Privacy settings have been saved successfully.', 'fluent-community')
        ];
    }

    public function getColorConfig(Request $request)
    {
        $config = Utility::getColorConfig('edit');
        $schemas = Utility::getColorSchemas();

        return [
            'config'  => $config,
            'schemas' => $schemas
        ];
    }

}
