<?php

declare(strict_types=1);

namespace App\DTO;

use App\Domain\Verifactu\RectifyMode;

final class InvoiceRectifyDTO
{
    public function __construct(
        public RectifyMode $mode,
        public ?string $reason,
        public string $originalSeries,
        public int $originalNumber,
        public string $originalIssueDate
    ) {
    }

    public static function fromArray(?array $in): ?self
    {
        if (!$in) {
            return null;
        }

        $mode = match (($in['mode'] ?? null)) {
            'substitution' => RectifyMode::SUBSTITUTION,
            'difference'   => RectifyMode::DIFFERENCE,
            default        => throw new \InvalidArgumentException('rectify.mode must be "substitution" or "difference"'),
        };

        $orig = $in['original'] ?? null;
        if (!is_array($orig)) {
            throw new \InvalidArgumentException('rectify.original is required for rectification invoices');
        }

        $series = trim((string)($orig['series'] ?? ''));
        $number = (int)($orig['number'] ?? 0);
        $issue  = trim((string)($orig['issueDate'] ?? ''));

        if ($series === '' || $number <= 0 || $issue === '') {
            throw new \InvalidArgumentException('rectify.original requires series, number and issueDate');
        }

        return new self(
            $mode,
            isset($in['reason']) ? (string)$in['reason'] : null,
            $series,
            $number,
            $issue
        );
    }

    public function toArray(): array
    {
        return [
            'mode'     => $this->mode->value,
            'reason'   => $this->reason,
            'original' => [
                'series'    => $this->originalSeries,
                'number'    => $this->originalNumber,
                'issueDate' => $this->originalIssueDate,
            ],
        ];
    }
}
