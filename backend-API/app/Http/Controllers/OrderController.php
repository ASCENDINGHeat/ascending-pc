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
    public function checkout(Request $request)
    {
        $user = $request->user();
        $request->validate([
            'selected_cart_ids' => 'required|array|min:1',
            'selected_cart_ids.*' => 'integer|exists:cart,id',
        ]);

        if (empty(config('midtrans.server_key'))) {
            Log::critical('Checkout aborted: MIDTRANS_SERVER_KEY is not configured.');
            return response()->json([
                'message' => 'Payment gateway is not configured.'
            ], 500);
        }

        $parentOrder = null;
        $calculatedTotal = 0;

        try {
            // Phase 1: Atomic DB order creation & inventory reservation (no external HTTP inside lock)
            DB::beginTransaction();

            $cartItems = Cart::with('product.vendor')
                ->whereIn('id', $request->selected_cart_ids)
                ->where('user_id', $user->id)
                ->get();

            if ($cartItems->isEmpty()) {
                throw ValidationException::withMessages([
                    'cart' => ['Your cart is empty.']
                ]);
            }

            // Lock all involved products in deterministic ID order to prevent deadlocks & overselling
            $productIds = $cartItems->pluck('product_id')->unique()->sort()->values();
            $lockedProducts = Product::whereIn('id', $productIds)
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

            foreach ($cartItems as $item) {
                $lockedProduct = $lockedProducts->get($item->product_id);
                $calculatedTotal += $item->quantity * $lockedProduct->price;
            }

            $vendorGroups = $cartItems->groupBy(fn ($item) => $lockedProducts->get($item->product_id)->vendor_id);

            $parentOrder = ParentOrder::create([
                'user_id' => $user->id,
                'total_amount' => $calculatedTotal,
                'payment_status' => '1',
                'snap_token' => null,
            ]);

            foreach ($vendorGroups as $vendorId => $items) {
                $vendorOrderTotal = 0;

                foreach ($items as $item) {
                    $lockedProduct = $lockedProducts->get($item->product_id);
                    $vendorOrderTotal += $item->quantity * $lockedProduct->price;
                }

                $commissionRate = 0.10;
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
            $params = [
                'transaction_details' => [
                    'order_id' => $parentOrder->id,
                    'gross_amount' => (int) round((float) $calculatedTotal) + 4000,
                ],
                'customer_details' => [
                    'first_name' => $user->name,
                    'email' => $user->email,
                ],
            ];

            $snapToken = Snap::getSnapToken($params);

            DB::transaction(function () use ($parentOrder, $snapToken, $request, $user) {
                $parentOrder->update(['snap_token' => $snapToken]);

                Cart::whereIn('id', $request->selected_cart_ids)
                    ->where('user_id', $user->id)
                    ->delete();
            });

            $snapBaseUrl = config('midtrans.is_production')
                ? 'https://app.midtrans.com/snap/v2/vtweb/'
                : 'https://app.sandbox.midtrans.com/snap/v2/vtweb/';

            return response()->json([
                'status' => 'success',
                'message' => 'Order created, waiting for payment',
                'snap_token' => $snapToken,
                'order_id' => $parentOrder->id,
                'redirect_url' => $snapBaseUrl . $snapToken,
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
        // Retrieve the authenticated user's parent orders, ordered by newest first.
        // Eager load vendorOrders for a complete overview.
        $orders = $request->user()->parentOrders()->with('vendorOrders')->latest()->paginate(15);

        return response()->json($orders);
    }

    public function show($id)
    {
        $parentOrder = ParentOrder::find($id);
        if (!$parentOrder) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        // Policy/Authorization Check: Ensure the order belongs to the authenticated user
        if ($parentOrder->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        // Load all relationships: vendor orders, and the items within each vendor order.
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
            $order = ParentOrder::where('id', $orderId)->lockForUpdate()->first();
            if (!$order) {
                return response()->json(['message' => 'Order not found'], 404);
            }

            $expectedGross = (int) round((float) $order->total_amount) + 4000;
            $actualGross = (int) round((float) $grossAmount);
            if ($actualGross !== $expectedGross) {
                Log::warning('Midtrans callback rejected: gross_amount mismatch', [
                    'order_id' => $orderId,
                    'expected' => $expectedGross,
                    'actual' => $actualGross,
                ]);
                return response()->json(['message' => 'Gross amount mismatch'], 400);
            }

            // Terminal states ('2' Completed, '3' Failed/Cancelled, '4' Refunded) are idempotent:
            // ignore duplicate or out-of-order notifications to prevent stock inflation & status regression.
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
