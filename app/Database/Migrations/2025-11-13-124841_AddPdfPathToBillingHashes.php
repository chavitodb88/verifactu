<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPdfPathToBillingHashes extends Migration
{
    public function up()
    {
        $fields = [
            'pdf_path' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'xml_path',
            ],
        ];

        $this->forge->addColumn('billing_hashes', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('billing_hashes', 'pdf_path');
    }
}
