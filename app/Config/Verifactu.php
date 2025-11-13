<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

final class Verifactu extends BaseConfig
{
    public string $systemNameReason = '';
    public string $systemNif = '';
    public string $systemName = '';
    public string $systemId = '';
    public string $systemVersion = '';
    public string $installNumber = '';
    public string $onlyVerifactu = '';
    public string $multiOt = '';
    public string $multiplesOt = '';
}
