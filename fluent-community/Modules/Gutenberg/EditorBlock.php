<?php

namespace FluentCommunity\Modules\Gutenberg;

use FluentCommunity\App\Services\Helper;
use FluentCommunity\App\Vite;

class EditorBlock
{
    public function register($app)
    {
        add_action('init', [$this, 'registerBlock']);
    }

    public function registerBlock()
    {
        global $pagenow;
        if ($pagenow == 'site-editor.php') {
            $asset_file = include(plugin_dir_path(__FILE__) . 'build/index.asset.php');
            wp_register_style(
                'custom-layout-block-editor-style',
                plugins_url('build/index.css', __FILE__),
                array(),
                $asset_file['version']
            );

            wp_register_style('fluent_community_global', Vite::getDynamicSrcUrl('global.scss'), [], FLUENT_COMMUNITY_PLUGIN_VERSION, 'screen');

            wp_register_script(
                'custom-layout-block-editor',
                plugins_url('build/index.js', __FILE__),
                $asset_file['dependencies'],
                $asset_file['version']
            );
        }
        if (function_exists('\register_block_type')) {
            \register_block_type(
                'fluent-community/page-layout',
                array(
                    'editor_script'   => 'custom-layout-block-editor',
                    'editor_style'    => 'custom-layout-block-editor-style',
                    'style'           => 'fluent_community_global',
                    'render_callback' => [$this, 'render'],
                    'supports'        => [
                        'html' => false
                    ],
                    'attributes'      => array(
                        'showPageHeader'      => array(
                            'type'    => 'boolean',
                            'default' => true,
                        ),
                        'useFullWidth'        => array(
                            'type'    => 'boolean',
                            'default' => false,
                        ),
                        'hideCommunityHeader' => array(
                            'type'    => 'boolean',
                            'default' => false,
                        ),
                        'useBuildInTheme'     => array(
                            'type'    => 'boolean',
                            'default' => true,
                        ),
                    ),
                )
            );
        }
    }

    public function render($attributes, $content)
    {
        static $isLoaded;
        if ($isLoaded) {
            return 'Space Layout is already loaded before';
        }

        if (!$isLoaded) {
            $isLoaded = true;
        }

        $contenx = 'wp';
        if (isset($_REQUEST['context']) && $_REQUEST['context'] == 'edit' && Helper::isSiteAdmin()) {
            $contenx = 'block_editor';
        }

        $useBuildInTheme = $attributes['useBuildInTheme'] ?? false;

        do_action('fluent_community/enqueue_global_assets', $useBuildInTheme);
        $showHeader = $attributes['showPageHeader'] ?? true;
        $useFullWidth = $attributes['useFullWidth'] ?? true;
        $hideCommunityHeader = $attributes['hideCommunityHeader'] ?? false;

        ob_start();
        ?>
        <div class="fluent_com fluent_com_wp_pages">
            <div class="fhr_wrap">
                <?php if (!$hideCommunityHeader): ?>
                    <?php do_action('fluent_community/portal_header', $contenx); ?>
                <?php endif; ?>
                <div class="fhr_content">
                    <div class="fhr_home">
                        <div class="feed_layout">
                            <div class="spaces">
                                <div id="fluent_community_sidebar_menu" class="space_contents">
                                    <!--                                    start-->
                                    <?php do_action('fluent_community/portal_sidebar', $contenx); ?>
                                    <!--                                    end-->
                                </div>
                            </div>
                            <div class="fcom_wp_page">
                                <?php if ($showHeader): ?>
                                    <div class="fhr_content_layout_header">
                                        <?php if ($contenx == 'block_editor'): ?>
                                            <div class="fhr_page_title">{{ Page Title }}</div>
                                        <?php else: ?>
                                            <div class="fhr_page_title"><?php the_title(); ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <div
                                    class="wp_content_wrapper <?php echo $useFullWidth ? 'wp_content_wrapper_full' : ''; ?>">
                                    <div class="wp_content">
                                        <?php if ($contenx == 'block_editor'): ?>
                                            <p>Your page content will be shown here</p>
                                        <?php else: ?>
                                            <?php the_content(); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function useCommunityTemplate($title, $contentCallback = null)
    {
        do_action('fluent_community/enqueue_global_assets', true);
        $useFullWidth = false;
        $hideCommunityHeader = false;
        
        ob_start();
        ?>
        <div class="fluent_com">
            <div class="fhr_wrap">
                <?php if (!$hideCommunityHeader): ?>
                    <?php do_action('fluent_community/portal_header', 'headless'); ?>
                <?php endif; ?>
                <div class="fhr_content">
                    <div class="fhr_home">
                        <div class="feed_layout">
                            <div class="spaces">
                                <div id="fluent_community_sidebar_menu" class="space_contents">
                                    <!--                                    start-->
                                    <?php do_action('fluent_community/portal_sidebar', 'headless'); ?>
                                    <!--                                    end-->
                                </div>
                            </div>
                            <div class="fcom_wp_page">
                                <?php if ($title): ?>
                                    <div class="fhr_content_layout_header">
                                        <div class="fhr_page_title"><?php echo wp_kses_post($title); ?></div>
                                    </div>
                                <?php endif; ?>
                                <div class="wp_content_wrapper <?php echo $useFullWidth ? 'wp_content_wrapper_full' : ''; ?>">
                                    <div class="wp_content">
                                        <?php if ($contentCallback): ?>
                                            <?php call_user_func($contentCallback); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
