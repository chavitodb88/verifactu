<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddPayloadToBillingHashes extends Migration
{
    public function up()
    {
        $this->forge->addColumn('billing_hashes', [
            'raw_payload_json' => ['type' => 'LONGTEXT', 'null' => true, 'after' => 'idempotency_key'],
            'detalle_json'     => ['type' => 'LONGTEXT', 'null' => true, 'after' => 'raw_payload_json'],
            'cuota_total'      => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true, 'after' => 'detalle_json'],
            'importe_total'    => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true, 'after' => 'cuota_total'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('billing_hashes', ['raw_payload_json', 'detalle_json', 'cuota_total', 'importe_total']);
    }
}
