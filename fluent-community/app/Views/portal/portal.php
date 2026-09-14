<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>
<div class="fhr_wrap">
    <a class="screen-reader-shortcut" href="#fluent_com_portal"><?php esc_html_e('Skip to main content', 'fluent-community'); ?></a>
    <?php do_action('fluent_community/portal_header', 'headless'); ?>
    <div class="fhr_content">
        <div id="fluent_comminity_body" class="fhr_home">
            <div class="feed_layout">
                <div class="spaces fcom_space_list">
                    <div id="fluent_community_sidebar_menu" class="space_contents">
                        <?php do_action('fluent_community/portal_sidebar', 'headless'); ?>
                    </div>
                </div>
                <div id="fluent_com_portal" tabindex="-1">
                    <?php
                    /**
                     * Server-rendered first paint for the current route. Vue's mount()
                     * empties this container before it renders, so whatever is printed
                     * here is only ever seen by crawlers and by the browser before the
                     * SPA boots - it does not need to match the Vue DOM.
                     */
                    do_action('fluent_community/portal_content_pre_render', isset($preRenderContext) ? $preRenderContext : []);
                    ?>
                </div>
            </div>
        </div>
    </div>
</div>
