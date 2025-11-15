<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAeatFieldsToBillingHashes extends Migration
{
    public function up()
    {
        $fields = [
            'aeat_csv' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
                'after'      => 'hash', // ajústalo si el orden no encaja
            ],
            'aeat_send_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => true,
                'after'      => 'aeat_csv',
            ],
            'aeat_register_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => true,
                'after'      => 'aeat_send_status',
            ],
            'aeat_error_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'null'       => true,
                'after'      => 'aeat_register_status',
            ],
            'aeat_error_message' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'aeat_error_code',
            ],
        ];

        $this->forge->addColumn('billing_hashes', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('billing_hashes', 'aeat_csv');
        $this->forge->dropColumn('billing_hashes', 'aeat_send_status');
        $this->forge->dropColumn('billing_hashes', 'aeat_register_status');
        $this->forge->dropColumn('billing_hashes', 'aeat_error_code');
        $this->forge->dropColumn('billing_hashes', 'aeat_error_message');
    }
}
