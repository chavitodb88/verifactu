<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCancellationModeToBillingHashes extends Migration
{
    public function up()
    {
        $this->forge->addColumn('billing_hashes', [
            'cancellation_mode' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'cancel_reason',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('billing_hashes', 'cancellation_mode');
    }
}
