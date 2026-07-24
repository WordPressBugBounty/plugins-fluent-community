<?php if ( ! defined( 'ABSPATH' ) ) { exit;} // Exit if accessed directly ?>
<?php /** @var string $content */ $fluentCommunityContent = $content ?? ''; ?>
<p style="font-family: Arial, sans-serif; font-size: 16px; font-weight: normal; margin: 0; margin-bottom: 16px;"><?php echo wp_kses_post($fluentCommunityContent); ?></p>
