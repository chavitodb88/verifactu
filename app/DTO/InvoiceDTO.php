<?php

declare(strict_types=1);

namespace App\DTO;

use App\Domain\Verifactu\RectifyMode;
use App\Services\SpanishIdValidator;

final class InvoiceDTO
{
    public const ALLOWED_TYPES = [
        'F1',
        'F2',
        'F3',
        'R1',
        'R2',
        'R3',
        'R4',
        'R5',
    ];

    public string $issuerNif;
    public ?string $issuerName = null;
    public ?string $issuerAddress     = null;
    public ?string $issuerPostalCode  = null;
    public ?string $issuerCity        = null;
    public ?string $issuerProvince    = null;
    public ?string $issuerCountry     = null;
    public string $series;
    public int $number;
    public string $issueDate; // YYYY-MM-DD
    public ?string $description = null;
    public string $invoiceType = 'F1';

    /** @var array<int, array<string,mixed>> */
    public array $lines = [];

    public ?string $recipientName = null;
    public ?string $recipientNif = null;
    public ?string $recipientCountry = null;
    public ?string $recipientIdType = null;
    public ?string $recipientIdNumber = null;

    public ?string $recipientAddress = null;
    public ?string $recipientPostalCode = null;
    public ?string $recipientCity = null;
    public ?string $recipientProvince = null;

    public ?string $taxRegimeCode = null;
    public ?string $operationQualification = null;

    /** Rectificación (solo para tipos R1–R5) */
    public ?InvoiceRectifyDTO $rectify = null;

