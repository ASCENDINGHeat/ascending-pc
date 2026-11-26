<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CartSeeder extends Seeder
{
    public function run(): void
    {
        $userIds = DB::table('users')->pluck('id')->toArray();
        $productIds = DB::table('products')->where('stock_quantity', '>', 0)->pluck('id')->toArray();

        if (empty($userIds) || empty($productIds)) {
            return;
        }

        foreach ($userIds as $index => $userId) {
            $productId = $productIds[$index % count($productIds)];
            DB::table('cart')->updateOrInsert(
                [
                    'user_id' => $userId,
                    'product_id' => $productId,
                ],
                [
                    'quantity' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
