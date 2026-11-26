<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Faker\Factory as Faker;

class VendorSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('id_ID');
        $userIds = DB::table('users')->pluck('id')->toArray();

        foreach ($userIds as $index => $userId) {
            // Always seed at least the first user as a vendor so ProductSeeder has an active store
            if ($index === 0 || rand(0, 1) === 1) {
                DB::table('vendors')->updateOrInsert(
                    ['user_id' => $userId],
                    [
                        'store_name' => $faker->unique()->company . ' Official Store',
                        'store_description' => 'Pusat komponen PC dan gaming gear bergaransi resmi.',
                        'commission_rate' => 5.00,
                        'balance' => 0.00,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
}
