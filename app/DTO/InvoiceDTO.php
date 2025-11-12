<?php

declare(strict_types=1);

namespace App\DTO;

final class InvoiceDTO
{
    /** @var array<int,array<string,mixed>> */
    public array $lines = [];

    public string $issuerNif;
    public ?string $issuerName = null;
    public string $series;
    public int $number;
    public string $issueDate; // YYYY-MM-DD
    public ?string $description = null;

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
        $self->issuerName  = isset($in['issuerName']) ? (string) $in['issuerName'] : null;
        $self->series      = (string) $in['series'];
        $self->number      = (int) $in['number'];
        $self->issueDate   = (string) $in['issueDate'];
        $self->description = isset($in['description']) ? (string) $in['description'] : null;

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

        return $self;
    }
}
