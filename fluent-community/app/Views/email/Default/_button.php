<?php if (!defined('ABSPATH')) exit; // Exit if accessed directly ?>
<table align="left" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
       style="text-align: left;margin-bottom:10px; margin-top:10px">
    <tbody>
    <tr>
        <td>
            <a href="<?php echo esc_url($link); ?>" style="background-color:#0867ec;color:rgb(255,255,255);font-weight:700;padding-top:12px;padding-bottom:12px;padding-left:24px;padding-right:24px;border-radius:4px;text-decoration-line:none;text-align:center;box-sizing:border-box;line-height:100%;text-decoration:none;display:inline-block;max-width:100%;mso-padding-alt:0px;padding:12px 24px 12px 24px" target="_blank">
                <span><!--[if mso]><i style="mso-font-width:400%;mso-text-raise:18" hidden>&#8202;&#8202;&#8202;</i><![endif]--></span>
                <span style="max-width:100%;display:inline-block;line-height:120%;mso-padding-alt:0px;mso-text-raise:9px"><?php echo wp_kses_post($btnText); ?></span>
                <span><!--[if mso]><i style="mso-font-width:400%" hidden>&#8202;&#8202;&#8202;&#8203;</i><![endif]--></span>
            </a>
        </td>
    </tr>
    </tbody>
</table>
