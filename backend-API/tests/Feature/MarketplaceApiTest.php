<?php

use App\Models\OrderItem;
use App\Models\ParentOrder;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorOrder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Storage;

test('healthcheck endpoint returns 200', function () {
    $this->get('/up')->assertOk();
});

test('user can register, partially update profile without wiping fields, and revoke token on logout', function () {
    $registerResponse = $this->postJson('/api/register', [
        'name' => 'Budi Santoso',
        'email' => 'budi@ascending.test',
        'password' => 'Secret1234!',
        'password_confirmation' => 'Secret1234!',
        'phone_number' => '081234567890',
        'address' => 'Jl. Sudirman No. 10, Jakarta Pusat',
    ])->assertCreated();

    $token = $registerResponse->json('access_token');
    expect($token)->toBeString()->not->toBeEmpty();

    // Partial update omits phone_number and address; existing values must remain intact
    $this->withToken($token)
        ->putJson('/api/user', [
            'name' => 'Budi Santoso Updated',
        ])
        ->assertOk()
        ->assertJsonPath('user.name', 'Budi Santoso Updated')
        ->assertJsonPath('user.phone_number', '081234567890')
        ->assertJsonPath('user.address', 'Jl. Sudirman No. 10, Jakarta Pusat');

    $this->withToken($token)
        ->postJson('/api/logout')
        ->assertOk();

    expect(User::where('email', 'budi@ascending.test')->first()->tokens()->count())->toBe(0);
});

