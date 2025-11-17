<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

if (ENVIRONMENT !== 'production') {
    $routes->get('public/openapi.json', 'SwaggerDocGenerator::generate');
    $routes->get('public/swagger', 'SwaggerDocGenerator::ui');
}


$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\V1', 'filter' => 'apikey'], static function ($routes) {
    $routes->get('health', 'HealthController::index');

    $routes->post('invoices/preview', 'InvoicesController::preview');
    $routes->get('invoices/preview/(:num)/xml', 'InvoicesController::xml/$1');

    $routes->get('invoices/(:num)', 'InvoicesController::show/$1');
    $routes->get('invoices/(:num)/qr', 'InvoicesController::qr/$1');
    $routes->get('invoices/(:num)/verifactu', 'InvoicesController::verifactu/$1');
    $routes->get('invoices/(:num)/pdf', 'InvoicesController::pdf/$1');
    $routes->post('invoices/(:num)/cancel', 'InvoicesController::cancel/$1');
});
