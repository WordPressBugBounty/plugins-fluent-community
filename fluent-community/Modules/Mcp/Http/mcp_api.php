<?php

defined('ABSPATH') || exit;

/**
 * @var FluentCommunity\Framework\Http\Router $router
 */
$router->prefix('settings')->namespace('FluentCommunity\Modules\Mcp\Http\Controllers')->withPolicy(\FluentCommunity\App\Http\Policies\AdminPolicy::class)->group(function ($router) {
    $router->get('mcp', 'McpSettingsController@get');
    $router->post('mcp', 'McpSettingsController@save');
    $router->post('mcp/install-adapter', 'McpSettingsController@installAdapter');
    $router->post('mcp/enable-hub', 'McpSettingsController@enableHub');
});
