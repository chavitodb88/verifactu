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
    private ?string $rawResponse   = null;
    private ?string $fixedLocation = null;

    public function __construct($wsdl, array $options = [])
    {
        $ctx = stream_context_create([
            'ssl' => [
                'local_cert'      => (string) getenv('verifactu.cert_pem'),
                'local_pk'        => (string) getenv('verifactu.key_pem'),
                'passphrase'      => (string) getenv('verifactu.key_pass'),
                // 'verify_peer'     => true,
                // 'verify_peer_name' => true,
                // 'cafile'        => '/etc/ssl/certs/ca-bundle.crt', // si el hosting lo requiere
                // 'allow_url_fopen' => true,
            ],
        ]);

        if (isset($options['location'])) {
            $this->fixedLocation = $options['location'];
        }

        $options['soap_version']   = $options['soap_version'] ?? SOAP_1_1;
        $options['exceptions']     = true;
        $options['trace']          = true;
        $options['cache_wsdl']     = WSDL_CACHE_NONE;
        $options['stream_context'] = $ctx;

        parent::__construct($wsdl, $options);
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

        $wsse                 = new WSSESoap($dom);
        $wsse->signAllHeaders = false;
        $wsse->addTimestamp();

        $key             = new XMLSecurityKey(XMLSecurityKey::RSA_SHA1, ['type' => 'private']);
        $key->passphrase = (string) getenv('verifactu.key_pass');
        $key->loadKey((string) getenv('verifactu.key_pem'), true);
        $wsse->signSoapDoc($key);

        $token = $wsse->addBinaryToken(file_get_contents((string) getenv('verifactu.cert_pem')));
        $wsse->attachTokentoSig($token);

        $signed = $wsse->saveXML();
        $signed = str_replace('SOAP-ENV:mustUnderstand="1"', '', $signed);

        $this->signedRequest = $signed;

        $resp              = parent::__doRequest($signed, $effectiveLocation, $action, $version, $oneWay);
        $this->rawResponse = $resp ?: '';

        return $resp;
    }

    // Getters para debug si __getLastRequest/__getLastResponse vienen vacíos
    public function getLastSignedRequest(): string
    {
        return $this->signedRequest ?? '';
    }
    public function getLastRawResponse(): string
    {
        return $this->rawResponse   ?? '';
    }
}
