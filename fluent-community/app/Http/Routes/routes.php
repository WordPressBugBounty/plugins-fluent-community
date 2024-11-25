<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
/**
 * @var $router FluentCommunity\Framework\Http\Router
 */

$router->namespace('FluentCommunity\App\Http\Controllers')->group(function($router) {
    require_once __DIR__ . '/api.php';
});
