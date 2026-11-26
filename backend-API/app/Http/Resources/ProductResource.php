<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor_id' => $this->vendor_id,
            'title' => $this->title,
            'price' => (float) $this->price,
            'stock_quantity' => (int) $this->stock_quantity,
            'category' => $this->category,
            'image_url' => $this->image_url,
            'gallery_urls' => $this->gallery_urls,
            'details' => $this->details,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
