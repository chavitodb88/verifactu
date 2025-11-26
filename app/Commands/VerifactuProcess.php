<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\BillingHashModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class VerifactuProcess extends BaseCommand
{
    protected $group = 'Verifactu';
    protected $name = 'verifactu:process';
    protected $description = 'Procesa envíos a AEAT en cola local (ready/error con backoff).';
    protected $usage = 'verifactu:process [limit]';
    protected $arguments = ['limit' => 'Máximo de items a procesar (por defecto 50)'];

    public function run(array $params)
    {
        $limit = isset($params[0]) ? max(1, (int) $params[0]) : 50;
        $now = date('Y-m-d H:i:s');

        $model = new BillingHashModel();

        $rows = $model
            ->whereIn('status', ['ready', 'error'])
            ->groupStart()
            ->where('next_attempt_at IS NULL', null, false)
            ->orWhere('next_attempt_at <=', $now)
            ->groupEnd()
            ->where('processing_at IS NULL', null, false)
            ->orderBy('updated_at', 'ASC')
            ->findAll($limit);

        if (!$rows) {
            CLI::write('No items to process', 'yellow'); // <- usar CLI::write

            return;
        }

        $svc = service('verifactu');

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            $locked = $model->where('id', $id)
                ->where('processing_at IS NULL', null, false)
                ->set('processing_at', $now)
                ->update();

            if (!$locked) {
                continue;
            }

            try {
                $svc->sendToAeat($id);
                CLI::write("✅ sent billing_hash_id={$id}", 'green'); // <- usar CLI::write
            } catch (\Throwable $e) {
                $backoff = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                $model->update($id, [
                    'status'          => 'error',
                    'processing_at'   => null,
                    'next_attempt_at' => $backoff,
                ]);
                CLI::write("❌ error billing_hash_id={$id} :: {$e->getMessage()}", 'red'); // <- usar CLI::write
            }
        }
    }
}
