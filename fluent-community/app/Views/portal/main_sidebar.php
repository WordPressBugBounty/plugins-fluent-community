<?php if (!defined('ABSPATH')) { exit;} // Exit if accessed directly ?>
<?php
/**
 * @var array $primaryItems
 * @var array $settingsItems
 * @var array $spaceGroups
 * @var array $topInlineLinks
 * @var array $bottomLinkGroups
 * @var bool $is_admin
 * @var bool $has_color_scheme
 * @var string $context
 */

use FluentCommunity\App\Services\Helper;
use FluentCommunity\Framework\Support\Arr;

$fluentCommunityPrimaryItems = $primaryItems ?? [];
$fluentCommunitySettingsItems = $settingsItems ?? [];
$fluentCommunitySpaceGroups = $spaceGroups ?? [];
$fluentCommunityTopInlineLinks = $topInlineLinks ?? [];
$fluentCommunityBottomLinkGroups = $bottomLinkGroups ?? [];
$fluentCommunityIsAdmin = $is_admin ?? false;
$fluentCommunityContext = $context ?? '';
$fluentCommunityFeedItem = $fluentCommunityPrimaryItems['all_feeds'] ?? [];
$fluentCommunityShowFeedLink = $fluentCommunityFeedItem && \FluentCommunity\App\Functions\Utility::isCustomizationEnabled('feed_link_on_sidebar');
$fluentCommunityCollapsedGroups = Helper::getCollapsedSidebarGroups(array_merge($fluentCommunitySpaceGroups, $fluentCommunityBottomLinkGroups));

?>

<?php if ($fluentCommunityContext != 'ajax'): ?>
    <?php do_action('fluent_community/before_sidebar_wrap', $fluentCommunityContext); ?>
<?php endif; ?>

