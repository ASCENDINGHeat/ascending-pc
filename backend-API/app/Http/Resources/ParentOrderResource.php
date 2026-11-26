<?php

// app/Http/Resources/ParentOrderResource.php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParentOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'parent_order_id' => $this->id,
            'midtrans_order_id' => $this->midtrans_order_id,
            'user_id' => $this->user_id,
            'total_amount' => (float) $this->total_amount,
            'admin_fee' => (float) ($this->admin_fee ?? 0),
            'payment_status' => $this->payment_status,
            'redirect_url' => $this->redirect_url,
            'created_at' => $this->created_at?->toISOString(),

            'vendor_shipments' => VendorOrderResource::collection($this->whenLoaded('vendorOrders')),
            'shipping_info' => [
                'name' => $this->user?->name,
                'email' => $this->user?->email,
                'address' => $this->shipping_address ?? $this->user?->address,
                'phone_number' => $this->recipient_phone ?? $this->user?->phone_number,
            ],
        ];
    }
}
