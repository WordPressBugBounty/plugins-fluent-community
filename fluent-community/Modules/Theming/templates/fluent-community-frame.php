<?php
defined('ABSPATH') or die;
/**
 * Template Name: Fluent Community Frame
 * Description: The template for displaying the Fluent Community frame.
 *
 * @package FluentCommunity
 */
$themeName = get_option('template');
$supportedTheme = apply_filters('fluent_community/is_supported_theme', false, $themeName);
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1">
    <?php wp_head(); ?>
    <?php do_action('fluent_community/template_header'); ?>
</head>

<body <?php body_class(); ?> <?php do_action('fluent_community/theme_body_atts', $themeName); ?>>
<?php wp_body_open(); ?>
<div class="fcom_wrap fcom_wp_frame">
    <?php do_action('fluent_community/before_portal_dom'); ?>
    <div class="fluent_com">
        <div class="fhr_wrap">
            <?php do_action('fluent_community/portal_header', 'wp'); ?>
            <div class="fhr_content">
                <div class="fhr_home">
                    <div class="feed_layout">
                        <div class="spaces">
                            <div id="fluent_community_sidebar_menu" class="space_contents">
                                <?php do_action('fluent_community/portal_sidebar', 'wp'); ?>
                            </div>
                        </div>
                        <div class="feeds_main <?php echo $supportedTheme ? 'fcom_supported_wp_content' : 'fcom_wp_content fcom_fallback_wp_content' ?>">
                            <?php do_action('fluent_community/theme_content', $themeName, 'default'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php wp_footer(); ?>
<?php do_action('fluent_community/template_footer'); ?>
</body>
</html>
