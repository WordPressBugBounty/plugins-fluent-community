<?php if (!defined('ABSPATH')) { exit;} // Exit if accessed directly ?>
<?php
/**
 * @var string $title
 * @var string $description
 * @var string $url
 * @var string $og_title
 * @var string $featured_image
 * @var array $css_files
 * @var array $js_files
 * @var array $js_vars
 * @var string $scope
 * @var string $layout
 * @var array $portal
 */
$fluentCommunityTitle = $title ?? '';
$fluentCommunityDescription = $description ?? '';
$fluentCommunityUrl = $url ?? '';
$fluentCommunityOgTitle = $og_title ?? '';
$fluentCommunityFeaturedImage = $featured_image ?? '';
$fluentCommunityCssFiles = $css_files ?? [];
$fluentCommunityJsFiles = $js_files ?? [];
$fluentCommunityJsVars = $js_vars ?? [];
$fluentCommunityScope = $scope ?? '';
$fluentCommunityLayout = $layout ?? '';
$fluentCommunityPortal = $portal ?? [];
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <title><?php echo esc_attr($fluentCommunityTitle); ?></title>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="description" content="<?php echo esc_attr($fluentCommunityDescription); ?>">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=0,viewport-fit=cover,interactive-widget=resizes-content"/>
    <?php
    if (!empty($load_wp)) :
        wp_head();
    else:
        ?>
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="robots" content="noindex">
        <link rel="icon" type="image/x-icon" href="<?php echo esc_url(get_site_icon_url()); ?>"/>
        <meta property="og:type" content="website">
        <meta property="og:url" content="<?php echo esc_url($fluentCommunityUrl); ?>">
        <meta property="og:site_name" content="<?php bloginfo('name'); ?>">
        <meta property="og:title" content="<?php echo esc_attr($fluentCommunityOgTitle); ?>">
        <meta property="og:description" content="<?php echo esc_attr($fluentCommunityDescription); ?>">
    <?php endif; ?>

    <?php if ($fluentCommunityFeaturedImage): ?>
        <meta property="og:image" content="<?php echo esc_url($fluentCommunityFeaturedImage); ?>"/>
    <?php endif; ?>

    <style>
        .fluent_com, .fcom_full_layout {
            display: block;
            width: 100%;
        }
    </style>

    <?php do_action('fluent_community/headless/head_early', $fluentCommunityScope); ?>

    <?php foreach ($fluentCommunityCssFiles as $fluentCommunityCssFile): ?>
        <link rel="stylesheet" href="<?php echo esc_url($fluentCommunityCssFile); ?>?version=<?php echo esc_attr(FLUENT_COMMUNITY_PLUGIN_VERSION); ?>" media="screen"/> <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet ?>
    <?php endforeach; ?>
    <?php do_action('fluent_community/headless/head', $fluentCommunityScope); ?>
</head>
<body class="fcom_headless_page fcom_headless_scope_<?php echo esc_attr($fluentCommunityScope); ?>">

<?php if ($fluentCommunityLayout == 'signup'): ?>
    <div class="fcom_full_layout fluent_com_auth <?php echo $fluentCommunityPortal['position'] == 'right' ? ' fcom_auth_page_row_reverse' : ' fcom_auth_page'; ?>">
        <div class="fcom_layout_side"
             style="<?php if (!empty($fluentCommunityPortal['background_image'])) { echo 'background-image: url(' . esc_url($fluentCommunityPortal['background_image']) . ');';} ?>
             <?php if (!empty($fluentCommunityPortal['background_color'])) { echo 'background-color: ' . esc_attr($fluentCommunityPortal['background_color']) . ';';} ?>
                 display: <?php echo $fluentCommunityPortal['hidden'] ? 'none' : 'flex'; ?>">
            <div class="fcom_welcome">
                <?php if (!empty($fluentCommunityPortal['logo'])): ?>
                    <div class="fcom_logo">
                        <a href="<?php echo esc_url(\FluentCommunity\App\Services\Helper::baseUrl()); ?>">
                            <img src="<?php echo esc_url($fluentCommunityPortal['logo']); ?>" alt="<?php echo esc_attr__('Site logo', 'fluent-community'); ?>">
                        </a>
                    </div>
                <?php endif; ?>
                <h2 class="fcom_title" style="color: <?php echo esc_attr(!empty($fluentCommunityPortal['title_color']) ? $fluentCommunityPortal['title_color'] : 'var(--fcom_text_color, #606266)'); ?>">
                    <?php echo wp_kses_post($fluentCommunityPortal['title']); ?>
                </h2>
                <div class="fcom_sub_title" style="color: <?php echo esc_attr(!empty($fluentCommunityPortal['text_color']) ? $fluentCommunityPortal['text_color'] : 'var(--fcom_text_color, #606266)'); ?>">
                    <p><?php echo wp_kses_post($fluentCommunityPortal['description']); ?></p>
                </div>
            </div>
        </div>
        <div class="fcom_layout_main" style="<?php if (!empty($fluentCommunityPortal['form']['background_image'])): ?>background-image: url(<?php echo esc_url($fluentCommunityPortal['form']['background_image']); ?>);<?php endif; ?>">
            <div class="fluent_com fluent_com_auth">
                <?php do_action('fluent_community/headless/content', $fluentCommunityScope); ?>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="fluent_com">
        <?php do_action('fluent_community/headless/content', $fluentCommunityScope); ?>
    </div>
<?php endif; ?>

<script>
    <?php foreach ($fluentCommunityJsVars as $fluentCommunityVarKey => $fluentCommunityValues): ?>
    var <?php echo esc_attr($fluentCommunityVarKey); ?> = <?php echo wp_json_encode($fluentCommunityValues); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
    <?php endforeach; ?>
</script>

<?php do_action('fluent_community/headless/before_js_loaded', $fluentCommunityScope); ?>

<?php foreach ($fluentCommunityJsFiles as $fluentCommunityFile): ?>
    <script type="module" src="<?php echo esc_url($fluentCommunityFile); ?>?version=<?php echo esc_attr(FLUENT_COMMUNITY_PLUGIN_VERSION); ?>" defer="defer"></script> <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript ?>
<?php endforeach; ?>

<?php if (!empty($load_wp)) { wp_footer(); } ?>

<?php do_action('fluent_community/headless/footer', $fluentCommunityScope); ?>
</body>
</html>
