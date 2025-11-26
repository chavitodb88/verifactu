<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddIssuerExtendedFieldsToBillingHashes extends Migration
{
    public function up()
    {
        $fields = [
            'issuer_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'issuer_name',
            ],
            'issuer_postal_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => true,
                'after'      => 'issuer_address',
            ],
            'issuer_city' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
                'after'      => 'issuer_postal_code',
            ],
            'issuer_province' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
                'after'      => 'issuer_city',
            ],
            'issuer_country_code' => [
                'type'       => 'CHAR',
                'constraint' => 2,
                'null'       => true,
                'after'      => 'issuer_province',
            ],
        ];

        $this->forge->addColumn('billing_hashes', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('billing_hashes', [
            'issuer_address',
            'issuer_postal_code',
            'issuer_city',
            'issuer_province',
            'issuer_country_code',
        ]);
    }
}
