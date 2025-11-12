<?php

namespace Config;

use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /*
     * public static function example($getShared = true)
     * {
     *     if ($getShared) {
     *         return static::getSharedInstance('example');
     *     }
     *
     *     return new \CodeIgniter\Example();
     * }
     */
    public static function requestContext(bool $getShared = true): \App\Services\RequestContext
    {
        if ($getShared) {
            return static::getSharedInstance('requestContext');
        }
        return new \App\Services\RequestContext();
    }


    public static function verifactu(bool $getShared = true): \App\Services\VerifactuService
    {
        if ($getShared) return static::getSharedInstance('verifactu');
        return new \App\Services\VerifactuService();
    }


    public static function verifactuCanonical(bool $getShared = true): \App\Services\VerifactuCanonicalService
    {
        if ($getShared) {
            return static::getSharedInstance('verifactuCanonical');
        }
        return new \App\Services\VerifactuCanonicalService();
    }

    public static function verifactuXmlBuilder(bool $getShared = true): \App\Services\VerifactuXmlBuilder
    {
        if ($getShared) {
            return static::getSharedInstance('verifactuXmlBuilder');
        }
        return new \App\Services\VerifactuXmlBuilder();
    }

    public static function verifactuPayload(bool $getShared = true): \App\Services\VerifactuAeatPayloadBuilder
    {
        if ($getShared) return static::getSharedInstance('verifactuPayload');
        return new \App\Services\VerifactuAeatPayloadBuilder();
    }

    public static function verifactuSoap(bool $getShared = true): \App\Libraries\VerifactuSoapClient
    {
        if ($getShared) return static::getSharedInstance('verifactuSoap');
        return new \App\Libraries\VerifactuSoapClient();
    }
}
