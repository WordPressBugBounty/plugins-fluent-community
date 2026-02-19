<?php

/**
 * @var $router FluentCommunity\Framework\Http\Router
 */

$router->prefix('fluent-player')
    ->namespace('\FluentCommunity\Modules\Integrations\FluentPlayer\Http\Controllers')
    ->withPolicy(\FluentCommunity\App\Http\Policies\PortalPolicy::class)
    ->group(function ($router) {
        $router->post('/video-upload', 'MediaController@uploadVideo');
        $router->get('/video-content/{media_id}', 'MediaController@getFluentPlayerContent');
    });
