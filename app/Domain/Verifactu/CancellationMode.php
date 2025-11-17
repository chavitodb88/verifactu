<?php

declare(strict_types=1);

namespace App\Domain\Verifactu;

enum CancellationMode: string
{
    /**
     * Caso normal: el registro de alta existe en la AEAT.
     * -> No se informa SinRegistroPrevio ni RechazoPrevio.
     */
    case AEAT_REGISTERED = 'aeat_registered';

    /**
     * Caso especial: la factura a anular NO tiene registro en la AEAT
     * (NO-VERI*FACTU antiguo, o alta rechazada que decides anular).
     * -> <SinRegistroPrevio>=S
     */
    case NO_AEAT_RECORD = 'no_aeat_record';

    /**
     * Caso aún más raro: ya intentaste una anulación y fue rechazada
     * y ahora envías una anulación “subsanada”.
     * -> <RechazoPrevio>=S
     */
    case PREVIOUS_CANCELLATION_REJECTED = 'previous_cancellation_rejected';
}
