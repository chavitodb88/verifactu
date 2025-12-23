<?php

declare(strict_types=1);

namespace App\Libraries;

final class VerifactuSoapClient extends MySoap
{
    public function __construct(?bool $test = null)
    {
        $cfg = config('Verifactu');
        $isTest = $test ?? $cfg->isTest;

        $wsdl = $isTest
            ? $cfg->qrBaseUrlTest . 'static_files/common/internet/dep/aplicaciones/es/aeat/tikeV1.0/cont/ws/SistemaFacturacion.wsdl'
            : $cfg->qrBaseUrlProd . 'static_files/common/internet/dep/aplicaciones/es/aeat/tikeV1.0/cont/ws/SistemaFacturacion.wsdl';

        $options = [
            'soap_version' => SOAP_1_1,
            'exceptions'   => true,
            'trace'        => true,
            'cache_wsdl'   => WSDL_CACHE_NONE,
            'location'     => $isTest
                ? 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP'
                : 'https://www1.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP',
        ];

        parent::__construct($wsdl, $options);
    }

    public function sendInvoice(array $payload): array
    {
        try {
            $response = $this->__soapCall('RegFactuSistemaFacturacion', [$payload]);

            return [
                'raw_response' => $response,
                'request_xml'  => $this->__getLastRequest(),
                'response_xml' => $this->__getLastResponse(),
            ];
        } catch (\Throwable $e) {
            log_message(
                'error',
                'VerifactuSoapClient ERROR: ' . $e->getMessage()
                    . "\nREQUEST:\n" . $this->__getLastRequest()
                    . "\nRESPONSE:\n" . $this->__getLastResponse()
                    . "\nHEADERS:\n" . $this->__getLastRequestHeaders()
            );

            throw $e;
        }
    }

    public function consultarFacturas(array $payload): array
    {
        try {
            $response = $this->__soapCall('ConsultaFactuSistemaFacturacion', [$payload]);

            return [
                'raw_response' => $response,
                'request_xml'  => $this->__getLastRequest(),
                'response_xml' => $this->__getLastResponse(),
            ];
        } catch (\Throwable $e) {
            log_message(
                'error',
                'VerifactuSoapClient ERROR: ' . $e->getMessage()
                    . "\nREQUEST:\n" . $this->__getLastRequest()
                    . "\nRESPONSE:\n" . $this->__getLastResponse()
            );

            throw $e;
        }
    }
}
