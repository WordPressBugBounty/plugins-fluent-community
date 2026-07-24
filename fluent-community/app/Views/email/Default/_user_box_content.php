<?php if ( ! defined( 'ABSPATH' ) ) { exit;} // Exit if accessed directly ?>
<?php
/** @var string $permalink @var string $user_avatar @var string $user_name @var string $linkColor @var string $post_content @var string $content */
$fluentCommunityPermalink = $permalink ?? '';
$fluentCommunityUserAvatar = $user_avatar ?? '';
$fluentCommunityUserName = $user_name ?? '';
$fluentCommunityLinkColor = $linkColor ?? '';
$fluentCommunityPostContent = $post_content ?? '';
$fluentCommunityContent = $content ?? '';
?>
<table width="100%" style="margin-bottom: 30px;" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td>
            <table cellspacing="0" cellpadding="0" border="0">
                <tr>
                    <td valign="top" style="border-radius: 50%; padding: 4px; vertical-align: top; height: 32px; width: 32px;">
                        <a href="<?php echo esc_url($fluentCommunityPermalink); ?>">
                            <img alt="" src="<?php echo esc_url($fluentCommunityUserAvatar); ?>" height="32" width="32" style="border-radius: 50%; height: 32px; width: 32px; display: block;">
                        </a>
                    </td>
                    <td style="font-family: Arial, sans-serif; font-size: 16px;color: #333; padding-left: 5px; vertical-align: middle;">
                        <span style="font-weight: bold;"><?php echo esc_html($fluentCommunityUserName); ?></span>
                        <span><?php esc_html_e('commented on:', 'fluent-community'); ?></span>
                        <a target="_blank" style="color: <?php echo esc_attr($fluentCommunityLinkColor); ?>; text-decoration: underline;" href="<?php echo esc_url($fluentCommunityPermalink); ?>">
                            <?php echo wp_kses_post($fluentCommunityPostContent); ?>
                        </a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td style="font-family: Arial, sans-serif; font-size: 16px; line-height: 1.4; color: #333;">
            <table style="background-color: #f7f7f7; margin: 10px 0" bgcolor="#f7f7f7" cellspacing="0" cellpadding="0" border="0"
                   width="100%">
                <tr>
                    <td style="padding: 7px 20px;">
                        <?php \FluentCommunity\App\Services\CustomSanitizer::sanitizeRichText($fluentCommunityContent, true); ?>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
