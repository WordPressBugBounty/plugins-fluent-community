<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>
<?php
    $formBuilder = new \FluentCommunity\App\Services\FormBuilder($formFields);
?>
<div id="fcom_user_onboard_wrap" class="fcom_user_onboard">
    <div class="fcom_onboard_header">
        <div class="fcom_onboard_header_title" style="color: <?php echo esc_attr($text_color); ?>;">
            <h2 style="color: <?php echo esc_attr($title_color); ?>;">
                <?php echo esc_html($title); ?>
            </h2>
            <p><?php echo wp_kses_post($description); ?></p>
        </div>
    </div>
    <div class="fcom_onboard_body">
        <div class="fcom_onboard_form">
            <form method="post" id="fcom_user_registration_form">
                <div class="fcom_form_main_fields">
                    <?php $formBuilder->render(); ?>
                    <?php
                        foreach ($hiddenFields as $name => $value) {
                            echo "<input type='hidden' name='".esc_attr($name)."' value='".esc_attr($value)."'>";
                        }
                    ?>
                    <div class="fcom_form-group">
                        <div class="fcom_form_input">
                            <button type="submit" class="fcom_btn fcom_btn_primary" style="background-color: <?php echo esc_attr($button_color); ?>; color: <?php echo esc_attr($button_label_color); ?>;">
                                <?php echo esc_html($button_label); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </form>

            <?php if(!empty($loginUrl)): ?>
            <div class="fcom_spaced_divider">
                <?php _e('Already have an account?', 'fluent-community'); ?>
                <a href="<?php echo esc_url($loginUrl); ?>">
                    <?php _e('Login', 'fluent-community'); ?>
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
