<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateAuthorizedIssuers extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'issuer_nif' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false],
            'active'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['company_id', 'issuer_nif']);
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('authorized_issuers');

        // Unique lógico
        $this->db->query('CREATE UNIQUE INDEX uniq_company_issuer ON authorized_issuers (company_id, issuer_nif)');
    }

    public function down()
    {
        $this->forge->dropTable('authorized_issuers', true);
    }
}
