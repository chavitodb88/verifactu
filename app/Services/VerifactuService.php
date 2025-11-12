<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BillingHashModel;
use App\Models\SubmissionsModel;

final class VerifactuService
{
    /**
     * Simula el envío a AEAT:
     * - Construye un XML "oficial" mínimo (no XSD todavía)
     * - Guarda raw request/response en writable
     * - Inserta submission con paths
     * - Actualiza estado del billing_hash: sent (o accepted si quieres)
     *
     * Lanza \RuntimeException para disparar reintentos.
     */
    public function sendToAeat(int $billingHashId): void
    {
        $bhModel = new BillingHashModel();
        $row = $bhModel->find($billingHashId);
        if (!$row) {
            throw new \RuntimeException('billing_hash not found');
        }

        // Sólo procesamos ready/error
        if (!in_array((string)$row['status'], ['ready', 'error'], true)) {
            // No es procesable ahora mismo
            return;
        }

        // 1) Construir XML “oficial” mínimo a partir del preview
        $xml = $this->buildOfficialXml($row);

        // 2) Guardar raw request
        [$reqPath, $resPath] = $this->ensurePaths((int)$row['id']);
        file_put_contents($reqPath, $xml);

        // 3) “Enviar” (simulación)
        //    Aquí luego vendrá el SOAP real. Mientras tanto simulamos éxito.
        $simulatedResponse = [
            'http_status' => 200,
            'aeat_status' => 'ACCEPTED', // puedes variar a REJECTED para probar
            'message'     => 'Simulated send OK',
            'ts'          => date('c'),
        ];
        file_put_contents($resPath, json_encode($simulatedResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // 4) Registrar submission
        $subs = new SubmissionsModel();
        $attempt = 1 + (int) $subs->where('billing_hash_id', (int)$row['id'])->countAllResults();
        $subs->insert([
            'billing_hash_id' => (int)$row['id'],
            'type'            => 'register', // en el futuro: 'register' | 'cancel'
            'status'          => 'sent',     // o 'accepted' si quieres marcarlo ya
            'attempt_number'  => $attempt,
            'request_ref'     => basename($reqPath),
            'response_ref'    => basename($resPath),
            'raw_req_path'    => $reqPath,
            'raw_res_path'    => $resPath,
            'error_code'      => null,
            'error_message'   => null,
        ]);

        // 5) Actualizar estado del documento
        $bhModel->update((int)$row['id'], [
            'status'          => 'sent',      // o 'accepted' si prefieres cerrar el ciclo
            'processing_at'   => null,
            'next_attempt_at' => null,
        ]);
    }

    /**
     * Genera un XML “oficial” mínimo (no XSD, no WSSE).
     * Usa los campos clave del billing_hash y lo que guardamos en preview.
     */
    private function buildOfficialXml(array $bh): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElement('RegFactuSistemaFacturacion');
        $doc->appendChild($root);

        $cab = $doc->createElement('Cabecera');
        $root->appendChild($cab);
        $obl = $doc->createElement('ObligadoEmision');
        $cab->appendChild($obl);
        $obl->appendChild($doc->createElement('NombreRazon', 'Empresa (placeholder)'));
        $obl->appendChild($doc->createElement('NIF', (string)$bh['issuer_nif']));

        $reg = $doc->createElement('RegistroFactura');
        $root->appendChild($reg);

        $alta = $doc->createElement('RegistroAlta');
        $reg->appendChild($alta);

        $alta->appendChild($doc->createElement('IDVersion', '1.0'));

        $idFactura = $doc->createElement('IDFactura');
        $alta->appendChild($idFactura);

        $numSerie = (string) ($bh['series'] . $bh['number']);
        $idFactura->appendChild($doc->createElement('IDEmisorFactura', (string)$bh['issuer_nif']));
        $idFactura->appendChild($doc->createElement('NumSerieFactura', $numSerie));
        // issue_date ya viene YYYY-MM-DD. Si quieres dd-mm-YYYY, conviértela.
        $idFactura->appendChild($doc->createElement('FechaExpedicionFactura', $bh['issue_date']));

        $enc = $doc->createElement('Encadenamiento');
        $alta->appendChild($enc);
        if (!empty($bh['prev_hash'])) {
            $regAnt = $doc->createElement('RegistroAnterior');
            $enc->appendChild($regAnt);
            $regAnt->appendChild($doc->createElement('IDEmisorFactura', (string)$bh['issuer_nif']));
            $regAnt->appendChild($doc->createElement('NumSerieFactura', $numSerie)); // placeholder
            $regAnt->appendChild($doc->createElement('FechaExpedicionFactura', $bh['issue_date']));
            $regAnt->appendChild($doc->createElement('Huella', (string)$bh['prev_hash']));
        } else {
            $enc->appendChild($doc->createElement('PrimerRegistro', 'S'));
        }

        $alta->appendChild($doc->createElement('TipoHuella', '01'));
        $alta->appendChild($doc->createElement('Huella', (string)$bh['hash']));

        // Si en csv_text guardaste tu cadena canónica, la incrustas como comentario para debug:
        if (!empty($bh['csv_text'])) {
            $alta->appendChild($doc->createComment('CadenaCanonica: ' . (string)$bh['csv_text']));
        }

        return $doc->saveXML() ?: '';
    }

    /**
     * Asegura directorios y devuelve [requestPath, responsePath] para este id.
     */
    private function ensurePaths(int $id): array
    {
        $base = WRITEPATH . 'verifactu';
        $reqDir = $base . '/requests';
        $resDir = $base . '/responses';
        if (!is_dir($reqDir)) @mkdir($reqDir, 0775, true);
        if (!is_dir($resDir)) @mkdir($resDir, 0775, true);

        $reqPath = $reqDir . "/{$id}-request.xml";
        $resPath = $resDir . "/{$id}-response.json";

        return [$reqPath, $resPath];
    }
}
