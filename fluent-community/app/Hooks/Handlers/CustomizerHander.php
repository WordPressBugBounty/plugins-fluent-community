<?php

namespace FluentCommunity\App\Hooks\Handlers;

use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Vite;
use FluentCommunity\Framework\Support\Arr;

class CustomizerHander
{
    public function register()
    {
        add_filter('fluent_community/portal_data_vars', function ($vars) {
            if (Arr::get($vars, 'route_group') == 'admin' && Helper::isSiteAdmin()) {
                add_action('fluent_community/before_header_right_menu_items', function () {
                    ?>
                    <li>
                        <a style="border: 1px solid; padding: 3px 15px; margin-right: 8px; color: var(--fcom-menu-text, #545861);background: var(--fcom-active-bg, #f0f3f5);"
                           href="<?php echo esc_url(Helper::baseUrl('/?customizer_panel=1')); ?>"><?php _e('Customize Colors', 'fluent-community'); ?></a>
                    </li>
                    <?php
                });
            } else if (isset($_GET['customizer_panel']) && Helper::isSiteAdmin()) {

                $vars['css_files'] = array_merge($vars['css_files'], [
                    'customizer'     => [
                        'url' => Vite::getStaticSrcUrl('customizer.css')
                    ],
                    'customizer_app' => [
                        'url' => Vite::getStaticSrcUrl('customizer_app.css')
                    ],
                ]);

                add_action('fluent_community/before_portal_rendered', [$this, 'pushCustomizer'], 10, 1);
                $vars['js_vars']['customizerI18n'] = [
                    'Light Mode'                                                                                                                                    => __('Light Mode', 'fluent-community'),
                    'Dark Mode'                                                                                                                                     => __('Dark Mode', 'fluent-community'),
                    'color_inst'                                                                                                                                    => __('The following styles will be applied when a member views your community in'),
                    'Exit'                                                                                                                                          => __('Exit', 'fluent-community'),
                    'Save Settings'                                                                                                                                 => __('Save Settings', 'fluent-community'),
                    'Color Schema'                                                                                                                                  => __('Color Schema', 'fluent-community'),
                    'Select Color Schema'                                                                                                                           => __('Select Color Schema', 'fluent-community'),
                    'Header'                                                                                                                                        => __('Header', 'fluent-community'),
                    'Background color of the header area'                                                                                                           => __('Background color of the header area', 'fluent-community'),
                    'Background'                                                                                                                                    => __('Background', 'fluent-community'),
                    'Link or Button colors of the header area'                                                                                                      => __('Link or Button colors of the header area', 'fluent-community'),
                    'Text/Link'                                                                                                                                     => __('Text/Link', 'fluent-community'),
                    'Background color of the active item in the top menu'                                                                                           => __('Background color of the active item in the top menu', 'fluent-community'),
                    'Active Item Background'                                                                                                                        => __('Active Item Background', 'fluent-community'),
                    'Text color of the active item in the top menu'                                                                                                 => __('Text color of the active item in the top menu', 'fluent-community'),
                    'Active Item Color'                                                                                                                             => __('Active Item Color', 'fluent-community'),
                    'Background color of the hovered item in the top menu'                                                                                          => __('Background color of the hovered item in the top menu', 'fluent-community'),
                    'Hover Background'                                                                                                                              => __('Hover Background', 'fluent-community'),
                    'Text color of the hovered item in the top menu'                                                                                                => __('Text color of the hovered item in the top menu', 'fluent-community'),
                    'Hover Color'                                                                                                                                   => __('Hover Color', 'fluent-community'),
                    'The main background color of the sidebar'                                                                                                      => __('The main background color of the sidebar', 'fluent-community'),
                    'The text/link color of the sidebar'                                                                                                            => __('The text/link color of the sidebar', 'fluent-community'),
                    'Background color of the active item in the sidebar'                                                                                            => __('Background color of the active item in the sidebar', 'fluent-community'),
                    'Text color of the active item in the sidebar'                                                                                                  => __('Text color of the active item in the sidebar', 'fluent-community'),
                    'Background color of the hovered item in the sidebar'                                                                                           => __('Background color of the hovered item in the sidebar', 'fluent-community'),
                    'Text color of the hovered item in the sidebar'                                                                                                 => __('Text color of the hovered item in the sidebar', 'fluent-community'),
                    'Background color of the main area'                                                                                                             => __('Background color of the main area', 'fluent-community'),
                    'Body Background'                                                                                                                               => __('Body Background', 'fluent-community'),
                    'Background color of the primary content area like sub header and post content'                                                                 => __('Background color of the primary content area like sub header and post content', 'fluent-community'),
                    'Primary Content Background'                                                                                                                    => __('Primary Content Background', 'fluent-community'),
                    'Background color of the secondary content area like each comment'                                                                              => __('Background color of the secondary content area like each comment', 'fluent-community'),
                    'Secondary Content Background'                                                                                                                  => __('Secondary Content Background', 'fluent-community'),
                    'Text color of the main area this includes post, comment, headings'                                                                             => __('Text color of the main area this includes post, comment, headings', 'fluent-community'),
                    'Text Color'                                                                                                                                    => __('Text Color', 'fluent-community'),
                    'Text color of the secondary area this includes post meta, comment meta'                                                                        => __('Text color of the secondary area this includes post meta, comment meta', 'fluent-community'),
                    'Off Text Color'                                                                                                                                => __('Off Text Color', 'fluent-community'),
                    'Border color of the main sections'                                                                                                             => __('Border color of the main sections', 'fluent-community'),
                    'Primary Border Color'                                                                                                                          => __('Primary Border Color', 'fluent-community'),
                    'order color of the secondary sections'                                                                                                         => __('order color of the secondary sections', 'fluent-community'),
                    'Secondary Border Color'                                                                                                                        => __('Secondary Border Color', 'fluent-community'),
                    'Navigations'                                                                                                                                   => __('Navigations', 'fluent-community'),
                    'Color of the links in the main content area.'                                                                                                  => __('Color of the links in the main content area.', 'fluent-community'),
                    'Link Color'                                                                                                                                    => __('Link Color', 'fluent-community'),
                    'Background color of the primary buttons. This includes the buttons in the post, comment, and forms.'                                           => __('Background color of the primary buttons. This includes the buttons in the post, comment, and forms.', 'fluent-community'),
                    'Primary Button Background'                                                                                                                     => __('Primary Button Background', 'fluent-community'),
                    'Text color of the primary buttons. This includes the buttons in the post, comment, and forms.'                                                 => __('Text color of the primary buttons. This includes the buttons in the post, comment, and forms.', 'fluent-community'),
                    'Primary Button Text Color'                                                                                                                     => __('Primary Button Text Color', 'fluent-community'),
                    'Background color of the secondary buttons. This includes the buttons in the space, user profile, and other secondary headers.'                 => __('Background color of the secondary buttons. This includes the buttons in the space, user profile, and other secondary headers.', 'fluent-community'),
                    'Secondary Nav Text Color'                                                                                                                      => __('Secondary Nav Text Color', 'fluent-community'),
                    'Background color of the secondary buttons on active state. This includes the buttons in the space, user profile, and other secondary headers.' => __('Background color of the secondary buttons on active state. This includes the buttons in the space, user profile, and other secondary headers.', 'fluent-community'),
                    'Secondary Nav Active Background'                                                                                                               => __('Secondary Nav Active Background', 'fluent-community'),
                    'Text color of the secondary buttons on active state. This includes the buttons in the space, user profile, and other secondary headers.'       => __('Text color of the secondary buttons on active state. This includes the buttons in the space, user profile, and other secondary headers.', 'fluent-community'),
                    'Secondary Nav Active Color'                                                                                                                    => __('Secondary Nav Active Color', 'fluent-community'),
                    'Sidebar'                                                                                                                                       => __('Sidebar', 'fluent-community'),
                    'General'                                                                                                                                       => __('General', 'fluent-comunity'),
                    'Save Settings (Pro Required)' => __('Save Settings (Pro Required)', 'fluent-community'),
                ];
            }

            return $vars;
        });
    }

    public function pushCustomizer($data)
    {
        add_action('fluent_community/portal_footer', function () {
            $jsFiles = [
                'customizer_app' => [
                    'url' => Vite::getDynamicSrcUrl('customizer/customizer_app.js')
                ],
            ];

            foreach ($jsFiles as $file) {
                ?>
                <script type="module"
                        src="<?php echo esc_url($file['url']); ?>?version=<?php echo esc_attr(FLUENT_COMMUNITY_PLUGIN_VERSION); ?>"
                        defer="defer"></script>
                <?php
            }
        }, 1);

        add_action('fluent_community/before_portal_dom', function () {
            ?>
            <div id="fcom_customizer_panel">

            </div>
            <?php
        });
    }

}
