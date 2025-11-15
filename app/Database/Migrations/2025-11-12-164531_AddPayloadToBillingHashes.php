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
            'details_json'     => ['type' => 'LONGTEXT', 'null' => true, 'after' => 'raw_payload_json'],
            'vat_total'      => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true, 'after' => 'details_json'],
            'gross_total'    => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true, 'after' => 'vat_total'],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('billing_hashes', ['raw_payload_json', 'details_json', 'vat_total', 'gross_total']);
    }
}
