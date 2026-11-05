<?php

namespace FluentCommunity\Modules;

use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Vite;
use FluentCommunity\Framework\Foundation\Application;
use FluentCommunity\Framework\Support\Arr;

class FeaturesHandler
{
    public function register(Application $app)
    {
        add_filter('fluent_community/portal_page_headless', '__return_true');

        $this->registerModules($app);

        add_action('fluent_community/portal_header', [ $this, 'maybePushDarkModeScript' ]);
        add_action('fluent_community/portal_sidebar', [ $this, 'maybePushDarkModeScript' ]);
        add_action('init', [ $this, 'maybeOembedRequest' ]);

        add_action('fluent_community/rendering_headless_portal', [ $this, 'loadHeadlessPortalAssets' ]);
    }

    public function loadHeadlessPortalAssets($data)
    {
        add_action('fluent_community/portal_head', function () use ($data) {
            $cssFiles = Arr::get($data, 'css_files', []);
            foreach ($cssFiles as $file) {
                ?>
                <link rel="stylesheet" href="<?php echo esc_url($file['url']); ?>?version=<?php echo esc_attr(FLUENT_COMMUNITY_PLUGIN_VERSION); ?>" media="screen"/> <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet ?>
                <?php
            }
            $jsFiles = Arr::get($data, 'header_js_files', []);
            foreach ($jsFiles as $file) {
                $jsVars = Arr::get($file, 'vars', []);
                foreach ($jsVars as $varKey => $values) {
                    ?>
                    <script>
                        var <?php echo esc_attr($varKey); ?> = <?php echo wp_json_encode($values); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
                    </script>
                    <?php } ?>
                    <script type="module" src="<?php echo esc_url(Vite::versionedUrl($file['url'])); ?>" defer="defer"></script> <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript ?>
                    <?php
            }
        });

        add_action('fluent_community/before_js_loaded', function () use ($data) {
            $jsVars = Arr::get($data, 'js_vars', []);
            ?>
            <script>
                <?php foreach ($jsVars as $varKey => $values): ?>
                var <?php echo esc_attr($varKey); ?> = <?php echo wp_json_encode($values); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
                <?php endforeach; ?>
            </script>
            <?php
        });

        add_action('fluent_community/portal_footer', function () use ($data) {
            $jsFiles = Arr::get($data, 'js_files', []);
            foreach ($jsFiles as $file) {
                ?>
                <script type="module" src="<?php echo esc_url(Vite::versionedUrl($file['url'])); ?>" defer="defer"></script> <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript ?>
                <?php
            }
        });

    }

    public function maybePushDarkModeScript()
    {

    }

    /**
     * Serves core's /oembed/1.0/proxy to community users. Core requires edit_posts, which
     * community editors (lessons, space pages) often lack.
     */
    public function maybeOembedRequest()
    {
        if (!isset($_GET['url'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $path = untrailingslashit((string) wp_parse_url(Arr::get($_SERVER, 'REQUEST_URI', ''), PHP_URL_PATH));
        $restRoute = untrailingslashit(sanitize_text_field(wp_unslash($_GET['rest_route'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!str_ends_with($path, '/oembed/1.0/proxy') && $restRoute !== '/oembed/1.0/proxy') {
            return;
        }

        $user = Helper::getCurrentUser();
        if (!$user || !Helper::canAccessPortal($user->ID)) {
            return; // Core answers with its own edit_posts check.
        }

        // The isolated block editor sends no nonce. Browsers send Sec-Fetch-Site: same-origin
        // only from our own pages, so a cross-site page cannot trigger the fetch.
        $nonce = sanitize_text_field(wp_unslash(Arr::get($_SERVER, 'HTTP_X_WP_NONCE', '')));
        if (!wp_verify_nonce($nonce, 'wp_rest') && Arr::get($_SERVER, 'HTTP_SEC_FETCH_SITE') !== 'same-origin') {
            return;
        }

        try {
            do_action('fluent_community/check_rate_limit/oembed', $user);
        } catch (\Exception $e) {
            wp_send_json([ 'error' => $e->getMessage() ], 429);
        }

        // Core's route args only: core keys its cache on every param, so extras would add cache rows.
        $params = Arr::only(wp_unslash($_GET), [ 'url', 'maxwidth', 'maxheight', 'format', 'discover' ]); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $params['url'] = sanitize_url($params['url']);
        $params += [ 'maxwidth' => 600 ];

        $request = new \WP_REST_Request('GET', '/oembed/1.0/proxy');
        $request->set_query_params($params);

        // Core's handler, with its per-URL cache.
        $data = (new \WP_oEmbed_Controller())->get_proxy_item($request);

        if (is_wp_error($data)) {
            wp_send_json([ 'error' => 'not_found' ], 404);
        }

        wp_send_json($data, 200);
    }

    private function registerModules($app)
    {
        if (Helper::isFeatureEnabled('course_module')) {
            // Regitser the modules here
            (new \FluentCommunity\Modules\Course\CourseModule())->register($app);
        }

        if (apply_filters('fluent_community/use_editor_block', true)) {
            (new \FluentCommunity\Modules\Gutenberg\EditorBlock())->register($app);
        }

        (new \FluentCommunity\Modules\Auth\AuthModdule())->register($app);
    }
}
