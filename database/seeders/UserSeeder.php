<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $main = Branch::where('code', 'JG-MAIN')->first();
        $second = Branch::where('code', 'JG-BR02')->first();

        $accounts = [
            [
                'name' => 'Admin',
                'email' => 'admin@jgdrugmart.test',
                'role' => UserRole::ADMIN,
                'branch_id' => $main?->id,
            ],
            [
                'name' => 'Maricel Santos',
                'email' => 'manager@jgdrugmart.test',
                'role' => UserRole::MANAGER,
                'branch_id' => $main?->id,
            ],
            [
                'name' => 'Jenny Reyes',
                'email' => 'sales@jgdrugmart.test',
                'role' => UserRole::CASHIER,
                'branch_id' => $main?->id,
            ],
            [
                'name' => 'Liza Cruz',
                'email' => 'cashier@jgdrugmart.test',
                'role' => UserRole::CASHIER,
                'branch_id' => $second?->id,
            ],
        ];

        foreach ($accounts as $account) {
            User::firstOrCreate(
                ['email' => $account['email']],
                array_merge($account, [
                    // Demo password for every seeded account.
                    'password' => 'password',
                    'is_active' => true,
                ])
            );
        }
    }
}
