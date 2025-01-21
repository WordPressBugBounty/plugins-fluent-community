<?php if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * @var $app FluentCommunity\Framework\Foundation\Application
 */

$app->addFilter('fluent_community/auth/signup_fields', function ($fields) {
    $authSettings = \FluentCommunity\App\Services\AuthenticationService::getAuthSettings();
    if (!empty($authSettings['signup']['form']['fields']['terms'])) {
        $fields['terms'] = $authSettings['signup']['form']['fields']['terms'];
    }
    return $fields;
}, 10, 1);