<div id="fcom_sidebar_wrap" class="fcom_sidebar_wrap">
    <?php if (apply_filters('fluent_community/will_render_default_sidebar_items', true)) : ?>
        <nav aria-label="<?php echo esc_attr__('Main Sidebar Home menu', 'fluent-community'); ?>">
            <ul class="fcom_sm_only fcom_general_menu fcom_home_link">
                <?php Helper::renderMenuItems($fluentCommunityPrimaryItems, 'fcom_menu_link', '<span class="fcom_no_avatar"></span>', true); ?>
            </ul>
        </nav>
        <?php if($fluentCommunityShowFeedLink || $fluentCommunityTopInlineLinks): ?>
        <nav aria-label="<?php echo esc_attr__('Main Sidebar Mobile Menu', 'fluent-community'); ?>">
            <ul class="fcom_general_menu">
                <?php if ($fluentCommunityShowFeedLink): ?>
                    <li class="fcom_menu_item_all_feeds fcom_desktop_only">
                        <a class="fcom_menu_link <?php echo esc_attr(Arr::get($fluentCommunityFeedItem, 'link_classes', 'fcom_dashboard route_url')); ?>"
                           data-fcom-tip="<?php echo esc_attr(Arr::get($fluentCommunityFeedItem, 'title', __('Feed', 'fluent-community'))); ?>"
                           title="<?php echo esc_attr(Arr::get($fluentCommunityFeedItem, 'title', __('Feed', 'fluent-community'))); ?>"
                           href="<?php echo esc_url(Arr::get($fluentCommunityFeedItem, 'permalink', Helper::baseUrl('/'))); ?>">
                            <div class="community_avatar">
                            <span class="fcom_shape">
                                <i class="el-icon">
                                    <?php Helper::printLinkIcon($fluentCommunityFeedItem); // prints directly with internal escaping ?>
                                </i>
                            </span>
                            </div>
                            <span class="community_name">
                                <?php echo esc_html(Arr::get($fluentCommunityFeedItem, 'title', __('Feed', 'fluent-community'))); ?>
                            </span>
                        </a>
                    </li>
                <?php endif; ?>
                <?php Helper::renderMenuItems($fluentCommunityTopInlineLinks, 'fcom_menu_link fcom_custom_link', '<span class="fcom_no_avatar"></span>', true); ?>
            </ul>
        </nav>
        <?php endif; ?>

        <div class="fcom_sidebar_contents">
            <?php foreach ($fluentCommunitySpaceGroups as $fluentCommunitySpaceGroup): ?>
                <?php if($fluentCommunitySpaceGroup['children']): ?>
                    <?php
                    $fluentCommunityGroupCollapsedClass = in_array(sanitize_key((string) $fluentCommunitySpaceGroup['id']), $fluentCommunityCollapsedGroups, true)
                    ? ' fcom_group_collapsed'
                    : '';
                    ?>
                <div class="<?php echo esc_attr('fcom_communities_menu' . $fluentCommunityGroupCollapsedClass); ?>">
                    <div class="fcom_space_group_header fcom_group_title">
                        <h4 data-group_id="<?php echo (int)$fluentCommunitySpaceGroup['id']; ?>" data-fcom-tip="<?php echo esc_attr($fluentCommunitySpaceGroup['title']); ?>" title="<?php echo esc_attr($fluentCommunitySpaceGroup['title']); ?>" class="space_section_title" tabindex="0" role="button" aria-label="<?php echo esc_attr($fluentCommunitySpaceGroup['title']); ?>" aria-expanded="<?php echo $fluentCommunityGroupCollapsedClass ? 'false' : 'true'; ?>">
                            <span><?php echo esc_html($fluentCommunitySpaceGroup['title']); ?></span>
                            <i class="el-icon fcom_space_down">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024"><path fill="currentColor" d="M831.872 340.864 512 652.672 192.128 340.864a30.592 30.592 0 0 0-42.752 0 29.12 29.12 0 0 0 0 41.6L489.664 714.24a32 32 0 0 0 44.672 0l340.288-331.712a29.12 29.12 0 0 0 0-41.728 30.592 30.592 0 0 0-42.752 0z"></path></svg>
                            </i>
                        </h4>
                        <?php if ($fluentCommunityIsAdmin): ?>
                            <div class="fcom_space_create">
                                <a class="fcom_space_create_link" data-parent_id="<?php echo (int)$fluentCommunitySpaceGroup['id']; ?>" href="<?php echo esc_url(Helper::baseUrl('discover/spaces/?create_space=yes&parent_id=' . $fluentCommunitySpaceGroup['id'])); ?>">+</a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <nav aria-label="Group Menu for <?php echo esc_html($fluentCommunitySpaceGroup['title']); ?>">
                        <ul>
                            <?php foreach ($fluentCommunitySpaceGroup['children'] as $fluentCommunityLink): ?>
                                <li class="space_menu_item">
                                    <?php Helper::renderLink($fluentCommunityLink, 'fcom_menu_link'); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($fluentCommunityBottomLinkGroups): ?>
                <?php foreach ($fluentCommunityBottomLinkGroups as $fluentCommunityBottomLink): ?>
                    <?php
                    $fluentCommunityBottomItems = isset($fluentCommunityBottomLink['items']) ? $fluentCommunityBottomLink['items'] : [];
                    
                    $fluentCommunityBottomCollapsedClass = in_array(sanitize_key((string) $fluentCommunityBottomLink['slug']), $fluentCommunityCollapsedGroups, true)
                        ? ' fcom_group_collapsed'
                        : '';
                    ?>
                    <div class="<?php echo esc_attr('fcom_communities_menu' . $fluentCommunityBottomCollapsedClass); ?>">
                        <div class="fcom_space_group_header fcom_group_title">
                            <h4 role="button" tabindex="0" aria-expanded="<?php echo $fluentCommunityBottomCollapsedClass ? 'false' : 'true'; ?>" aria-label="<?php echo esc_attr($fluentCommunityBottomLink['title']); ?>" data-group_id="<?php echo esc_attr($fluentCommunityBottomLink['slug']); ?>" data-fcom-tip="<?php echo esc_attr($fluentCommunityBottomLink['title']); ?>" title="<?php echo esc_attr($fluentCommunityBottomLink['title']); ?>"
                                class="space_section_title">
                                <span><?php echo esc_html($fluentCommunityBottomLink['title']); ?></span>
                                <i class="el-icon fcom_space_down">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024"><path fill="currentColor" d="M831.872 340.864 512 652.672 192.128 340.864a30.592 30.592 0 0 0-42.752 0 29.12 29.12 0 0 0 0 41.6L489.664 714.24a32 32 0 0 0 44.672 0l340.288-331.712a29.12 29.12 0 0 0 0-41.728 30.592 30.592 0 0 0-42.752 0z"></path></svg>
                                </i>
                            </h4>
                            <?php if ($fluentCommunityIsAdmin): ?>
                                <div class="fcom_space_create">
                                    <a href="<?php echo esc_url(Helper::baseUrl('admin/settings/menu-settings')); ?>">
                                        +
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                        <nav aria-label="Group Menu for <?php echo esc_html($fluentCommunityBottomLink['title']); ?>">
                        <ul>
                            <?php foreach ($fluentCommunityBottomLink['items'] as $fluentCommunityButtomLink): ?>
                                <li class="space_menu_item">
                                    <?php Helper::renderLink($fluentCommunityButtomLink, 'fcom_menu_link space_menu_item route_url fcom_custom_link', '🔗'); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        </nav>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>


            <?php if ($fluentCommunitySettingsItems): ?>
                <div style="margin-top: 20px;">
                    <h4 class="space_section_title"><?php esc_html_e('# Manage', 'fluent-community'); ?></h4>
                    <ul style="margin-top: 20px;">
                        <?php Helper::renderSettingsMenuItems($fluentCommunitySettingsItems); ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>


<?php if ($fluentCommunityContext != 'ajax'): ?>
    <?php do_action('fluent_community/after_sidebar_wrap', $fluentCommunityContext); ?>
    <div id="fcom_menu_sidebar"></div>
    <?php do_action('fluent_community/after_portal_sidebar', $fluentCommunityContext); ?>
<?php endif; ?>
