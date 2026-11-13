<?php
if (!defined('ABSPATH')) { exit; // Exit if accessed directly
}

/**
 * The page the footer of an announcement email links to.
 *
 * @var string $error        Set when the link is not valid; nothing else is shown.
 * @var int    $space_id     The space the email came from.
 * @var string $space_title
 * @var string $current      keep | stop
 * @var bool   $can_keep_space The member is an active member of that space, so it can be kept.
 * @var bool   $space_kept   That space is kept while announcements are off.
 * @var string $saved        Confirmation after a save.
 * @var string $form_url     The signed URL of this page.
 * @var string $settings_url The member's notification settings page.
 */

$fluent_community_title = __('Announcement emails', 'fluent-community');
$fluent_community_color = \FluentCommunity\App\Functions\Utility::getThemeColor();
?>
<!DOCTYPE html>
<html style="background: #f0f0f1;" <?php language_attributes(); ?>>
<head>
    <title><?php echo esc_html($fluent_community_title); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" type="image/x-icon" href="<?php echo esc_url(get_site_icon_url()); ?>"/>
    <style>
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif; color: #1d2327; }
        .fcom_ae_wrap { max-width: 560px; margin: 64px auto; padding: 0 16px; }
        .fcom_ae_card { background: #fff; border: 1px solid #e3e8ee; border-radius: 10px; overflow: hidden; }
        .fcom_ae_head { padding: 16px 20px; background: #f0f2f5; border-bottom: 1px solid #e3e8ee; }
        .fcom_ae_head h1 { margin: 0; font-size: 18px; }
        .fcom_ae_body { padding: 20px; }
        .fcom_ae_body p { margin: 0 0 16px; line-height: 1.5; }
        .fcom_ae_choice { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border: 1px solid #e3e8ee; border-radius: 8px; margin-bottom: 8px; cursor: pointer; }
        .fcom_ae_choice input, .fcom_ae_space input { margin-top: 3px; accent-color: <?php echo esc_attr($fluent_community_color); ?>; }
        .fcom_ae_choice small { display: block; color: #646970; margin-top: 2px; }
        .fcom_ae_keep { margin: 4px 0 8px 26px; padding: 8px 10px; border: 1px solid #e3e8ee; border-radius: 8px; }
        .fcom_ae_keep .fcom_ae_link { display: inline-block; margin: 2px 0 4px 26px; font-size: 13px; }
        .fcom_ae_keep.fcom_ae_off .fcom_ae_space { opacity: .5; }
        .fcom_ae_space { display: flex; gap: 10px; align-items: flex-start; padding: 6px 4px; cursor: pointer; font-size: 14px; }
        .fcom_ae_space small { color: #646970; margin-left: auto; font-size: 12px; }
        .fcom_ae_actions { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 16px; }
        .fcom_ae_btn { background: <?php echo esc_attr($fluent_community_color); ?>; color: #fff; border: 0; border-radius: 6px; padding: 10px 20px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .fcom_ae_btn:focus-visible, .fcom_ae_choice input:focus-visible, .fcom_ae_space input:focus-visible { outline: 2px solid #1d2327; outline-offset: 2px; }
        .fcom_ae_link { color: #50575e; font-size: 14px; }
        .fcom_ae_saved { background: #edfaef; border: 1px solid #b8e6bf; border-radius: 6px; padding: 10px 12px; margin-bottom: 16px; }
    </style>
</head>
<body>
<div class="fluent_com fcom_ae_wrap">
    <div class="fcom_ae_card">
        <div class="fcom_ae_head">
            <h1><?php echo esc_html($fluent_community_title); ?></h1>
        </div>
        <div class="fcom_ae_body">
            <?php if ($error): ?>
                <p><?php echo esc_html($error); ?></p>
                <a class="fcom_ae_link" href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('Go to the community', 'fluent-community'); ?></a>
            <?php else: ?>
                <?php if ($saved): ?>
                    <div class="fcom_ae_saved" role="status"><?php echo esc_html($saved); ?></div>
                <?php endif; ?>
                <p><?php esc_html_e('Admins send announcements for the important things: event dates, course changes, community news.', 'fluent-community'); ?></p>
                <form method="post" action="<?php echo esc_url($form_url); ?>" id="fcom_ae_form">
                    <label class="fcom_ae_choice" for="fcom_ae_keep">
                        <input type="radio" name="choice" id="fcom_ae_keep" value="keep" <?php checked($current, 'keep'); ?>>
                        <span><?php esc_html_e('Keep getting announcement emails', 'fluent-community'); ?></span>
                    </label>
                    <label class="fcom_ae_choice" for="fcom_ae_stop">
                        <input type="radio" name="choice" id="fcom_ae_stop" value="stop" <?php checked($current, 'stop'); ?>>
                        <span>
                            <?php esc_html_e('Stop announcement emails', 'fluent-community'); ?>
                            <small><?php esc_html_e('You still see announcements in the community.', 'fluent-community'); ?></small>
                        </span>
                    </label>
                    <?php if ($can_keep_space): ?>
                        <div class="fcom_ae_keep" id="fcom_ae_keep_spaces">
                            <label class="fcom_ae_space" for="fcom_ae_keep_this">
                                <input type="checkbox" name="keep_this_space" id="fcom_ae_keep_this" value="1" <?php checked($space_kept); ?>>
                                <span>
                                    <?php
                                    /* translators: %s is the space name */
                                    echo esc_html(sprintf(__('Still email me announcements from %s', 'fluent-community'), $space_title));
                                    ?>
                                </span>
                            </label>
                            <a class="fcom_ae_link" href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('Choose other spaces in your notification settings', 'fluent-community'); ?></a>
                        </div>
                    <?php endif; ?>
                    <div class="fcom_ae_actions">
                        <button type="submit" class="fcom_ae_btn"><?php esc_html_e('Save', 'fluent-community'); ?></button>
                        <a class="fcom_ae_link" href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('Manage all email settings', 'fluent-community'); ?></a>
                    </div>
                </form>
                <script>
                    (function () {
                        var form = document.getElementById('fcom_ae_form');
                        var keep = document.getElementById('fcom_ae_keep_spaces');
                        if (!form || !keep) { return; }
                        // Without script the checkbox stays usable; with it, it follows the choice.
                        var box = document.getElementById('fcom_ae_keep_this');
                        var sync = function () {
                            var stop = document.getElementById('fcom_ae_stop').checked;
                            box.disabled = !stop;
                            keep.classList.toggle('fcom_ae_off', !stop);
                        };
                        form.addEventListener('change', sync);
                        sync();
                    })();
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
