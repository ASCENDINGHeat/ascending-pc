<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Widen users.address from VARCHAR(255) to TEXT to match controller validation (max:1000)
        Schema::table('users', function (Blueprint $table) {
            $table->text('address')->nullable()->change();
        });

        // 2. Widen vendors.store_name to 255 and enforce 1:1 unique constraint on vendors.user_id
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('store_name', 255)->change();
            $table->unique('user_id');
        });

        // 3. Deduplicate any existing (user_id, product_id) rows in cart before adding unique constraint
        $duplicates = DB::table('cart')
            ->select('user_id', 'product_id', DB::raw('MIN(id) as keep_id'), DB::raw('SUM(quantity) as total_qty'), DB::raw('COUNT(*) as cnt'))
            ->groupBy('user_id', 'product_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $dup) {
            DB::table('cart')
                ->where('id', $dup->keep_id)
                ->update(['quantity' => $dup->total_qty]);

            DB::table('cart')
                ->where('user_id', $dup->user_id)
                ->where('product_id', $dup->product_id)
                ->where('id', '!=', $dup->keep_id)
                ->delete();
        }

        Schema::table('cart', function (Blueprint $table) {
            $table->unique(['user_id', 'product_id']);
        });

        // 4. Add collision-free Midtrans order ID, admin fee, shipping snapshots, and redirect URL to parent_orders
        Schema::table('parent_orders', function (Blueprint $table) {
            $table->string('midtrans_order_id', 100)->nullable()->unique()->after('user_id');
            $table->decimal('admin_fee', 20, 2)->default(0)->after('total_amount');
            $table->text('shipping_address')->nullable()->after('snap_token');
            $table->string('recipient_phone', 30)->nullable()->after('shipping_address');
            $table->string('snap_redirect_url', 500)->nullable()->after('recipient_phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parent_orders', function (Blueprint $table) {
            $table->dropUnique(['midtrans_order_id']);
        });

        Schema::table('parent_orders', function (Blueprint $table) {
            $table->dropColumn([
                'midtrans_order_id',
                'admin_fee',
                'shipping_address',
                'recipient_phone',
                'snap_redirect_url',
            ]);
        });

        Schema::table('cart', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'product_id']);
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
            $table->string('store_name', 100)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('address')->nullable()->change();
        });
    }
};
