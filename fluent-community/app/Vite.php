<?php

namespace FluentCommunity\App;

use FluentCommunity\App\Functions\Utility;
use FluentCommunity\Framework\Support\Arr;

class Vite
{
    protected static $moduleScripts = [];
    protected static $resourceURL = 'https://localhost:4444/src/';
    protected static $assetsURL = FLUENT_COMMUNITY_PLUGIN_URL . 'assets/';

    private static function isDev()
    {
        return Utility::isDev();
    }

    public static function enqueueScript($handle, $src, $dependency = [], $version = false, $inFooter = false)
    {
        static::$moduleScripts [] = $handle;
        $src = self::generateSrc($src);
        wp_enqueue_script($handle, $src, $dependency, $version, $inFooter);
        self::addModuleToScript();
    }

    public static function enqueueStyle($handle, $src, $dependency = [], $version = false, $media = 'all')
    {
        $src = self::generateSrc($src);
        wp_enqueue_style($handle, $src, $dependency, $version, $media);
    }

    public static function enqueueStaticScript($handle, $src, $dependency = [], $version = false, $inFooter = false)
    {
        $src = static::getStaticSrcUrl($src);
        wp_enqueue_script($handle, $src, $dependency, $version, $inFooter);
    }

    public static function enqueueStaticStyle($handle, $src, $dependency = [], $version = false, $media = 'all')
    {
        $src = static::getStaticSrcUrl($src);
        wp_enqueue_style($handle, $src, $dependency, $version, $media);
    }

    private static function parseManifest()
    {
        static $manifest;
        if ($manifest) {
            return $manifest;
        }

        $config = App::getInstance()->config;
        $manifest = $config->get('app.manifest');

        if (!$manifest) {
            throw new \Exception('Manifest config could not be found on the app config');
        }

        return $manifest;
    }

    private static function addModuleToScript()
    {
        add_filter('script_loader_tag', function ($tag, $handle, $src) {
            return $tag;
        }, 10, 3);
    }

    private static function generateSrc($src, $isRtl = null)
    {
        if (!self::isDev()) {
            $manifest = self::parseManifest();
            $src = 'src/' . $src;
            $mainSrc = isset($manifest[$src]) ? $manifest[$src] : false;

            if ($mainSrc) {
                if (isset($mainSrc['css'])) {
                    foreach ($mainSrc['css'] as $css) {
                        wp_enqueue_style($css . '_css', static::$assetsURL . $css, [], '1.0.0', 'all');
                    }
                }

                if ($isRtl) {
                    $file = $manifest[$src]['file'];
                    $file = str_replace('.css', '.rtl.css', $file);
                    return static::$assetsURL . $file;
                }

                return static::$assetsURL . $manifest[$src]['file'];
            }
        }

        return static::$resourceURL . $src;
    }

    public static function getStaticSrcUrl($src, $isRtl = null)
    {
        if ($isRtl) {
            $src = str_replace('.css', '.rtl.css', $src);
        }

        return static::$assetsURL . $src;
    }

    public static function getDynamicSrcUrl($src, $isRtl = null)
    {
        return self::generateSrc($src, $isRtl);
    }

    public static function getDynamicProductionSrcUrl($src, $isRtl = null)
    {
        if (!self::isDev()) {
            return self::generateSrc($src, $isRtl);
        }

        return false;
    }

    /**
     * Build the URL an entry script is loaded with from HTML.
     *
     * The build rewrites every inter-chunk import to `<chunk>.js?ver=<version>`
     * (see src/dev/version-plugin.js), and wp_enqueue_script() emits the same
     * `?ver=` key. HTML script tags must match it exactly: to the browser
     * `app.js?version=x` and `app.js?ver=x` are two different modules, so a
     * mismatch evaluates the whole app a second time and remounts it.
     *
     * @param string $url
     * @return string
     */
    public static function versionedUrl($url)
    {
        return $url . '?ver=' . FLUENT_COMMUNITY_PLUGIN_VERSION;
    }

    /**
     * Print the import map that lets add-on bundles import core's runtime instead of bundling
     * their own: `@fluentcommunity/vue`, `@fluentcommunity/element-plus` and `@fluentcommunity/ui`.
     * One Vue and one Element Plus per page; a second copy breaks provide/inject and every <el-*>.
     *
     * Must print before any module script. Each app builds its own copy of the runtime
     * (see vite.unified.config.js), so the map points at the set that matches the page's app.
     *
     * @param string $runtime 'portal' or 'admin'
     * @return void
     */
    public static function printModuleImportMap($runtime = 'portal')
    {
        $imports = [];
        foreach ([ 'vue', 'element-plus', 'ui' ] as $name) {
            if (self::isDev()) {
                $url = static::$resourceURL . 'shared/' . $name . '.js';
            } else {
                $prefix = $runtime === 'admin' ? 'admin_' : '';
                // Same ?ver= as the chunk imports, so the browser sees the same module URLs
                $url = self::versionedUrl(static::$assetsURL . $prefix . 'runtime_' . $name . '.js');
            }
            $imports['@fluentcommunity/' . $name] = $url;
        }

        /**
         * Bare module specifiers add-ons may import on FluentCommunity pages.
         *
         * @param array  $imports Map of bare specifier => absolute URL.
         * @param string $runtime 'portal' or 'admin'.
         */
        $imports = apply_filters('fluent_community/module_import_map', $imports, $runtime);

        if (!$imports || !is_array($imports)) {
            return;
        }

        $imports = array_map('esc_url_raw', $imports);

        // JSON_HEX_TAG keeps "</script" out of the element content
        echo '<script type="importmap">' . wp_json_encode([ 'imports' => $imports ], JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Put add-on module scripts between start.js and the app.
     *
     * The app applies its route and app filters as soon as its module runs, so an add-on that
     * registers routes must run first. It also needs window.FluentCommunityUtil, which start.js
     * creates. Module scripts run in document order, so placement is the whole contract.
     *
     * @param array $dataVars Portal page data, after the portal_data_vars filter.
     * @return array
     */
    public static function insertModuleScripts($dataVars)
    {
        $runtime = Arr::get($dataVars, 'module_runtime', 'portal');

        /**
         * Module scripts of add-ons built on the shared runtime.
         *
         * @param array  $scripts Map of handle => ['url' => string]. No query string in the URL:
         *                        the page appends ?ver= to every script.
         * @param string $runtime 'portal' or 'admin'.
         */
        $scripts = apply_filters('fluent_community/module_scripts', [], $runtime);

        $jsFiles = Arr::get($dataVars, 'js_files', []);
        if (!$scripts || !is_array($scripts) || !isset($jsFiles['fcom_app'])) {
            return $dataVars;
        }

        $ordered = [];
        foreach ($jsFiles as $handle => $file) {
            if ($handle === 'fcom_app') {
                foreach ($scripts as $scriptHandle => $script) {
                    if (empty($script['url']) || isset($jsFiles[$scriptHandle])) {
                        continue;
                    }
                    $ordered[$scriptHandle] = [ 'url' => $script['url'], 'deps' => [] ];
                }
            }
            $ordered[$handle] = $file;
        }

        $dataVars['js_files'] = $ordered;

        return $dataVars;
    }

    public static function getAssetsUrl()
    {
        if (!self::isDev()) {
            return static::$assetsURL;
        }
        return static::$resourceURL;
    }
}