    /**
     * @param array<string,mixed> $in
     */
    public static function fromArray(array $in): self
    {
        foreach (['issuer', 'series', 'number', 'issueDate', 'lines'] as $req) {
            if (!array_key_exists($req, $in)) {
                throw new \InvalidArgumentException("Missing field: {$req}");
            }
        }

        $self = new self();

        // --- Emisor ---
        $issuerBlock = is_array($in['issuer'] ?? null) ? $in['issuer'] : [];
        $issuerNif  = $issuerBlock['nif'] ?? null;

        if (!SpanishIdValidator::isValid($issuerNif)) {
            throw new \InvalidArgumentException('issuerNif is not a valid Spanish NIF/NIE/CIF');
        }
        $self->issuerNif  = (string) $issuerNif;
        $self->issuerName = isset($issuerBlock['name']) ? (string)$issuerBlock['name'] : null;

        $self->issuerAddress     = isset($issuerBlock['address'])    ? (string)$issuerBlock['address']    : null;
        $self->issuerPostalCode  = isset($issuerBlock['postalCode']) ? (string)$issuerBlock['postalCode'] : null;
        $self->issuerCity        = isset($issuerBlock['city'])       ? (string)$issuerBlock['city']       : null;
        $self->issuerProvince    = isset($issuerBlock['province'])   ? (string)$issuerBlock['province']   : null;
        $self->issuerCountry     = isset($issuerBlock['country'])    ? (string)$issuerBlock['country']    : null;

        $self->series = (string)$in['series'];
        $self->number = (int)$in['number'];
        $self->issueDate = (string)$in['issueDate'];
        $self->description = isset($in['description']) ? (string)$in['description'] : null;

        // --- Tipo de factura (F1/F2/F3/R1–R5) ---
        $invoiceType = isset($in['invoiceType']) ? strtoupper((string)$in['invoiceType']) : 'F1';
        if (!in_array($invoiceType, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException(
                'invoiceType must be one of: ' . implode(', ', self::ALLOWED_TYPES)
            );
        }
        $self->invoiceType = $invoiceType;

        // --- Régimen y calificación de la operación ---
        // FASE 1: sólo soportamos 01 / S1, pero dejamos el campo abierto a futuro
        $taxRegimeCode = isset($in['taxRegimeCode']) ? (string)$in['taxRegimeCode'] : '01';
        $operationQualification = isset($in['operationQualification']) ? (string)$in['operationQualification'] : 'S1';

        $allowedRegimes = ['01']; // régimen general
        $allowedQualifications = ['S1']; // sujeta y no exenta - operación interior

        if (!in_array($taxRegimeCode, $allowedRegimes, true)) {
            throw new \InvalidArgumentException('taxRegimeCode not supported yet (only "01" allowed)');
        }

        if (!in_array($operationQualification, $allowedQualifications, true)) {
            throw new \InvalidArgumentException('operationQualification not supported yet (only "S1" allowed)');
        }

        $self->taxRegimeCode = $taxRegimeCode;
        $self->operationQualification = $operationQualification;

        // --- Líneas ---
        if (!is_array($in['lines']) || count($in['lines']) === 0) {
            throw new \InvalidArgumentException('lines[] is required and must be non-empty');
        }

        $self->lines = array_map(static function ($row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('each line must be an object');
            }
            foreach (['desc', 'qty', 'price', 'vat'] as $k) {
                if (!array_key_exists($k, $row)) {
                    throw new \InvalidArgumentException("line missing field: {$k}");
                }
            }

            return [
                'desc'     => (string)$row['desc'],
                'qty'      => (float)$row['qty'],
                'price'    => (float)$row['price'],
                'vat'      => (float)$row['vat'],
                'discount' => isset($row['discount']) ? (float)$row['discount'] : 0.0,
            ];
        }, $in['lines']);

        // --- Destinatario (bloque recipient) ---
        $recipient = is_array($in['recipient'] ?? null) ? $in['recipient'] : [];
        $self->recipientName = isset($recipient['name']) ? (string)$recipient['name'] : null;
        $self->recipientNif = isset($recipient['nif']) ? (string)$recipient['nif'] : null;
        $self->recipientCountry = isset($recipient['country']) ? (string)$recipient['country'] : null;
        $self->recipientIdType = isset($recipient['idType']) ? (string)$recipient['idType'] : null;
        $self->recipientIdNumber = isset($recipient['idNumber']) ? (string)$recipient['idNumber'] : null;
        // NUEVOS CAMPOS OPCIONALES
        $self->recipientAddress = isset($recipient['address']) ? (string)$recipient['address'] : null;
        $self->recipientPostalCode = isset($recipient['postalCode']) ? (string)$recipient['postalCode'] : null;
        $self->recipientCity = isset($recipient['city']) ? (string)$recipient['city'] : null;
        $self->recipientProvince = isset($recipient['province']) ? (string)$recipient['province'] : null;

        // Si viene NIF, validar sintácticamente
        if ($self->recipientNif !== null) {
            if (!SpanishIdValidator::isValid($self->recipientNif)) {
                throw new \InvalidArgumentException('recipient.nif is not a valid Spanish NIF/NIE/CIF');
            }
        }

        // ========================
        // Validación IDOtro
        // ========================

        $hasNifRecipient = $self->recipientName && $self->recipientNif;
        $hasIdOtro = $self->recipientName
            && $self->recipientCountry
            && $self->recipientIdType
            && $self->recipientIdNumber;

        // IDType permitido por AEAT: 02, 03, 04, 05, 06, 07
        $validIdTypes = ['02', '03', '04', '05', '06', '07'];

        if ($hasIdOtro) {
            if (!in_array($self->recipientIdType, $validIdTypes, true)) {
                throw new \InvalidArgumentException(
                    'recipient.idType must be one of: ' . implode(', ', $validIdTypes)
                );
            }

            // IDOtro es para identificadores NO nacionales → pais ≠ ES
            if (strtoupper($self->recipientCountry) === 'ES') {
                throw new \InvalidArgumentException(
                    'For Spanish recipients you must use recipient.nif (not IDOtro).'
                );
            }
        }

        $hasRecipientBlock = $self->recipientName
            || $self->recipientNif
            || $self->recipientCountry
            || $self->recipientIdType
            || $self->recipientIdNumber;

        // F2 y R5: NO pueden llevar destinatario (ni NIF ni IDOtro)
        if (in_array($self->invoiceType, ['F2', 'R5'], true) && $hasRecipientBlock) {
            throw new \InvalidArgumentException(
                'For invoiceType F2/R5 the recipient block must be empty (AEAT: no Destinatarios).'
            );
        }

        // F1/F3/R1–R4: exigir destinatario (NIF o IDOtro completo)
        if (in_array($self->invoiceType, ['F1', 'F3', 'R1', 'R2', 'R3', 'R4'], true)) {
            if (!$hasNifRecipient && !$hasIdOtro) {
                throw new \InvalidArgumentException(
                    'For invoiceType ' . $self->invoiceType .
                        ' you must provide recipient.name + recipient.nif or a full IDOtro (country, idType, idNumber).'
                );
            }
        }

        // --- Bloque de rectificación para tipos R* (R1–R5) ---
        if (str_starts_with($self->invoiceType, 'R')) {
            $self->rectify = InvoiceRectifyDTO::fromArray(
                isset($in['rectify']) && is_array($in['rectify']) ? $in['rectify'] : null
            );
        }

        return $self;
    }

    public function isRectification(): bool
    {
        return str_starts_with($this->invoiceType, 'R');
    }

    public function rectifyModeOrNull(): ?RectifyMode
    {
        return $this->rectify?->mode ?? null;
    }
}
