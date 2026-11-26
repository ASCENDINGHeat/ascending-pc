<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $buyerName = $this->parentOrder?->user?->name ?? 'Customer';
        $shippingAddress = $this->parentOrder?->shipping_address ?? $this->parentOrder?->user?->address ?? null;
        $recipientPhone = $this->parentOrder?->recipient_phone ?? $this->parentOrder?->user?->phone_number ?? null;

        return [
            'id' => $this->id,
            'vendor_order_id' => $this->id,
            'parent_order_id' => $this->parent_order_id,
            'vendor_id' => $this->vendor_id,
            'total_price' => (float) $this->order_total,
            'order_total' => (float) $this->order_total,
            'commission_fee' => (float) $this->commission_fee,
            'net_amount' => (float) $this->net_amount,
            'status' => $this->shipping_status,
            'shipping_status' => $this->shipping_status,
            'payment_status' => $this->parentOrder?->payment_status,
            'user' => [
                'name' => $buyerName,
                'phone_number' => $recipientPhone,
                'address' => $shippingAddress,
            ],
            'parent_order' => [
                'id' => $this->parent_order_id,
                'payment_status' => $this->parentOrder?->payment_status,
                'shipping_address' => $shippingAddress,
                'recipient_phone' => $recipientPhone,
                'user' => [
                    'name' => $buyerName,
                ],
            ],
            'totals' => [
                'order_total' => number_format((float) $this->order_total, 2, '.', ''),
                'commission_fee' => number_format((float) $this->commission_fee, 2, '.', ''),
                'net_amount' => number_format((float) $this->net_amount, 2, '.', ''),
            ],
            'items' => OrderItemResource::collection($this->whenLoaded('orderItems')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}