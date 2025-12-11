<?php

declare(strict_types=1);

namespace App\Billing\Auth;

final class MyTransferHeaderCompanyResolver extends AbstractHttpTokenCompanyResolver
{
    /**
     * Del JSON de /franchise-info sacamos el CIF y lo usamos como issuer_nif en companies.
     *
     * @param array $json
     * @return array|null
     */
    protected function extractCompanyLookupKey(array $json): ?array
    {
        $cif = $json['data']['document'] ?? null;

        if (! $cif) {
            return null;
        }

        /**
         * Buscaremos en companies donde issuer_nif = cif
         */
        return [
            'issuer_nif' => $cif,
        ];
    }
}
