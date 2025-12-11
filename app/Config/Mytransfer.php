<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

final class Mytransfer extends BaseConfig
{
    /**
     * URL base del API de Telelavo (sin slash al final).
     * Ejemplo: https://api.telelavo.com
     */
    public string $baseUrl = 'https://apiv2.mytransfer.test';

    /**
     * Path del endpoint que devuelve la franquicia actual del token.
     * Ejemplo: /franchise-info
     */
    public string $franchiseInfoPath = '/my-company';

    /**
     * Timeout en segundos para la llamada HTTP.
     */
    public int $timeout = 3;
}
