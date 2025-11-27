<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateApiKeys extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'company_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'api_key'    => ['type' => 'CHAR', 'constraint' => 64, 'null' => false],
            'scopes'     => ['type' => 'TEXT', 'null' => true], // JSON en string
            'active'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('api_key');
        $this->forge->addForeignKey('company_id', 'companies', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('api_keys');


        if (ENVIRONMENT !== 'testing') {
            $this->db->query('CREATE UNIQUE INDEX uniq_api_key ON api_keys (api_key)');
        }
    }

    public function down()
    {
        $this->forge->dropTable('api_keys', true);
    }
}
