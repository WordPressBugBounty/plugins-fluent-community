<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>
<div id="fcom_user_onboard_wrap" class="fcom_user_onboard">
    <div class="fcom_onboard_header">
        <div class="fcom_onboard_header_title" style="color: <?php echo esc_attr($settings['text_color']); ?>;">
            <h2 style="color: <?php echo esc_attr($settings['title_color']); ?>;">
                <?php echo esc_html($settings['title']); ?>
            </h2>
            <p>
                <?php echo wp_kses_post($settings['description']); ?>
            </p>
        </div>
    </div>
    <div class="fcom_onboard_body">
        <div class="fcom_onboard_form">
            <form method="post" id="fcom_user_login_form">
                <div id="fcom_group_log" class="fcom_form-group">
                    <input type="hidden" name="action" value="fcom_user_login_form" />
                    <?php if(!empty($redirect_to)): ?>
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url_raw($redirect_to); ?>" />
                    <?php endif; ?>
                    <?php foreach ($hiddenFields as $key => $value): ?>
                        <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>" />
                    <?php endforeach; ?>
                    <div class="fcom_form_label">
                        <label for="fcom_user_email">
                            <?php echo _e('Email Address', 'fluent-community'); ?>
                        </label>
                    </div>
                    <div class="fcom_form_input">
                        <input value="<?php echo esc_attr(\FluentCommunity\Framework\Support\Arr::get($defaults, 'email')); ?>" type="text" id="fcom_user_email" name="log" placeholder="<?php _e('Your account email address', 'fluent-community'); ?>"/>
                    </div>
                </div>
                <div id="fcom_group_pwd" class="fcom_form-group">
                    <div class="fcom_form_label">
                        <label for="fcom_user_pwd">
                            <?php echo _e('Password', 'fluent-community'); ?>
                        </label>
                    </div>
                    <div class="fcom_form_input">
                        <input type="password" id="fcom_user_pwd" name="pwd" placeholder="<?php _e('Your account password', 'fluent-community'); ?>"/>
                    </div>
                </div>
                <div class="fcom_form-group">
                    <div class="fcom_form_input">
                        <button type="submit" class="fcom_btn fcom_btn_primary" style="background-color: <?php echo esc_attr($settings['button_color']); ?>; color: <?php echo esc_attr($settings['button_label_color']); ?>;">
                            <?php echo esc_html($settings['button_label']); ?>
                        </button>
                    </div>
                </div>
            </form>

            <div class="fcom_spaced_divider">
                <?php if (!empty($signupUrl)): ?>
                    <div class="fcom_alt_auth_text">
                        <?php _e('Don\'t have an account?', 'fluent-community'); ?>
                        <a href="<?php echo esc_url($signupUrl); ?>">
                            <?php _e('Signup', 'fluent-community'); ?>
                        </a>
                    </div>
                <?php endif; ?>
                <p class="fcom_reset_pass_text">
                    <a href="<?php echo wp_lostpassword_url($redirect) ?>">
                        <?php _e('Lost your password?', 'fluent-community'); ?>
                    </a>
                </p>
            </div>
        </div>
    </div>
</div>
