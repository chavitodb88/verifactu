<?php

declare(strict_types=1);

namespace Config;

use App\Billing\Auth\ApiKeyCompanyResolver;
use App\Billing\Auth\TelelavoTokenCompanyResolver;
use App\Billing\Auth\MyTransferHeaderCompanyResolver;
use App\Billing\Auth\WeclubTokenCompanyResolver;
use CodeIgniter\Config\BaseConfig;

final class CompanyContext extends BaseConfig
{
    public array $resolverFactories = [];

    public function __construct()
    {
        parent::__construct();

        $tenant = getenv('verifactu.tenant') ?: 'generic';

        switch ($tenant) {
            case 'telelavo':
                $telelavoCfg = config(\Config\Telelavo::class);

                $this->resolverFactories = [
                    'apiKey' => static fn() => new ApiKeyCompanyResolver(),
                    'telelavoToken' => static fn() => new TelelavoTokenCompanyResolver(
                        $telelavoCfg->baseUrl,
                        $telelavoCfg->franchiseInfoPath,
                        $telelavoCfg->timeout
                    ),
                ];
                break;
            //TODO: integrar mytransfer cuando sea necesario
            // case 'mytransfer':
            //     $this->resolverFactories = [
            //         'apiKey' => static fn() => new ApiKeyCompanyResolver(),
            //         'mytransferHeader' => static fn() => new MyTransferHeaderCompanyResolver(),
            //     ];
            //     break;
            //TODO: integrar Weclub cuando sea necesario
            // case 'weclub':
            //     $weclubCfg = config(\Config\Weclub::class);

            //     $this->resolverFactories = [
            //         'apiKey' => static fn() => new ApiKeyCompanyResolver(),
            //         'weclubToken' => static fn() => new WeclubTokenCompanyResolver(
            //             $weclubCfg->baseUrl,
            //             $weclubCfg->clubInfoPath,
            //             $weclubCfg->timeout
            //         ),
            //     ];
            //     break;

            default:
                // Modo genérico: solo API key
                $this->resolverFactories = [
                    'apiKey' => static fn() => new ApiKeyCompanyResolver(),
                ];
        }
    }
}
