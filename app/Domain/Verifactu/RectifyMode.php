<?php

declare(strict_types=1);

namespace App\Domain\Verifactu;

enum RectifyMode: string
{
    case SUBSTITUTION = 'substitution'; // S
    case DIFFERENCE   = 'difference';   // I
}
