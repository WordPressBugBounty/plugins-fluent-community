<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>
<?php
/**
 * @var $invitee_name string
 * @var $by_name string
 * @var $by_email string
 * @var $site_title string
 * @var $access_url string
 */
?>
<div style="background-color: #ffffff; padding: 20px; border-radius: 8px;">
    <p><?php echo esc_html(sprintf(__('Hey %s,', 'fluent-community'), $invitee_name)); ?></p>
    <p><?php
        echo wp_kses_post(sprintf(
            __('%1$s has invited you to join in %2$s.', 'fluent-community'),
            '<strong>'.$by_name.'</strong>',
            '<strong>'.$site_title.'</strong>'
        ));
        ?>
    </p>

    <p><?php esc_html_e('Click the link below to accept the invitation:', 'fluent-community') ?></p>

    <a href="<?php echo esc_url($access_url); ?>" style="background-color: #000000; color: #ffffff; padding: 10px 20px; text-align: center; border-radius: 5px; display: inline-block; text-decoration: none;">
        <?php esc_html_e('Accept invitation', 'fluent-community'); ?>
    </a>

    <hr/>
    <p style="font-size: 12px; color: #666; text-align: left; padding-top: 20px;">
        <?php esc_html_e('If you think you\'ve received this invitation in error, please ignore this email.', 'fluent-community'); ?>
    </p>
</div>
