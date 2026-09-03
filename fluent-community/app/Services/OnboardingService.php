<?php

namespace FluentCommunity\App\Services;

use FluentCommunity\App\Models\Space;
use FluentCommunity\App\Models\SpaceGroup;
use FluentCommunity\Framework\Support\Arr;
use FluentCommunity\Modules\Course\Model\Course;

class OnboardingService
{
    public static function maybeCreateSpaceTemplates($template = '')
    {
        $spaceCount = Space::count();
        if ($spaceCount >= 2) {
            return false;
        }

        if (!$template || $template == 'blank') {
            return false;
        }

        self::createDefaultSpaceTemplate();

        if ($template == 'course') {
            self::createCourseTemplate();
        } else if ($template == 'product') {
            self::createProductTemplate();
        }

        return true;
    }

    protected static function createDefaultSpaceTemplate()
    {
        $spaceGroupData = [
            'title'       => 'Get Started',
            'slug'        => 'get-started',
            'description' => 'General Discussion Group',
            'status'      => 'active',
            'type'        => 'space_group',
            'settings'    => [
                'hide_members'       => 'no',
                'always_show_spaces' => 'yes'
            ],
            'serial'      => 1
        ];

        $spaceGroup = SpaceGroup::where('slug', 'get-started')->first();
        if (!$spaceGroup) {
            $spaceGroup = SpaceGroup::create($spaceGroupData);
        }

        $spacesData = [
            [
                'title'       => 'Start Here',
                'slug'        => 'start-here',
                'privacy'     => 'public',
                'description' => '',
                'settings'    => [
                    'restricted_post_only' => 'no',
                    'emoji'                => '🏠',
                    'can_request_join'     => 'yes',
                    'custom_lock_screen'   => 'no',
                    'layout_style'         => 'timeline',
                    'show_sidebar'         => 'yes'
                ],
                'parent_id'   => $spaceGroup->id,
                'serial'      => 1
            ],
            [
                'title'       => 'Say Hello',
                'slug'        => 'say-hello',
                'privacy'     => 'public',
                'description' => '',
                'settings'    => [
                    'restricted_post_only' => 'no',
                    'emoji'                => '👋',
                    'can_request_join'     => 'yes',
                    'custom_lock_screen'   => 'no',
                    'layout_style'         => 'timeline',
                    'show_sidebar'         => 'yes'
                ],
                'parent_id'   => $spaceGroup->id,
                'serial'      => 2
            ]
        ];

        foreach ($spacesData as $spaceData) {
            $space = Space::where('slug', $spaceData['slug'])->first();
            if ($space) {
                continue;
            }

            $space = Space::create($spaceData);
            /** @var Space $space */
            Helper::addToSpace($space, get_current_user_id(), 'admin');
        }

        return true;
    }

    protected static function createCourseTemplate()
    {
        $spaceGroupData = [
            'title'       => 'Courses',
            'slug'        => 'courses',
            'description' => 'Course Learning Modules',
            'status'      => 'active',
            'type'        => 'space_group',
            'settings'    => [
                'always_show_spaces' => 'yes'
            ],
            'serial'      => 2
        ];

        $spaceGroup = SpaceGroup::where('slug', 'courses')->first();

        if (!$spaceGroup) {
            $spaceGroup = SpaceGroup::create($spaceGroupData);
        } else {
            $spaceGroup = SpaceGroup::create($spaceGroupData);
        }

        $coursesData = [
            [
                'title'       => 'Demo Course 1',
                'slug'        => 'course-1',
                'privacy'     => 'private',
                'status'      => 'draft',
                'description' => '',
                'settings'    => [
                    'course_type' => 'self_paced',
                    'emoji'       => '1️⃣',
                    'shape_svg'   => ''
                ],
                'parent_id'   => $spaceGroup->id,
                'serial'      => 1
            ],
            [
                'title'       => 'Module 2',
                'slug'        => 'module-2',
                'privacy'     => 'private',
                'status'      => 'draft',
                'description' => '',
                'settings'    => [
                    'course_type' => 'self_paced',
                    'emoji'       => '2️⃣',
                    'shape_svg'   => ''
                ],
                'parent_id'   => $spaceGroup->id,
                'serial'      => 2
            ]
        ];

        foreach ($coursesData as $courseData) {
            $course = Course::where('slug', $courseData['slug'])->first();
            if ($course) {
                continue;
            }

            Course::create($courseData);
        }
    }

