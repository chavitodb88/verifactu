<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPdfCancelReason extends Migration
{
    public function up()
    {
        $this->forge->addColumn('billing_hashes', [
            'original_billing_hash_id' => [
                'type'  => 'INT',
                'null'  => true,
                'after' => 'id',
            ],
            'cancel_reason' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('billing_hashes', 'original_billing_hash_id');
        $this->forge->dropColumn('billing_hashes', 'cancel_reason');
    }
}
