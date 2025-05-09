<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>
<!doctype html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <style media="all" type="text/css">
        @media all {
            .btn-primary table td:hover {
                background-color: #2B2E33 !important;
            }

            .btn-primary a:hover {
                background-color: #2B2E33 !important;
                border-color: #2B2E33 !important;
            }
        }
        @media only screen and (max-width: 640px) {
            .main p,
            .main td,
            .main span {
                font-size: 16px !important;
            }

            .wrapper {
                padding: 8px !important;
            }

            .content {
                padding: 0 !important;
            }

            .container {
                padding: 0 !important;
                padding-top: 8px !important;
                width: 100% !important;
            }

            .main {
                border-left-width: 0 !important;
                border-radius: 0 !important;
                border-right-width: 0 !important;
            }

            .btn table {
                max-width: 100% !important;
                width: 100% !important;
            }

            .btn a {
                font-size: 16px !important;
                max-width: 100% !important;
                width: 100% !important;
            }
        }
        @media all {
            .ExternalClass {
                width: 100%;
            }

            .ExternalClass,
            .ExternalClass p,
            .ExternalClass span,
            .ExternalClass font,
            .ExternalClass td,
            .ExternalClass div {
                line-height: 100%;
            }

            .apple-link a {
                font-family: inherit !important;
                font-size: inherit !important;
                font-weight: inherit !important;
                line-height: inherit !important;
            }

            #MessageViewBody a {
                color: inherit;
                text-decoration: none;
                font-size: inherit;
                font-family: inherit;
                font-weight: inherit;
                line-height: inherit;
            }

            img {
                max-width: 100%;
            }
        }

        .footer p {
            margin: 0;
            margin-top: 8px;
            padding: 0;
            font-size: 14px;
            color: #9a9ea6;
            text-align: center;
        }
        .footer p a {
            color: #9a9ea6;
            text-decoration: underline;
        }
        .footer .powered_by a {
            color: #8e8e90;
        }

        .user_contents p {
            margin: 7px 0;
        }

        blockquote {
            margin: 0;
            padding: 0;
            border-left: 4px solid #959595;
            padding-left: 16px;
        }

        blockquote p {
            margin: 3px 0;
        }

        <?php if(\FluentCommunity\App\Services\Helper::isRtl()): ?>
        .fcom_email {
            direction: rtl;
        }
        <?php endif; ?>

    </style>
</head>
<body style="font-family: Helvetica, sans-serif; -webkit-font-smoothing: antialiased; font-size: 16px; line-height: 1.3; -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; background-color: #f4f5f6; margin: 0; padding: 0;">
<table class="fcom_email" role="presentation" border="0" cellpadding="0" cellspacing="0" class="body" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; background-color: #f4f5f6; width: 100%;" width="100%" bgcolor="#f4f5f6">
    <?php if($logo): ?>
    <tr>
        <td></td>
        <td>
            <a href="<?php echo esc_url(\FluentCommunity\App\Services\Helper::baseUrl()); ?>">
                <img src="<?php echo esc_url($logo['url']); ?>" alt="<?php echo esc_attr($logo['alt']); ?>" style="display: block; margin: 0 auto;max-height: 60px; padding: 0; width: auto; margin-top: 12px;">
            </a>
        </td>
        <td></td>
    </tr>
    <?php endif; ?>
    <tr>
        <td style="font-family: Helvetica, sans-serif; font-size: 16px; vertical-align: top;" valign="top">
            &nbsp;
        </td>
        <td class="container" style="font-family: Helvetica, sans-serif; font-size: 16px; vertical-align: top; max-width: 600px; padding: 0; padding-top: 24px; width: 600px; margin: 0 auto;" width="600" valign="top">
            <div class="content" style="box-sizing: border-box; display: block; margin: 0 auto; max-width: 600px; padding-bottom: 20px; padding: 0 0 20px;">
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" class="main" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; background: #ffffff; border: 1px solid #eaebed; border-radius: 16px; width: 100%;" width="100%">
                    <tr>
                        <td>
                            <?php if(!empty($headingContent)): ?>
                                <table role="presentation" border="0" cellpadding="0" cellspacing="0" class="main" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;" width="100%">
                                    <tr>
                                        <td class="wrapper" style="font-family: Helvetica, sans-serif; font-size: 16px; line-height: 1.4; vertical-align: top; box-sizing: border-box; padding: 20px 20px 10px;" valign="top">
                                            <?php \FluentCommunity\App\Services\CustomSanitizer::sanitizeRichText($headingContent, true); ?>
                                        </td>
                                    </tr>
                                </table>
                            <?php endif; ?>

                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" class="main" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;" width="100%">
                                <tr>
                                    <td class="wrapper user_contents" style="font-family: Helvetica, sans-serif; font-size: 16px; line-height: 1.4; vertical-align: top; box-sizing: border-box; padding: 24px;" valign="top">
                                        <?php \FluentCommunity\App\Services\CustomSanitizer::sanitizeRichText($bodyContent, true); ?>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                <?php if($footerLines): ?>
                <div class="footer" style="clear: both; padding-top: 24px; padding-bottom: 24px; text-align: center; width: 100%;">
                    <!--before_footer_section-->
                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;" width="100%">
                        <?php foreach ($footerLines as $line):  ?>
                        <tr>
                            <td class="content-block" style="font-family: Helvetica, sans-serif; vertical-align: top; color: #9a9ea6; font-size: 14px;  text-align: center;" valign="top" align="center">
                                <span class="apple-link" style="color: #9a9ea6; font-size: 14px; text-align: center;"><?php \FluentCommunity\App\Services\CustomSanitizer::sanitizeRichText($line, true); ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </td>
        <td style="font-family: Helvetica, sans-serif; font-size: 16px; vertical-align: top;" valign="top">&nbsp;</td>
    </tr>
</table>
</body>
</html>
