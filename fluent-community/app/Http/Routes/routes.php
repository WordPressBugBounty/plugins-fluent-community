<?php if ( ! defined( 'ABSPATH' ) ) { exit; // Exit if accessed directly
}
/**
 * @var FluentCommunity\Framework\Http\Router $router
 */

$router->namespace('FluentCommunity\App\Http\Controllers')->group(function ($router) {
    require_once __DIR__ . '/api.php';
});
