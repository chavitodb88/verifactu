<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddQueueColumns extends Migration
{
    public function up()
    {
        // billing_hashes: planificación y control de procesamiento
        $this->forge->addColumn('billing_hashes', [
            'next_attempt_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'status'],
            'processing_at'   => ['type' => 'DATETIME', 'null' => true, 'after' => 'next_attempt_at'],
        ]);

        if (ENVIRONMENT !== 'testing') {
            $this->db->simpleQuery(
                'CREATE INDEX idx_bh_status_next_attempt ON billing_hashes (status, next_attempt_at)'
            );
            $this->db->simpleQuery(
                'CREATE INDEX idx_bh_processing_at ON billing_hashes (processing_at)'
            );
        }

        $this->forge->addColumn('submissions', [
            'attempt_number' => ['type' => 'INT', 'default' => 1, 'after' => 'status'],
        ]);
    }

    public function down()
    {

        if (ENVIRONMENT !== 'testing') {
            $this->forge->dropKey('billing_hashes', 'idx_bh_status_next_attempt');
            $this->forge->dropKey('billing_hashes', 'idx_bh_processing_at');
        }

        $this->forge->dropColumn('billing_hashes', 'next_attempt_at');
        $this->forge->dropColumn('billing_hashes', 'processing_at');


        $this->forge->dropColumn('submissions', 'attempt_number');
    }
}
