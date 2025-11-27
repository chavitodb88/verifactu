<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

final class TestSeeder extends Seeder
{
    public function run()
    {
        // IMPORTANTE: orden correcto para respetar foreign keys
        $this->call(CompaniesSeeder::class);
        $this->call(ApiKeysSeeder::class);

        // Aquí puedes añadir otros seeders de pruebas si los necesitas:
        // $this->call(AnotherSeeder::class);
    }
}
