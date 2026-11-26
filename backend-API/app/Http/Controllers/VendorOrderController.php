<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\VendorOrder;
use App\Http\Resources\VendorOrderResource;

class VendorOrderController extends Controller
{
    private const ALLOWED_TRANSITIONS = [
        'pending'    => ['processing', 'cancelled'],
        'processing' => ['shipped', 'cancelled'],
        'shipped'    => ['delivered'],
        'delivered'  => [],
        'cancelled'  => [],
    ];

    /**
     * Display a listing of paid orders belonging to the authenticated vendor.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        if (!$user->vendor) {
            return response()->json(['message' => 'User is not a registered vendor.'], 403);
        }

        $orders = VendorOrder::where('vendor_id', $user->vendor->id)
            ->whereHas('parentOrder', fn ($q) => $q->where('payment_status', '2'))
            ->with(['orderItems.product', 'parentOrder.user'])
            ->latest()
            ->paginate(15);

        return VendorOrderResource::collection($orders)->response();
    }

    /**
     * Display the specified paid vendor order.
     */
    public function show($id)
    {
        $user = Auth::user();

        if (!$user->vendor) {
            return response()->json(['message' => 'User is not a registered vendor.'], 403);
        }

        $order = VendorOrder::where('vendor_id', $user->vendor->id)
            ->whereHas('parentOrder', fn ($q) => $q->where('payment_status', '2'))
            ->with(['orderItems.product', 'parentOrder.user'])
            ->find($id);

        if (!$order) {
            return response()->json(['message' => 'Order not found or unauthorized.'], 404);
        }

        return response()->json(['data' => new VendorOrderResource($order)]);
    }

    /**
     * Update the shipping status of a paid vendor order with state-machine enforcement.
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'shipping_status' => 'required|string|in:pending,processing,shipped,delivered,cancelled',
        ]);

        $user = Auth::user();

        if (!$user->vendor) {
            return response()->json(['message' => 'User is not a registered vendor.'], 403);
        }

        return DB::transaction(function () use ($user, $id, $validated) {
            $order = VendorOrder::where('vendor_id', $user->vendor->id)
                ->with(['parentOrder', 'orderItems.product'])
                ->lockForUpdate()
                ->find($id);

            if (!$order) {
                return response()->json(['message' => 'Order not found or unauthorized.'], 404);
            }

            if (!$order->parentOrder || (string) $order->parentOrder->payment_status !== '2') {
                return response()->json([
                    'message' => 'Cannot update shipping status before payment is completed.'
                ], 422);
            }

            $currentStatus = $order->shipping_status;
            $targetStatus = $validated['shipping_status'];

            if ($currentStatus === $targetStatus) {
                return response()->json([
                    'message' => 'Order status unchanged.',
                    'data' => new VendorOrderResource($order),
                ]);
            }

            $allowedNext = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];
            if (!in_array($targetStatus, $allowedNext, true)) {
                return response()->json([
                    'message' => "Invalid status transition from '{$currentStatus}' to '{$targetStatus}'."
                ], 422);
            }

            $order->update([
                'shipping_status' => $targetStatus,
            ]);

            if ($targetStatus === 'cancelled') {
                foreach ($order->orderItems as $item) {
                    if ($item->product) {
                        $item->product->increment('stock_quantity', $item->quantity);
                    }
                }
            }

            return response()->json([
                'message' => 'Order status updated successfully.',
                'data' => new VendorOrderResource($order),
            ]);
        });
    }
}