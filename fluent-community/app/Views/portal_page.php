<?php
if (!defined('ABSPATH')) { exit; // Exit if accessed directly
}
/**
 * @var string $title
 * @var string $description
 * @var string $url
 * @var string $og_title
 * @var string $featured_image
 * @var string $theme_color
 * @var string $landing_route
 * @var bool $isHeadless
 */
$fluentCommunityOgTitle = $og_title ?? '';
$fluentCommunityThemeColor = $theme_color ?? '';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?><?php echo !empty($html_class) ? ' class="' . esc_attr($html_class) . '"' : ''; ?>>
<head>
    <title><?php echo esc_attr($title); ?></title>
    <meta charset='utf-8'>
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=2.0,user-scalable=yes,viewport-fit=cover"/>
    <meta name="mobile-web-app-capable" content="yes">
    <?php if (!$isHeadless) : wp_head(); else: ?>
        <meta name="description" content="<?php echo esc_attr($description); ?>">
        <link rel="icon" type="image/x-icon" href="<?php echo esc_url(get_site_icon_url()); ?>"/>
        <link rel="apple-touch-icon" href="<?php echo esc_url(get_site_icon_url()); ?>"/>
        <meta property="og:type" content="website">
        <meta property="og:url" content="<?php echo esc_url($url); ?>">
        <meta property="og:site_name" content="<?php bloginfo('name'); ?>">
        <meta property="og:title" content="<?php echo esc_attr($fluentCommunityOgTitle); ?>">
        <meta property="og:description" content="<?php echo esc_attr($description); ?>">
        <?php if ($featured_image): ?>
            <meta property="og:image" content="<?php echo esc_url($featured_image); ?>">
            <meta name="twitter:image" content="<?php echo esc_url($featured_image); ?>">
        <?php endif; ?>
        <meta name="twitter:card" content="summary">
        <meta name="theme-color" content="<?php echo esc_attr($fluentCommunityThemeColor); ?>">
        <?php do_action('fluent_community/portal_head_meta', $landing_route); ?>

        <?php if(!empty($canonical_url)): ?>
        <link rel="canonical" href="<?php echo esc_url($canonical_url); ?>">
        <?php endif; ?>

        <?php if(!empty($json_ld)): ?><script type="application/ld+json"><?php echo wp_json_encode($json_ld, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
        <?php endif; ?>
    <?php endif; ?>


    <?php if (\FluentCommunity\App\Services\Helper::hasColorScheme()): ?>
        <?php \FluentCommunity\App\Services\Helper::renderColorSchemePrePaintScript(); ?>
    <?php endif; ?>

    <?php if (\FluentCommunity\App\Services\Helper::isRtl()): ?>
        <style>
            body {
                direction: rtl;
            }
        </style>
    <?php endif; ?>

    <style id="fcom_css_vars">
        <?php echo esc_html(\FluentCommunity\App\Functions\Utility::getColorCssVariables()); ?>
        .dark body .el-dialog {
            --el-dialog-bg-color: #2B2E33;
        }
    </style>

    <?php do_action('fluent_community/portal_head'); ?>
</head>
<body>
<div class="fcom_wrap">
    <?php do_action('fluent_community/before_portal_dom'); ?>
    <div class="fluent_com">
        <?php do_action('fluent_community/portal_html'); ?>
    </div>
</div>
<?php do_action('fluent_community/before_js_loaded'); ?>
<?php do_action('fluent_community/portal_footer'); ?>
<?php !$isHeadless && wp_footer(); ?>
</body>
</html>