    protected static function createProductTemplate()
    {
        $spaceGroupData = [
            'title'       => 'Product Discussions',
            'slug'        => 'product-discussions',
            'description' => 'Product Discussion Group',
            'status'      => 'active',
            'type'        => 'space_group',
            'settings'    => [
                'always_show_spaces' => 'yes'
            ],
            'serial'      => 2
        ];

        $spaceGroup = SpaceGroup::where('slug', 'product-discussions')->first();

        if (!$spaceGroup) {
            $spaceGroup = SpaceGroup::create($spaceGroupData);
        }

        $spacesData = [
            [
                'title'       => 'Give Feedback',
                'slug'        => 'give-feedback',
                'privacy'     => 'public',
                'description' => '',
                'settings'    => [
                    'restricted_post_only' => 'no',
                    'emoji'                => '💬',
                    'can_request_join'     => 'yes',
                    'custom_lock_screen'   => 'no',
                    'layout_style'         => 'timeline',
                    'show_sidebar'         => 'yes'
                ],
                'parent_id'   => $spaceGroup->id,
                'serial'      => 1
            ],
            [
                'title'       => 'Ask for Help',
                'slug'        => 'ask-for-help',
                'privacy'     => 'public',
                'description' => '',
                'settings'    => [
                    'restricted_post_only' => 'no',
                    'emoji'                => '💬',
                    'can_request_join'     => 'yes',
                    'custom_lock_screen'   => 'no',
                    'layout_style'         => 'timeline',
                    'show_sidebar'         => 'yes'
                ],
                'parent_id'   => $spaceGroup->id,
                'serial'      => 2
            ],
            [
                'title'       => 'Announcements',
                'slug'        => 'announcements',
                'privacy'     => 'public',
                'description' => '',
                'settings'    => [
                    'restricted_post_only' => 'no',
                    'emoji'                => '📣',
                    'can_request_join'     => 'yes',
                    'custom_lock_screen'   => 'no',
                    'layout_style'         => 'timeline',
                    'show_sidebar'         => 'yes'
                ],
                'parent_id'   => $spaceGroup->id,
                'serial'      => 3
            ],
        ];

        foreach ($spacesData as $spaceData) {
            $space = Space::where('slug', $spaceData['slug'])->first();
            if ($space) {
                continue;
            }

            $space = Space::create($spaceData);
            /** @var Space $space */
            Helper::addToSpace($space, get_current_user_id(), 'admin');
        }
    }

    public static function installAddons($addons = [])
    {
        $validAddons = ['fluent-crm', 'fluent-smtp', 'fluent-cart'];
        $validAddons = array_intersect($validAddons, $addons);

        if (!$validAddons || !current_user_can('install_plugins')) {
            return;
        }

        foreach ($validAddons as $addon) {
            self::installPlugin($addon);
        }

        return true;
    }

    public static function maybeOptinUserToNewsletter($settings)
    {
        $isOptin = isset($settings['subscribe_to_newsletter']) && $settings['subscribe_to_newsletter'] === 'yes';
        if (!$isOptin) {
            return false;
        }

        $userFullName = Arr::get($settings, 'user_full_name');
        $userEmail = Arr::get($settings, 'user_email_address');

        if (!$userEmail) {
            return;
        }

        $url = 'https://fluentcommunity.co/discount-deal/?fluentcrm=1&route=contact&hash=8850223d-c62d-4e6a-8108-b04c7e9e4fdb';

        $response = wp_safe_remote_post($url, [
            'timeout'     => 10,
            'redirection' => 0,
            'body'        => json_encode([ // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
                'full_name'       => $userFullName,
                'email'           => $userEmail,
                'source'          => 'fcom_plugin',
                'optin_website'   => home_url(),
                'share_essential' => Arr::get($settings, 'share_data', 'no') === 'yes' ? 'yes' : 'no',
            ])
        ]);

        return true;
    }

