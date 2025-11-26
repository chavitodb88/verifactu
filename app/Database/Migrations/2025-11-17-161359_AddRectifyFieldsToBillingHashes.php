<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddRectifyFieldsToBillingHashes extends Migration
{
    public function up()
    {
        $this->forge->addColumn('billing_hashes', [
            'invoice_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 4,
                'null'       => true,
                'after'      => 'issue_date',
            ],
            'rectified_billing_hash_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'after'    => 'invoice_type',
            ],
            'rectified_meta_json' => [
                'type'  => 'TEXT',
                'null'  => true,
                'after' => 'rectified_billing_hash_id',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('billing_hashes', 'invoice_type');
        $this->forge->dropColumn('billing_hashes', 'rectified_billing_hash_id');
        $this->forge->dropColumn('billing_hashes', 'rectified_meta_json');
    }
}
