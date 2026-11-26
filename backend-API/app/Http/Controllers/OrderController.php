<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\ParentOrder;
use App\Models\OrderItem;
use App\Models\VendorOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Models\Cart;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Product;
use Midtrans\Snap;
use Midtrans\Config;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    private const ADMIN_FEE = 4000;

    public function checkout(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'selected_cart_ids' => 'required|array|min:1',
            'selected_cart_ids.*' => 'integer|exists:cart,id',
            'shipping_address' => 'nullable|string|max:1000',
            'recipient_phone' => 'nullable|string|max:30',
        ]);

        if (empty(config('midtrans.server_key'))) {
            Log::critical('Checkout aborted: MIDTRANS_SERVER_KEY is not configured.');
            return response()->json([
                'message' => 'Payment gateway is not configured.'
            ], 500);
        }

        $shippingAddress = trim((string) ($validated['shipping_address'] ?? $user->address ?? ''));
        $recipientPhone = trim((string) ($validated['recipient_phone'] ?? $user->phone_number ?? ''));

        if ($shippingAddress === '') {
            return response()->json([
                'message' => 'Alamat pengiriman wajib diisi sebelum melakukan checkout.',
                'errors' => ['shipping_address' => ['Alamat pengiriman wajib diisi.']]
            ], 422);
        }

        $parentOrder = null;
        $grandTotal = 0;
        $midtransOrderId = null;

        try {
            // Phase 1: Atomic DB order creation & inventory reservation (no external HTTP inside lock)
            DB::beginTransaction();

            // Persist shipping details back to user profile if not yet configured
            $profileUpdates = [];
            if (empty($user->address) && $shippingAddress !== '') {
                $profileUpdates['address'] = $shippingAddress;
            }
            if (empty($user->phone_number) && $recipientPhone !== '') {
                $profileUpdates['phone_number'] = $recipientPhone;
            }
            if (!empty($profileUpdates)) {
                $user->update($profileUpdates);
            }

            $cartItems = Cart::with('product.vendor')
                ->whereIn('id', $validated['selected_cart_ids'])
                ->where('user_id', $user->id)
                ->get();

            if ($cartItems->isEmpty()) {
                throw ValidationException::withMessages([
                    'cart' => ['Your cart is empty.']
                ]);
            }

            // Lock all involved products in deterministic ID order to prevent deadlocks & overselling
            $productIds = $cartItems->pluck('product_id')->unique()->sort()->values();
            $lockedProducts = Product::with('vendor')
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Aggregate quantities per product in case multiple cart rows reference the same product
            $requestedByProduct = $cartItems->groupBy('product_id')->map(fn ($rows) => $rows->sum('quantity'));
            foreach ($requestedByProduct as $productId => $totalRequestedQty) {
                $lockedProduct = $lockedProducts->get($productId);
                if (!$lockedProduct) {
                    throw ValidationException::withMessages([
                        'cart' => ["Product #{$productId} is no longer available."]
                    ]);
                }
                if ($totalRequestedQty <= 0 || $lockedProduct->stock_quantity < $totalRequestedQty) {
                    throw ValidationException::withMessages([
                        'stock' => ["Stok tidak mencukupi untuk '{$lockedProduct->title}'. Tersedia: {$lockedProduct->stock_quantity}, diminta: {$totalRequestedQty}."]
                    ]);
                }
            }

            $itemsSubtotal = 0;
            foreach ($cartItems as $item) {
                $lockedProduct = $lockedProducts->get($item->product_id);
                $itemsSubtotal += $item->quantity * $lockedProduct->price;
            }

            $grandTotal = round($itemsSubtotal + self::ADMIN_FEE, 2);
            $vendorGroups = $cartItems->groupBy(fn ($item) => $lockedProducts->get($item->product_id)->vendor_id);

            $parentOrder = ParentOrder::create([
                'user_id' => $user->id,
                'total_amount' => $grandTotal,
                'admin_fee' => self::ADMIN_FEE,
                'payment_status' => '1',
                'snap_token' => null,
                'shipping_address' => $shippingAddress,
                'recipient_phone' => $recipientPhone !== '' ? $recipientPhone : null,
            ]);

            $midtransOrderId = 'ASC-' . $parentOrder->id . '-' . now()->format('YmdHis');
            $parentOrder->update(['midtrans_order_id' => $midtransOrderId]);

            foreach ($vendorGroups as $vendorId => $items) {
                $vendorOrderTotal = 0;
                $vendorModel = null;

                foreach ($items as $item) {
                    $lockedProduct = $lockedProducts->get($item->product_id);
                    $vendorOrderTotal += $item->quantity * $lockedProduct->price;
                    $vendorModel = $vendorModel ?? $lockedProduct->vendor;
                }

                // Apply vendor's configured commission_rate (default 5.00%) instead of hardcoded 10%
                $commissionPercent = (float) ($vendorModel?->commission_rate ?? 5.00);
                $commissionRate = max(0, min($commissionPercent, 100)) / 100;
                $commissionFee = round($vendorOrderTotal * $commissionRate, 2);
                $netAmount = round($vendorOrderTotal - $commissionFee, 2);

                $vendorOrder = VendorOrder::create([
                    'parent_order_id' => $parentOrder->id,
                    'vendor_id' => $vendorId,
                    'order_total' => $vendorOrderTotal,
                    'commission_fee' => $commissionFee,
                    'net_amount' => $netAmount,
                    'shipping_status' => 'pending',
                ]);

                foreach ($items as $item) {
                    $lockedProduct = $lockedProducts->get($item->product_id);
                    OrderItem::create([
                        'vendor_order_id' => $vendorOrder->id,
                        'product_id' => $item->product_id,
                        'quantity' => $item->quantity,
                        'unit_price' => $lockedProduct->price,
                    ]);
                    $lockedProduct->decrement('stock_quantity', $item->quantity);
                }
            }

            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['message' => 'Checkout failed.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Checkout DB Phase Failed: ' . $e->getMessage(), ['exception' => $e]);

            $payload = ['message' => 'An unexpected error occurred during checkout.'];
            if (config('app.debug')) {
                $payload['error'] = $e->getMessage();
            }
            return response()->json($payload, 500);
        }

        // Phase 2: Request Midtrans Snap Token outside DB row locks
        try {
            $customerDetails = [
                'first_name' => $user->name,
                'email' => $user->email,
            ];
            if ($recipientPhone !== '') {
                $customerDetails['phone'] = $recipientPhone;
            }

            $params = [
                'transaction_details' => [
                    'order_id' => $midtransOrderId,
                    'gross_amount' => (int) round((float) $grandTotal),
                ],
                'customer_details' => $customerDetails,
            ];

            $snapToken = Snap::getSnapToken($params);
            $snapBaseUrl = config('midtrans.is_production')
                ? 'https://app.midtrans.com/snap/v2/vtweb/'
                : 'https://app.sandbox.midtrans.com/snap/v2/vtweb/';
            $redirectUrl = $snapBaseUrl . $snapToken;

            DB::transaction(function () use ($parentOrder, $snapToken, $redirectUrl, $validated, $user) {
                $parentOrder->update([
                    'snap_token' => $snapToken,
                    'snap_redirect_url' => $redirectUrl,
                ]);

                Cart::whereIn('id', $validated['selected_cart_ids'])
                    ->where('user_id', $user->id)
                    ->delete();
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Order created, waiting for payment',
                'snap_token' => $snapToken,
                'order_id' => $parentOrder->id,
                'midtrans_order_id' => $midtransOrderId,
                'redirect_url' => $redirectUrl,
            ], 201);
        } catch (\Throwable $e) {
            // Compensate: restore reserved inventory and remove unpayable order while keeping user's cart intact
            try {
                DB::transaction(function () use ($parentOrder) {
                    $parentOrder->load('vendorOrders.orderItems.product');
                    foreach ($parentOrder->vendorOrders as $vendorOrder) {
                        foreach ($vendorOrder->orderItems as $orderItem) {
                            if ($orderItem->product) {
                                $orderItem->product->increment('stock_quantity', $orderItem->quantity);
                            }
                        }
                        $vendorOrder->orderItems()->delete();
                    }
                    $parentOrder->vendorOrders()->delete();
                    $parentOrder->delete();
                });
            } catch (\Throwable $rollbackError) {
                Log::critical('Checkout compensation failed for ParentOrder #' . $parentOrder->id . ': ' . $rollbackError->getMessage());
            }

            Log::error('Midtrans Snap Token Generation Failed: ' . $e->getMessage(), ['order_id' => $parentOrder->id]);

            $payload = ['message' => 'Failed to initialize payment gateway. Please try again.'];
            if (config('app.debug')) {
                $payload['error'] = $e->getMessage();
            }
            return response()->json($payload, 502);
        }
    }

    public function index(Request $request)
    {
        $orders = $request->user()->parentOrders()->with('vendorOrders')->latest()->paginate(15);

        return response()->json($orders);
    }

    public function show($id)
    {
        $parentOrder = ParentOrder::find($id);
        if (!$parentOrder) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ($parentOrder->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $parentOrder->load('vendorOrders.orderItems.product');

        return response()->json($parentOrder);
    }

    public function receive(Request $request)
    {
        $serverKey = config('midtrans.server_key');
        if (empty($serverKey) || !is_string($serverKey)) {
            Log::critical('Midtrans callback rejected: MIDTRANS_SERVER_KEY is not configured.');
            return response()->json(['message' => 'Payment gateway misconfigured'], 500);
        }

        $orderId = (string) $request->input('order_id', '');
        $statusCode = (string) $request->input('status_code', '');
        $grossAmount = (string) $request->input('gross_amount', '');
        $incomingSignature = (string) $request->input('signature_key', '');

        if ($orderId === '' || $statusCode === '' || $grossAmount === '' || $incomingSignature === '') {
            return response()->json(['message' => 'Invalid callback payload'], 400);
        }

        $mySignature = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

        if (!hash_equals($mySignature, $incomingSignature)) {
            Log::warning('Midtrans callback rejected: invalid signature', ['order_id' => $orderId]);
            return response()->json(['message' => 'Invalid Signature'], 403);
        }

        $transactionStatus = (string) $request->input('transaction_status', '');
        $fraudStatus = (string) $request->input('fraud_status', '');

        return DB::transaction(function () use ($orderId, $grossAmount, $transactionStatus, $fraudStatus) {
            $order = ParentOrder::where('midtrans_order_id', $orderId)
                ->when(ctype_digit($orderId), fn ($q) => $q->orWhere('id', (int) $orderId))
                ->lockForUpdate()
                ->first();

            if (!$order) {
                return response()->json(['message' => 'Order not found'], 404);
            }

            // Orders created after P1 store admin_fee inside total_amount; legacy orders had admin_fee == 0
            $recordedAdminFee = (float) ($order->admin_fee ?? 0);
            $expectedGross = $recordedAdminFee > 0
                ? (int) round((float) $order->total_amount)
                : (int) round((float) $order->total_amount) + self::ADMIN_FEE;
            $actualGross = (int) round((float) $grossAmount);

            if ($actualGross !== $expectedGross) {
                Log::warning('Midtrans callback rejected: gross_amount mismatch', [
                    'order_id' => $orderId,
                    'expected' => $expectedGross,
                    'actual' => $actualGross,
                ]);
                return response()->json(['message' => 'Gross amount mismatch'], 400);
            }

            // Terminal states ('2' Completed, '3' Failed/Cancelled, '4' Refunded) are idempotent
            if (in_array((string) $order->payment_status, ['2', '3', '4'], true)) {
                return response()->json(['message' => 'Callback already processed']);
            }

            if ($transactionStatus === 'settlement') {
                $order->update(['payment_status' => '2']);
            } elseif ($transactionStatus === 'capture') {
                if ($fraudStatus === '' || $fraudStatus === 'accept') {
                    $order->update(['payment_status' => '2']);
                } elseif ($fraudStatus === 'challenge') {
                    $order->update(['payment_status' => '1']);
                }
            } elseif (in_array($transactionStatus, ['expire', 'cancel', 'deny'], true)) {
                $order->load('vendorOrders.orderItems.product');
                $order->update(['payment_status' => '3']);

                foreach ($order->vendorOrders as $vendorOrder) {
                    if ($vendorOrder->shipping_status === 'pending') {
                        $vendorOrder->update(['shipping_status' => 'cancelled']);
                    }
                    foreach ($vendorOrder->orderItems as $item) {
                        if ($item->product) {
                            $item->product->increment('stock_quantity', $item->quantity);
                        }
                    }
                }
            } elseif ($transactionStatus === 'pending') {
                $order->update(['payment_status' => '1']);
            }

            return response()->json(['message' => 'Callback received successfully']);
        });
    }
}
