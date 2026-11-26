<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => bcrypt('password'),
                'phone_number' => '081234567890',
                'address' => 'Jl. Sudirman No. 1, Jakarta Pusat',
            ]
        );

        if (User::count() < 4) {
            User::factory(3)->create();
        }

        $this->call([
            VendorSeeder::class,
            ProductSeeder::class,
            CartSeeder::class,
        ]);
    }
}
