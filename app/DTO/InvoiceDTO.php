<?php

declare(strict_types=1);

namespace App\DTO;

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
    public string $series;
    public int $number;
    public string $issueDate; // YYYY-MM-DD
    public ?string $description = null;
    public string $invoiceType = 'F1';

    /** @var array<int, array<string,mixed>> */
    public array $lines = [];

    public ?string $recipientName = null;
    public ?string $recipientNif  = null;
    public ?string $recipientCountry = null;
    public ?string $recipientIdType  = null;
    public ?string $recipientIdNumber = null;

    /** @param array<string,mixed> $in */
    public static function fromArray(array $in): self
    {
        foreach (['issuerNif', 'series', 'number', 'issueDate', 'lines'] as $req) {
            if (!array_key_exists($req, $in)) {
                throw new \InvalidArgumentException("Missing field: {$req}");
            }
        }
        $self = new self();
        $self->issuerNif   = (string) $in['issuerNif'];

        if (!SpanishIdValidator::isValid($self->issuerNif)) {
            throw new \InvalidArgumentException('issuerNif is not a valid Spanish NIF/NIE/CIF');
        }

        $self->issuerName  = isset($in['issuerName']) ? (string) $in['issuerName'] : null;
        $self->series      = (string) $in['series'];
        $self->number      = (int) $in['number'];
        $self->issueDate   = (string) $in['issueDate'];
        $self->description = isset($in['description']) ? (string) $in['description'] : null;
        $self->invoiceType = isset($in['invoiceType']) ? (string)$in['invoiceType'] : 'F1';


        $invoiceType = isset($in['invoiceType']) ? (string)$in['invoiceType'] : 'F1';
        if (!in_array($invoiceType, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException(
                'invoiceType must be one of: ' . implode(', ', self::ALLOWED_TYPES)
            );
        }


        if (!is_array($in['lines']) || count($in['lines']) === 0) {
            throw new \InvalidArgumentException('lines[] is required and must be non-empty');
        }

        // normaliza líneas
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
                'desc'     => (string) $row['desc'],
                'qty'      => (float)  $row['qty'],
                'price'    => (float)  $row['price'],
                'vat'      => (float)  $row['vat'],
                'discount' => isset($row['discount']) ? (float) $row['discount'] : 0.0,
            ];
        }, $in['lines']);

        $recipient = is_array($in['recipient'] ?? null) ? $in['recipient'] : [];
        $self->recipientName     = isset($recipient['name']) ? (string)$recipient['name'] : null;
        $self->recipientNif      = isset($recipient['nif']) ? (string)$recipient['nif'] : null;
        $self->recipientCountry  = isset($recipient['country']) ? (string)$recipient['country'] : null;
        $self->recipientIdType   = isset($recipient['idType']) ? (string)$recipient['idType'] : null;
        $self->recipientIdNumber = isset($recipient['idNumber']) ? (string)$recipient['idNumber'] : null;

        if ($self->recipientNif !== null) {
            if (!SpanishIdValidator::isValid($self->recipientNif)) {
                throw new \InvalidArgumentException('recipient.nif is not a valid Spanish NIF/NIE/CIF');
            }
        }

        // --- Reglas por tipo de factura ---

        $hasRecipientBlock = $self->recipientName
            || $self->recipientNif
            || $self->recipientCountry
            || $self->recipientIdType
            || $self->recipientIdNumber;

        // F2 y R5: NO pueden llevar Destinatarios
        if (in_array($self->invoiceType, ['F2', 'R5'], true) && $hasRecipientBlock) {
            throw new \InvalidArgumentException(
                'For invoiceType F2/R5 the recipient block must be empty (AEAT: no Destinatarios).'
            );
        }

        // Si quieres ser estricto con F1/F3/R1-R4:
        if (in_array($self->invoiceType, ['F1', 'F3', 'R1', 'R2', 'R3', 'R4'], true)) {
            // Ejemplo sencillo: exigir al menos nombre + NIF o IDOtro completo
            $hasNifRecipient = $self->recipientName && $self->recipientNif;
            $hasIdOtro = $self->recipientName
                && $self->recipientCountry
                && $self->recipientIdType
                && $self->recipientIdNumber;

            if (!$hasNifRecipient && !$hasIdOtro) {
                throw new \InvalidArgumentException(
                    'For invoiceType ' . $self->invoiceType .
                        ' you must provide recipient.name + recipient.nif or a full IDOtro (country, idType, idNumber).'
                );
            }
        }

        return $self;
    }
}
