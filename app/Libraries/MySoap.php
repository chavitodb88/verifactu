<?php

declare(strict_types=1);

namespace App\Libraries;

use DOMDocument;
use RobRichards\WsePhp\WSASoap;
use RobRichards\WsePhp\WSSESoap;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use SoapClient;

class MySoap extends SoapClient
{
    private ?string $signedRequest = null;
    private ?string $rawResponse = null;
    private ?string $fixedLocation = null;
    private $cfg;

    public function __construct($wsdl, array $options = [])
    {
        $this->cfg = config('Verifactu');

        $ctx = stream_context_create([
            'ssl' => [
                // Mutual TLS (cert cliente)
                'local_cert' => (string) $this->cfg->certPem,
                'local_pk'   => (string) $this->cfg->keyPem,
                'passphrase' => (string) $this->cfg->keyPass,

                'cafile' => '/etc/ssl/certs/ca-certificates.crt',
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
            ],
            'http' => [
                'timeout' => 30,
            ],
        ]);


        libxml_set_streams_context($ctx);

        if (isset($options['location'])) {
            $this->fixedLocation = $options['location'];
        }

        $options['soap_version'] = $options['soap_version'] ?? SOAP_1_1;
        $options['exceptions']   = $options['exceptions'] ?? true;
        $options['trace']        = $options['trace'] ?? true;
        $options['cache_wsdl']   = $options['cache_wsdl'] ?? WSDL_CACHE_NONE;
        $options['stream_context'] = $options['stream_context'] ?? $ctx;
        $options['connection_timeout'] = $options['connection_timeout'] ?? 30;

        // --- FIX: el contenedor no puede acceder a W3C por HTTP (80), pero sí por HTTPS (443)
        // Reescribimos SOLO esta URL http->https durante la carga del WSDL/XSD del SoapClient.
        $prevLoader = null;

        if (function_exists('libxml_set_external_entity_loader')) {
            $prev = libxml_set_external_entity_loader(function ($public, $system, $context) {
                if ($system === 'http://www.w3.org/TR/xmldsig-core/xmldsig-core-schema.xsd') {
                    $system = 'https://www.w3.org/TR/xmldsig-core/xmldsig-core-schema.xsd';
                }
                if (!$system) {
                    return null;
                }
                return fopen($system, 'r', false, $context);
            });

            // Normalizamos el tipo para evitar warnings de tipado (Intelephense)
            if (is_callable($prev) || $prev === null) {
                $prevLoader = $prev;
            }
        }

        try {
            parent::__construct($wsdl, $options);
        } finally {
            if (function_exists('libxml_set_external_entity_loader')) {
                libxml_set_external_entity_loader($prevLoader);
            }
        }
    }


    public function __doRequest(
        string $request,
        string $location,
        string $action,
        int    $version,
        bool   $oneWay = false
    ): ?string {
        $dom = new DOMDocument();
        $dom->loadXML($request);

        $wsa = new WSASoap($dom);
        $wsa->addAction($action);

        $effectiveLocation = $this->fixedLocation ?? $location;
        $wsa->addTo($effectiveLocation);

        $wsa->addMessageID();
        $wsa->addReplyTo();
        $dom = $wsa->getDoc();

        $wsse = new WSSESoap($dom);
        $wsse->signAllHeaders = false;
        $wsse->addTimestamp();

        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA1, ['type' => 'private']);
        $key->passphrase = (string) $this->cfg->keyPass;
        $key->loadKey((string) $this->cfg->keyPem, true);
        $wsse->signSoapDoc($key);

        $token = $wsse->addBinaryToken(file_get_contents((string) $this->cfg->certPem));
        $wsse->attachTokentoSig($token);

        $signed = $wsse->saveXML();
        $signed = str_replace('SOAP-ENV:mustUnderstand="1"', '', $signed);

        $this->signedRequest = $signed;

        $resp = parent::__doRequest($signed, $effectiveLocation, $action, $version, $oneWay);
        $this->rawResponse = $resp ?: '';

        return $resp;
    }

    public function getLastSignedRequest(): string
    {
        return $this->signedRequest ?? '';
    }

    public function getLastRawResponse(): string
    {
        return $this->rawResponse ?? '';
    }
}
