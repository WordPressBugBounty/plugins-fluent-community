<?php if (!defined('ABSPATH')) exit; // Exit if accessed directly ?>

<?php
/**
 *
 * @var string $portal_url
 * @var string $logo
 * @var string $white_logo
 * @var string $logo_permalink
 * @var string $site_title
 * @var string $profile_url
 * @var array | null $auth
 * @var string $auth_url
 * @var array $menuItems
 * @var array $profileLinks
 * @var bool $has_color_scheme
 * @var string $context
 **/
?>
<div class="fcom_top_menu">
    <div class="top_menu_left">
        <div class="space_opener">
            <button aria-label="<?php echo esc_attr__('Open Menu', 'fluent-community'); ?>" class="fcom_space_opener_btn" aria-disabled="false" type="button">
                <span>
                    <i class="el-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="20" height="20" fill="currentColor">
                            <path d="M1 2.75A.75.75 0 0 1 1.75 2h12.5a.75.75 0 0 1 0 1.5H1.75A.75.75 0 0 1 1 2.75Zm0 5A.75.75 0 0 1 1.75 7h12.5a.75.75 0 0 1 0 1.5H1.75A.75.75 0 0 1 1 7.75ZM1 12.75a.75.75 0 0 1 .75-.75h12.5a.75.75 0 0 1 0 1.5H1.75a.75.75 0 0 1-.75-.75Z"/>
                        </svg>
                    </i>
                </span>
            </button>
        </div>
        <div id="fcom_before_logo"></div>
        <?php do_action('fluent_community/before_header_logo', $auth); ?>
        <div class="fhr_logo">
            <a class="fcom_route" href="<?php echo esc_url($logo_permalink); ?>">
                <?php if ($logo): ?>
                    <img class="show_on_light" src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($site_title); ?>"/>
                    <img class="show_on_dark" src="<?php echo esc_url($white_logo); ?>" alt="<?php echo esc_attr($site_title); ?>"/>
                <?php else: ?>
                    <span><?php echo esc_html($site_title); ?></span>
                <?php endif; ?>
            </a>
        </div>
        <?php do_action('fluent_community/after_header_logo', $auth); ?>
    </div>
    <div class="top_menu_center fcom_desktop_only fcom_general_menu">
        <?php if ($menuItems): ?>
            <nav>
                <ul aria-label="<?php echo esc_attr__('Main menu', 'fluent-community'); ?>" class="fcom_header_menu top_menu_items">
                    <?php \FluentCommunity\App\Services\Helper::renderMenuItems($menuItems, 'fcom_menu_link'); ?>
                </ul>
            </nav>
        <?php endif; ?>
        <?php do_action('fluent_community/after_header_menu', $context); ?>
    </div>
    <div class="top_menu_right">
        <?php  do_action('fluent_community/top_menu_right_items', $context); ?>
    </div>
</div>
