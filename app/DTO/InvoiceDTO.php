<?php

declare(strict_types=1);

namespace App\DTO;

final class InvoiceDTO
{
    /** @var string */
    public $issuerNif;
    /** @var string */
    public $series;
    /** @var string */
    public $number;
    /** @var string YYYY-MM-DD */
    public $issueDate;
    /** @var array<string,mixed>|null */
    public $customer;
    /** @var array<int,array<string,mixed>> */
    public $lines;
    /** @var array{net:float|int,vat:float|int,gross:float|int} */
    public $totals;
    /** @var string|null */
    public $currency;
    /** @var string|null */
    public $externalId;
    /** @var array<string,mixed>|null */
    public $meta;

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        if (!isset($payload['issuer_nif'], $payload['invoice']) || !is_array($payload['invoice'])) {
            throw new \InvalidArgumentException('issuer_nif and invoice are required');
        }

        $inv = $payload['invoice'];
        foreach (['series', 'number', 'issue_date', 'totals', 'lines'] as $req) {
            if (!array_key_exists($req, $inv)) {
                throw new \InvalidArgumentException("invoice.$req is required");
            }
        }
        if (!is_string($payload['issuer_nif'])) {
            throw new \InvalidArgumentException('issuer_nif must be string');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $inv['issue_date'])) {
            throw new \InvalidArgumentException('invoice.issue_date must be YYYY-MM-DD');
        }
        if (!is_array($inv['totals']) || !isset($inv['totals']['net'], $inv['totals']['vat'], $inv['totals']['gross'])) {
            throw new \InvalidArgumentException('invoice.totals must include net, vat, gross');
        }
        if (!is_array($inv['lines']) || count($inv['lines']) === 0) {
            throw new \InvalidArgumentException('invoice.lines must be a non-empty array');
        }

        $dto = new self();
        $dto->issuerNif = (string) $payload['issuer_nif'];
        $dto->series     = (string) $inv['series'];
        $dto->number     = (string) $inv['number'];
        $dto->issueDate  = (string) $inv['issue_date'];
        $dto->customer   = isset($inv['customer']) && is_array($inv['customer']) ? $inv['customer'] : null;
        $dto->lines      = $inv['lines'];
        $dto->totals     = [
            'net'   => (float) $inv['totals']['net'],
            'vat'   => (float) $inv['totals']['vat'],
            'gross' => (float) $inv['totals']['gross'],
        ];
        $dto->currency   = isset($inv['currency']) ? (string) $inv['currency'] : null;
        $dto->externalId = isset($payload['external_id']) ? (string) $payload['external_id'] : null;
        $dto->meta       = isset($inv['meta']) && is_array($inv['meta']) ? $inv['meta'] : null;

        return $dto;
    }
}
