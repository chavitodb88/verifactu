<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use OpenApi\Generator;
use Psr\Log\NullLogger;

class SwaggerDocGenerator extends BaseController
{
    public function generate(): ResponseInterface
    {
        $openapi = Generator::scan(
            [
                APPPATH . 'Swagger',
                APPPATH . 'Controllers/Api/V1',
            ],
            [
                'logger' => new NullLogger(),
            ]
        );

        $json = $openapi->toJson();

        // $filePath = FCPATH . 'swagger_ui/swagger.json';
        // @is_dir(dirname($filePath)) || @mkdir(dirname($filePath), 0775, true);
        // file_put_contents($filePath, $json);

        return $this->response->setStatusCode(200)->setContentType('application/json')->setBody($json);
    }

    public function ui()
    {
        return view('swagger_docs/index');
    }
}
