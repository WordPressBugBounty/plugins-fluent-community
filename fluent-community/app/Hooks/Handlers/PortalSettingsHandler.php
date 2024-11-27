<?php

namespace FluentCommunity\App\Hooks\Handlers;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Vite;
use FluentCommunity\Framework\Support\Arr;

class PortalSettingsHandler
{
    public function register()
    {
        add_filter('fluent_community/portal_data_vars', function ($vars) {
            if (Arr::get($vars, 'route_group') != 'admin') {
                return $vars;
            }

            $isRtl = Helper::isRtl();
            unset($vars['js_files']['fcom_app_admin']);
            $vars['js_files']['fcom_app'] = [
                'url'  => Vite::getStaticSrcUrl('admin_app.js'),
                'deps' => []
            ];

            if (!Utility::isDev()) {
                $vars['css_files']['fcom_admin_vendor'] = [
                    'url' => Vite::getStaticSrcUrl('admin_app.css', $isRtl) // vite automatically build src/admin_app.css for all components also styles inside components
                ];
            }

            add_filter('fluent_community/header_vars', function ($vars) {
                $vars['menuItems'] = [];
                return $vars;
            });

            add_filter('fluent_community/will_render_default_sidebar_items', '__return_false');

            add_action('fluent_community/after_header_menu', function () {
                echo '<h4 style="margin: 0; font-size: 20px;">' . __('Portal Settings', 'fluent-community') . '</h4>';
            });

            $vars['js_vars']['fluentComAdmin']['portal_slug'] = ltrim(Helper::getPortalSlug(true), '/') . '/admin';

            // check if current route starts with admin
            return $vars;
        });

        add_action('admin_enqueue_scripts', function () {
            if (isset($_GET['page']) && $_GET['page'] === 'fluent-community') {
                wp_enqueue_style('fluent_community_admin', Vite::getStaticSrcUrl('onboarding.css'), [], FLUENT_COMMUNITY_PLUGIN_VERSION);
            }
        });


        // add a link to admin menu which will redirect to /portal
        add_action('admin_menu', function () {
            add_menu_page(
                'FluentCommunity',
                'FluentCommunity',
                'edit_posts',
                'fluent-community',
                [$this, 'showAdminPage'],
                $this->getMenuIcon(),
                130
            );
        });
    }

    public function showAdminPage()
    {
        $jsTags = ['fluent_community_onboarding'];

        add_filter('script_loader_tag', function ($tag, $handle) use ($jsTags) {
            if (!in_array($handle, $jsTags)) {
                return $tag;
            }
            $tag = str_replace(' src', ' type="module" src', $tag);
            return $tag;
        }, 10, 2);

        wp_enqueue_script('fluent_community_onboarding', Vite::getDynamicSrcUrl('Onboarding/onboarding.js'), ['jquery'], FLUENT_COMMUNITY_PLUGIN_VERSION, [
            'in_footer' => true,
            'strategy'  => 'defer',
            'type'      => 'module'
        ]);

        wp_localize_script('fluent_community_onboarding', 'fluentComAdmin', [
            'ajaxurl'             => admin_url('admin-ajax.php'),
            'rest'                => $this->getRestInfo(),
            'urls'                => [
                'site_url'      => site_url('/'),
                'portal_base'   => Helper::baseUrl('/'),
                'permalink_url' => admin_url('options-permalink.php')
            ],
            'logo'                => Helper::assetUrl('images/logo.png'),
            'is_onboarded'        => !!Utility::getOption('onboarding_sub_settings'),
            'permalink_structure' => get_option('permalink_structure'),
            'is_slug_defined'     => defined('FLUENT_COMMUNITY_PORTAL_SLUG'),
            'has_pro'             => defined('FLUENT_COMMUNITY_PRO_VERSION'),
            'upgrade_url'         => Utility::getProductUrl(false),
            'settings_page_url'   => admin_url('admin.php?page=fluent-community'),
            'is_license_page'     => isset($_GET['license']) && $_GET['license'] === 'yes',
            'license_url_page'    => defined('FLUENT_COMMUNITY_PRO_VERSION') ? admin_url('admin.php?page=fluent-community&license=yes') : '',
        ]);

        echo '<div class="wrap"><div id="fcom_onboarding_app"></div></div>';
    }

    private function getMenuIcon()
    {
        return 'data:image/svg+xml;base64,' . base64_encode('<svg width="82" height="71" viewBox="0 0 82 71" fill="none" xmlns="http://www.w3.org/2000/svg">
<path d="M25.9424 49.1832L39.6888 41.2467L47.6253 54.9931C40.0334 59.3763 30.3256 56.7751 25.9424 49.1832Z" fill="white"/>
<path fill-rule="evenodd" clip-rule="evenodd" d="M53.4348 33.3101L39.6884 41.2466L47.6249 54.993L61.3713 47.0565L53.4348 33.3101ZM67.1821 25.3734L53.4356 33.3099L61.3721 47.0564L75.1186 39.1199L67.1821 25.3734Z" fill="white"/>
<path d="M67.182 25.3736C70.978 23.182 75.8319 24.4826 78.0235 28.2786L81.9917 35.1518L75.1185 39.12L67.182 25.3736Z" fill="white"/>
<path d="M42.593 30.4052L28.8466 38.3417L20.9101 24.5953L34.6565 16.6588L42.593 30.4052Z" fill="white"/>
<path d="M56.3397 22.4683L42.5933 30.4048L34.6568 16.6584C42.2487 12.2752 51.9565 14.8764 56.3397 22.4683Z" fill="white"/>
<path d="M28.847 38.3418L15.1006 46.2783L7.16409 32.5318L20.9105 24.5953L28.847 38.3418Z" fill="white"/>
<path d="M15.1011 46.2783C11.3051 48.4699 6.4512 47.1693 4.25959 43.3733L0.291343 36.5001L7.16456 32.5319L15.1011 46.2783Z" fill="white"/>
</svg>');

    }

    protected function getRestInfo()
    {
        $app = fluentCommunityApp();
        $ns = $app->config->get('app.rest_namespace');
        $v = $app->config->get('app.rest_version');

        return [
            'base_url'  => esc_url_raw(rest_url()),
            'url'       => rest_url($ns . '/' . $v),
            'nonce'     => wp_create_nonce('wp_rest'),
            'namespace' => $ns,
            'version'   => $v,
        ];
    }
}
