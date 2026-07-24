<?php

defined('ABSPATH') || exit;

/**
 * @var FluentCommunity\Framework\Http\Router $router
 */

$router->prefix('fluent-player')
    ->namespace('FluentCommunity\Modules\Integrations\FluentPlayer\Http\Controllers')
    ->withPolicy(\FluentCommunity\App\Http\Policies\PortalPolicy::class)
    ->group(function ($router) {
        $router->post('/video-upload', 'MediaController@uploadVideo');
        $router->get('/video-content/{media_id}', 'MediaController@getFluentPlayerContent');
        $router->post('/audio-media/{media_id}', 'MediaController@updateAudioMeta');
    });
