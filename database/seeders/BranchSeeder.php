<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            ['code' => 'JG-MAIN', 'name' => 'JG Drugmart — Main', 'address' => 'Poblacion, Main Street', 'contact_number' => '+63 917 000 1111'],
            ['code' => 'JG-BR02', 'name' => 'JG Drugmart — Public Market', 'address' => 'Public Market Road', 'contact_number' => '+63 917 000 2222'],
        ])->each(fn (array $branch) => Branch::firstOrCreate(['code' => $branch['code']], $branch));
    }
}
