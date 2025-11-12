<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class CreateSubmissions extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'            => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'billing_hash_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'type'          => ['type' => 'ENUM', 'constraint' => ['register', 'cancel', 'resend'], 'default' => 'register'],
            'status'        => ['type' => 'ENUM', 'constraint' => ['pending', 'sent', 'accepted', 'rejected', 'error'], 'default' => 'pending'],
            'request_ref'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'response_ref'  => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'error_code'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'error_message' => ['type' => 'TEXT', 'null' => true],
            'raw_req_path'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'raw_res_path'  => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('billing_hash_id');
        $this->forge->addForeignKey('billing_hash_id', 'billing_hashes', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('submissions');
    }

    public function down()
    {
        $this->forge->dropTable('submissions', true);
    }
}
