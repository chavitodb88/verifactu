<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddKindToBillingHashes extends Migration
{
    public function up()
    {
        $this->forge->addColumn('billing_hashes', [
            'kind' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true, 'after' => 'external_id'],
        ]);
        $this->db->query('CREATE INDEX idx_bh_kind ON billing_hashes (kind)');
    }

    public function down()
    {
        $this->forge->dropKey('billing_hashes', 'idx_bh_kind');
        $this->forge->dropColumn('billing_hashes', 'kind');
    }
}