test('vendor onboarding, product CRUD, and gallery deletion path-traversal guard work securely', function () {
    Storage::fake('public');

    $buyer = User::factory()->create();
    $seller = User::factory()->create();

    // Non-vendor cannot create products
    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/product', [
            'title' => 'Unauthorized GPU',
            'price' => 5000000,
            'stock_quantity' => 3,
        ])
        ->assertForbidden();

    // Onboard seller as vendor
    $this->actingAs($seller, 'sanctum')
        ->postJson('/api/vendor/register', [
            'store_name' => 'Ascending Official Rig Store',
            'store_description' => 'Toko komponen PC resmi',
            'phone_number' => '081199887766',
            'address' => 'Mangga Dua Mall Lt. 4, Jakarta',
        ])
        ->assertCreated()
        ->assertJsonPath('vendor.store_name', 'Ascending Official Rig Store');

    // Duplicate vendor registration is rejected
    $this->actingAs($seller, 'sanctum')
        ->postJson('/api/vendor/register', [
            'store_name' => 'Second Store',
            'phone_number' => '081199887766',
            'address' => 'Jakarta',
        ])
        ->assertStatus(400);

    $seller->refresh();

    // Seed two files on public disk: one owned gallery image, one unrelated file belonging to another vendor
    Storage::disk('public')->put('products/gallery/owned-photo.jpg', 'owned-image-bytes');
    Storage::disk('public')->put('products/main/other-vendor-secret.jpg', 'victim-image-bytes');

    $product = Product::create([
        'vendor_id' => $seller->vendor->id,
        'title' => 'AMD Ryzen 7 7800X3D',
        'price' => 6850000,
        'stock_quantity' => 8,
        'details' => [
            'category' => 'processor',
            'socket' => 'AM5',
            'images' => [
                'main' => null,
                'gallery' => ['products/gallery/owned-photo.jpg'],
            ],
        ],
    ]);

    // Attempt to delete both owned gallery image and an unowned path via remove_gallery_images
    $this->actingAs($seller, 'sanctum')
        ->putJson("/api/product/{$product->id}", [
            'price' => 6750000,
            'remove_gallery_images' => [
                'products/gallery/owned-photo.jpg',
                'products/main/other-vendor-secret.jpg',
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.price', '6750000.00');

    Storage::disk('public')->assertMissing('products/gallery/owned-photo.jpg');
    Storage::disk('public')->assertExists('products/main/other-vendor-secret.jpg');

    // Vendor can delete product when it has no order history
    $this->actingAs($seller, 'sanctum')
        ->deleteJson("/api/product/{$product->id}")
        ->assertOk();

    expect(Product::find($product->id))->toBeNull();
});

test('cart enforces positive quantities, stock bounds, and zero-quantity removal', function () {
    $sellerUser = User::factory()->create();
    $vendor = Vendor::create([
        'user_id' => $sellerUser->id,
        'store_name' => 'Tech Hub Store',
        'store_description' => 'PC components',
        'commission_rate' => 5.00,
        'balance' => 0,
    ]);

    $product = Product::create([
        'vendor_id' => $vendor->id,
        'title' => 'Corsair Vengeance 32GB DDR5',
        'price' => 1950000,
        'stock_quantity' => 3,
        'details' => ['category' => 'ram', 'memory_type' => 'DDR5'],
    ]);

    $buyer = User::factory()->create();

    // Zero or negative quantity is rejected on addToCart
    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/cart/{$product->id}", ['quantity' => 0])
        ->assertStatus(422);

    // Adding 2 units succeeds and returns cart primary key
    $addRes = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/cart/{$product->id}", ['quantity' => 2])
        ->assertOk();

    $cartId = $addRes->json('data.id');
    expect($cartId)->toBeInt();

    // Adding 2 more (total 4 > stock 3) is rejected
    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/cart/{$product->id}", ['quantity' => 2])
        ->assertStatus(400);

    // Updating quantity to 0 deletes the cart item
    $this->actingAs($buyer, 'sanctum')
        ->putJson("/api/cart/update/{$cartId}", ['quantity' => 0])
        ->assertOk()
        ->assertJsonPath('status', 'deleted');
});

test('midtrans webhook verifies signature, updates order state, enforces vendor fulfillment transitions, and restores stock idempotently on expiry', function () {
    config(['midtrans.server_key' => 'SB-Mid-server-ci-secret-key']);

    $buyer = User::factory()->create([
        'address' => 'Jl. Gatot Subroto No. 12, Jakarta',
        'phone_number' => '081200001111',
    ]);
    $sellerUser = User::factory()->create();
    $vendor = Vendor::create([
        'user_id' => $sellerUser->id,
        'store_name' => 'GPU Master Store',
        'store_description' => 'Authorized GPU partner',
        'commission_rate' => 5.00,
        'balance' => 0,
    ]);

    $product = Product::create([
        'vendor_id' => $vendor->id,
        'title' => 'NVIDIA RTX 4070 Super',
        'price' => 10000000,
        'stock_quantity' => 5,
        'details' => ['category' => 'vga'],
    ]);

    // Simulate post-checkout reserved order (2 units reserved -> 3 remaining)
    $product->decrement('stock_quantity', 2);

    $parentOrder = ParentOrder::create([
        'user_id' => $buyer->id,
        'midtrans_order_id' => 'ASC-501-20261126010101',
        'total_amount' => 20004000,
        'admin_fee' => 4000,
        'payment_status' => '1',
        'shipping_address' => $buyer->address,
        'recipient_phone' => $buyer->phone_number,
    ]);

    $vendorOrder = VendorOrder::create([
        'parent_order_id' => $parentOrder->id,
        'vendor_id' => $vendor->id,
        'order_total' => 20000000,
        'commission_fee' => 1000000,
        'net_amount' => 19000000,
        'shipping_status' => 'pending',
    ]);

    OrderItem::create([
        'vendor_order_id' => $vendorOrder->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 10000000,
    ]);

    // Unpaid order is hidden from vendor orders list and cannot be shipped
    $this->actingAs($sellerUser, 'sanctum')
        ->getJson('/api/vendor/orders')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($sellerUser, 'sanctum')
        ->patchJson("/api/vendor/orders/{$vendorOrder->id}/status", [
            'shipping_status' => 'processing',
        ])
        ->assertStatus(422);

    // Invalid webhook signature is rejected with 403
    $this->postJson('/api/midtrans-callback', [
        'order_id' => $parentOrder->midtrans_order_id,
        'status_code' => '200',
        'gross_amount' => '20004000.00',
        'transaction_status' => 'settlement',
        'signature_key' => 'forged-signature',
    ])->assertForbidden();

    // Tampered gross_amount with valid signature for tampered value is rejected with 400
    $tamperedGross = '1000.00';
    $tamperedSig = hash('sha512', $parentOrder->midtrans_order_id . '200' . $tamperedGross . 'SB-Mid-server-ci-secret-key');
    $this->postJson('/api/midtrans-callback', [
        'order_id' => $parentOrder->midtrans_order_id,
        'status_code' => '200',
        'gross_amount' => $tamperedGross,
        'transaction_status' => 'settlement',
        'signature_key' => $tamperedSig,
    ])->assertStatus(400);

    // Valid settlement callback transitions order to paid ('2')
    $validGross = '20004000.00';
    $validSig = hash('sha512', $parentOrder->midtrans_order_id . '200' . $validGross . 'SB-Mid-server-ci-secret-key');
    $this->postJson('/api/midtrans-callback', [
        'order_id' => $parentOrder->midtrans_order_id,
        'status_code' => '200',
        'gross_amount' => $validGross,
        'transaction_status' => 'settlement',
        'signature_key' => $validSig,
    ])->assertOk();

    expect($parentOrder->fresh()->payment_status)->toBe('2');

    // Paid order now appears in vendor dashboard and follows forward-only shipping state machine
    $this->actingAs($sellerUser, 'sanctum')
        ->getJson('/api/vendor/orders')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($sellerUser, 'sanctum')
        ->patchJson("/api/vendor/orders/{$vendorOrder->id}/status", ['shipping_status' => 'processing'])
        ->assertOk();

    // Backward transition processing -> pending is rejected
    $this->actingAs($sellerUser, 'sanctum')
        ->patchJson("/api/vendor/orders/{$vendorOrder->id}/status", ['shipping_status' => 'pending'])
        ->assertStatus(422);

    // Create a second pending order to test expire stock restoration + replay idempotency
    $product->decrement('stock_quantity', 1); // 3 -> 2
    $expiredOrder = ParentOrder::create([
        'user_id' => $buyer->id,
        'midtrans_order_id' => 'ASC-502-20261126010505',
        'total_amount' => 10004000,
        'admin_fee' => 4000,
        'payment_status' => '1',
        'shipping_address' => $buyer->address,
    ]);
    $expiredVendorOrder = VendorOrder::create([
        'parent_order_id' => $expiredOrder->id,
        'vendor_id' => $vendor->id,
        'order_total' => 10000000,
        'commission_fee' => 500000,
        'net_amount' => 9500000,
        'shipping_status' => 'pending',
    ]);
    OrderItem::create([
        'vendor_order_id' => $expiredVendorOrder->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 10000000,
    ]);

    $expireGross = '10004000.00';
    $expireSig = hash('sha512', $expiredOrder->midtrans_order_id . '202' . $expireGross . 'SB-Mid-server-ci-secret-key');

    $this->postJson('/api/midtrans-callback', [
        'order_id' => $expiredOrder->midtrans_order_id,
        'status_code' => '202',
        'gross_amount' => $expireGross,
        'transaction_status' => 'expire',
        'signature_key' => $expireSig,
    ])->assertOk();

    expect($product->fresh()->stock_quantity)->toBe(3);
    expect($expiredVendorOrder->fresh()->shipping_status)->toBe('cancelled');

    // Replaying the exact same expire webhook must NOT increment stock a second time
    $this->postJson('/api/midtrans-callback', [
        'order_id' => $expiredOrder->midtrans_order_id,
        'status_code' => '202',
        'gross_amount' => $expireGross,
        'transaction_status' => 'expire',
        'signature_key' => $expireSig,
    ])->assertOk()->assertJsonPath('message', 'Callback already processed');

    expect($product->fresh()->stock_quantity)->toBe(3);
});

test('database seeders run cleanly and populate marketplace catalog', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Vendor::count())->toBeGreaterThan(0);
    expect(Product::count())->toBeGreaterThanOrEqual(10);
});
