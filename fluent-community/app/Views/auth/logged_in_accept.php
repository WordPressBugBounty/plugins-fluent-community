<?php if ( ! defined( 'ABSPATH' ) ) { exit;} // Exit if accessed directly ?>
<?php
/** @var string $title @var string $description @var string $invitation_token */
$fluentCommunityTitle = $title ?? '';
$fluentCommunityDescription = $description ?? '';
$fluentCommunityInvitationToken = $invitation_token ?? '';
?>
<div id="fcom_user_onboard_wrap" class="fcom_user_onboard">
    <div class="fcom_onboard_header">
        <div class="fcom_onboard_header_title">
            <h2><?php echo esc_html($fluentCommunityTitle); ?></h2>
            <p><?php echo wp_kses_post($fluentCommunityDescription); ?></p>
        </div>
    </div>
    <div class="fcom_onboard_body">
        <div class="fcom_onboard_form">
            <form method="post" id="fcom_user_accept_form">
                <input type="hidden" name="action" value="fcom_user_accept_invitation" />
                <input type="hidden" name="invitation_token" value="<?php echo esc_attr($fluentCommunityInvitationToken); ?>" />
                <input type="hidden" name="_fcom_accept_invitation_nonce" value="<?php echo esc_attr(wp_create_nonce('fcom_accept_invitation')); ?>" />
                <div style="text-align: center;" class="fcom_form-group">
                    <div class="fcom_form_input">
                        <button type="submit" class="fcom_btn fcom_btn_submit fcom_btn_success"><?php esc_html_e('Accept invitation & continue', 'fluent-community'); ?></button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
