<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateCompanies extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'                => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'slug'              => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'name'              => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => false],
            'verifactu_enabled' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'send_to_aeat'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'nif_colaborador'   => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'storage_driver'    => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'fs'],
            'storage_base_path' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('companies');
    }

    public function down()
    {
        $this->forge->dropTable('companies', true);
    }
}
