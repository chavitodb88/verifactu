<?php

declare(strict_types=1);

namespace App\Services;

final class VerifactuDispatcher
{
    /**
     * Dispara el procesador de cola (best-effort).
     * - NO rompe la request si falla.
     * - Evita disparos masivos (lock corto).
     */
    public function kick(int $limit = 1): bool
    {
        // Anti-rebote (evita 20 kicks en 1 segundo)
        $lockFile = WRITEPATH . 'cache/verifactu_kick.lock';
        $ttl      = (int) (env('verifactu.dispatchTtl', 3)); // segundos

        $now = time();
        if (is_file($lockFile)) {
            $age = $now - (int) @filemtime($lockFile);
            if ($age >= 0 && $age < $ttl) {
                return false;
            }
        }

        @file_put_contents($lockFile, (string) $now);

        $mode = strtolower((string) env('verifactu.dispatchMode', 'noop')); // noop|spark

        if ($mode === 'noop') {
            // Docker con worker siempre activo -> no hace falta lanzar nada
            return true;
        }

        if ($mode !== 'spark') {
            return false;
        }

        $phpBin = (string) env('verifactu.phpBin', PHP_BINARY);
        $spark  = ROOTPATH . 'spark';

        $limit = max(1, $limit);

        $logFile = WRITEPATH . 'logs/verifactu-dispatch.log';

        $cmd = 'nohup '
            . escapeshellcmd($phpBin)
            . ' ' . escapeshellarg($spark)
            . ' verifactu:process ' . (int) $limit
            . ' >> ' . escapeshellarg($logFile)
            . ' 2>&1 &';

        exec($cmd);

        return true;
    }
}
