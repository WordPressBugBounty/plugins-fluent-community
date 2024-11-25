<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>
<?php
/**
 * @var $title string
 * @var $description string
 * @var $url string
 * @var $featured_image string
 * @var $css_files array
 * @var $js_files array
 * @var $js_vars array
 * @var $scope string
 */
?>
<!DOCTYPE html>
<html lang='en'>
<head>
    <title><?php echo esc_attr($title); ?></title>
    <meta charset='utf-8'>

    <meta name="viewport"
          content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=0,viewport-fit=cover"/>
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="description" content="<?php echo esc_attr($description); ?>">
    <meta name="robots" content="noindex">
    <link rel="icon" type="image/x-icon" href="<?php echo esc_url(get_site_icon_url()); ?>"/>

    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo esc_url($url); ?>">
    <meta property="og:site_name" content="<?php bloginfo('name'); ?>">
    <meta property="og:description" content="<?php echo esc_attr($description); ?>">

    <?php if ($featured_image): ?>
        <meta property="og:image" content="<?php echo esc_url($featured_image); ?>"/>
    <?php endif; ?>
    <style>
        .fluent_com, .fcom_full_layout {
            display: block;
            width: 100%;
        }
    </style>

    <?php foreach ($css_files as $css_file): ?>
        <link rel="stylesheet"
              href="<?php echo esc_url($css_file); ?>?version=<?php echo esc_attr(FLUENT_COMMUNITY_PLUGIN_VERSION); ?>"
              media="screen"/>
    <?php endforeach; ?>
    <?php do_action('fluent_community/headless/head', $scope); ?>
</head>
<body class="fcom_headless_page fcom_headless_scope_<?php echo esc_attr($scope); ?>">

<?php if ($layout == 'signup'): ?>
    <div class="fcom_full_layout" <?php echo $portal['position'] == 'right' ? 'style="flex-direction: row-reverse;"' : ''; ?>>
        <div class="fcom_layout_side" style="background-image: url(<?php echo esc_url($portal['background_image']); ?>); background-color: <?php echo esc_attr($portal['background_color']); ?>;">
            <div class="fcom_welcome">
                <?php if(!empty($portal['logo'])): ?>
                <div class="fcom_logo">
                    <a href="<?php echo esc_url(\FluentCommunity\App\Services\Helper::baseUrl()); ?>">
                        <img src="<?php echo esc_url($portal['logo']); ?>" alt="Site logo">
                    </a>
                </div>
                <?php endif; ?>
                <h2 class="fcom_title" style="color: <?php echo esc_attr($portal['title_color']); ?>">
                    <?php echo wp_kses_post($portal['title']); ?>
                </h2>
                <div class="fcom_sub_title" style="color: <?php echo esc_attr($portal['text_color']); ?>">
                    <?php echo wp_kses_post($portal['description']); ?>
                </div>
            </div>
        </div>
        <div class="fcom_layout_main" style="background-image: url(<?php echo esc_url($portal['form']['background_image']); ?>); background-color: <?php echo esc_attr($portal['form']['background_color']); ?>;">
            <div class="fluent_com">
                <?php do_action('fluent_community/headless/content', $scope); ?>
            </div>
        </div>
    </div>
<?php else: ?>

    <div class="fluent_com">
        <?php do_action('fluent_community/headless/content', $scope); ?>
    </div>

<?php endif; ?>

<script>
    <?php foreach ($js_vars as $varKey => $values): ?>
    var <?php echo esc_attr($varKey); ?> = <?php echo wp_json_encode($values); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped  ?>;
    <?php endforeach; ?>
</script>

<?php do_action('fluent_community/headless/before_js_loaded', $scope); ?>

<?php foreach ($js_files as $file): ?>
    <script type="module"
            src="<?php echo esc_url($file); ?>?version=<?php echo esc_attr(FLUENT_COMMUNITY_PLUGIN_VERSION); ?>"
            defer="defer"></script>
<?php endforeach; ?>

<?php do_action('fluent_community/headless/footer', $scope); ?>
</body>
</html>