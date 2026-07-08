<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['name' => 'Admin User', 'email' => 'admin@app.test', 'role' => UserRole::Admin, 'shift' => 'A'],
            ['name' => 'Supervisor User', 'email' => 'supervisor@app.test', 'role' => UserRole::Supervisor, 'shift' => 'A'],
            ['name' => 'Geologist User', 'email' => 'geologist@app.test', 'role' => UserRole::Geologist, 'shift' => 'B'],
            ['name' => 'Driller User', 'email' => 'driller@app.test', 'role' => UserRole::Driller, 'shift' => 'B'],
            ['name' => 'Helper User', 'email' => 'helper@app.test', 'role' => UserRole::Helper, 'shift' => 'C'],
            ['name' => 'Geotechnical User', 'email' => 'geotechnical@app.test', 'role' => UserRole::Geotechnical, 'shift' => 'A'],
        ];

        foreach ($users as $user) {
            User::updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'password' => Hash::make('password'),
                    'role' => $user['role'],
                    'shift' => $user['shift'],
                    'active' => true,
                ],
            );
        }
    }
}
