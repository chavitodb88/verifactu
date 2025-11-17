<?php

declare(strict_types=1);

namespace App\Domain\Verifactu;

enum CancellationMode: string
{
    /**
     * Caso normal:
     * - La factura tiene un RegistroAlta en AEAT (real o presumible).
     * - Se envía un RegistroAnulacion estándar.
     */
    case AEAT_REGISTERED = 'aeat_registered';

    /**
     * La factura no tiene registro previo en AEAT.
     * Documentación AEAT: usar flag "SinRegistroPrevio".
     */
    case NO_AEAT_RECORD = 'no_aeat_record';

    /**
     * Hubo un intento previo de anulación que AEAT ha rechazado,
     * y ahora volvemos a intentar anular marcando "RechazoPrevio".
     */
    case PREVIOUS_CANCELLATION_REJECTED = 'previous_cancellation_rejected';
}