    private static function installPlugin($pluginSlug)
    {
        $plugin = [
            'name'      => $pluginSlug,
            'repo-slug' => $pluginSlug,
            'file'      => $pluginSlug . '.php'
        ];

        $UrlMaps = [
            'fluentform' => [
                'admin_url' => admin_url('admin.php?page=fluent_forms'),
                'title'     => 'Go to Fluent Forms Dashboard',
            ],
            'fluent-crm' => [
                'admin_url' => admin_url('admin.php?page=fluentcrm-admin'),
                'title'     => 'Go to FluentCRM Dashboard'
            ],
            'fluent-smtp' => [
                'admin_url' => admin_url('options-general.php?page=fluent-mail#/'),
                'title'     => 'Go to FluentSMTP Dashboard'
            ],
            'fluent-cart' => [
                'admin_url' => admin_url('admin.php?page=fluent-cart#/'),
                'title'     => 'Go to FluentCart Dashboard'
            ]
        ];

        if (!isset($UrlMaps[$pluginSlug]) || (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS)) {
            return new \WP_Error('invalid_plugin', __('Invalid plugin or file mods are disabled.', 'fluent-community'));
        }

        try {
            return self::backgroundInstaller($plugin);
        } catch (\Exception $exception) {
            return new \WP_Error('plugin_install_error', $exception->getMessage());
        }
    }

    /**
     * Install and activate a plugin. Resolves the package from wordpress.org
     * unless $downloadUrl is given, for plugins hosted outside the repo.
     */
    public static function backgroundInstaller($plugin_to_install, $downloadUrl = null)
    {
        if (empty($plugin_to_install['repo-slug'])) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        WP_Filesystem();

        $installedPlugins = array_reduce(array_keys(\get_plugins()), array(self::class, 'associate_plugin_file'), array());

        $pluginSlug = $plugin_to_install['repo-slug'];
        $pluginFile = isset($plugin_to_install['file']) ? $plugin_to_install['file'] : $pluginSlug . '.php';
        $isInstalled = isset($installedPlugins[$pluginFile]);

        if ($isInstalled) {
            $needsActivation = !is_plugin_active($installedPlugins[$pluginFile]);
        } else {
            $upgrader = new \WP_Upgrader(new \Automatic_Upgrader_Skin());

            // The upgrader prints progress markup that would corrupt the response.
            ob_start();

            try {
                $package = $downloadUrl;

                if (!$package) {
                    $information = plugins_api('plugin_information', array(
                        'slug'   => $pluginSlug,
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
                    ));
                    if (is_wp_error($information)) {
                        throw new \Exception(wp_kses_post($information->get_error_message()));
                    }

                    $package = $information->download_link;
                }

                $download = $upgrader->download_package($package);
                if (is_wp_error($download)) {
                    throw new \Exception(wp_kses_post($download->get_error_message()));
                }

                $workingDir = $upgrader->unpack_package($download, true);
                if (is_wp_error($workingDir)) {
                    throw new \Exception(wp_kses_post($workingDir->get_error_message()));
                }

                $installed = $upgrader->install_package(array(
                    'source'                      => $workingDir,
                    'destination'                 => WP_PLUGIN_DIR,
                    'clear_destination'           => false,
                    'abort_if_destination_exists' => false,
                    'clear_working'               => true,
                    'hook_extra'                  => array(
                        'type'   => 'plugin',
                        'action' => 'install',
                    ),
                ));
                if (is_wp_error($installed)) {
                    throw new \Exception(wp_kses_post($installed->get_error_message()));
                }
            } finally {
                ob_end_clean();
            }

            $needsActivation = true;
        }

        wp_clean_plugins_cache();

        if (!$needsActivation) return;

        $activated = activate_plugin($isInstalled ? $installedPlugins[$pluginFile] : $pluginSlug . '/' . $pluginFile);

        if (is_wp_error($activated)) {
            throw new \Exception(wp_kses_post($activated->get_error_message()));
        }
    }

    private static function associate_plugin_file($plugins, $key)
    {
        $path = explode('/', $key);
        $filename = end($path);
        $plugins[$filename] = $key;
        return $plugins;
    }
}
