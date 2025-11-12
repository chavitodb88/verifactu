<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

if (ENVIRONMENT !== 'production') {
    $routes->get('api/v1/docs/generate', 'SwaggerDocGenerator::generate');
    $routes->get('api/v1/docs/ui', 'SwaggerDocGenerator::ui');
}


$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\V1', 'filter' => 'apikey'], static function ($routes) {
    $routes->get('health', 'HealthController::index');
    // Próximos: invoices/preview, invoices/{id}, etc.
});
