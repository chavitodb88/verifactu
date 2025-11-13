<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateBillingHashes extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'             => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'company_id'     => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'issuer_nif'     => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false],
            'series'         => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => false],
            'number'         => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'issue_date'     => ['type' => 'DATE', 'null' => false],
            'external_id'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'hash'           => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'prev_hash'      => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'chain_index'    => ['type' => 'INT', 'null' => true],
            'qr_url'         => ['type' => 'TEXT', 'null' => true],
            'csv_text'       => ['type' => 'LONGTEXT', 'null' => true],
            'xml_path'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'status'         => ['type' => 'ENUM', 'constraint' => ['draft', 'ready', 'sent', 'accepted', 'accepted_with_errors', 'rejected', 'error'], 'default' => 'draft'],
            'idempotency_key' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['issuer_nif', 'series', 'number']);
        $this->forge->addKey('idempotency_key');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('billing_hashes');
    }

    public function down()
    {
        $this->forge->dropTable('billing_hashes', true);
    }
}
