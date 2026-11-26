<?php

// app/Models/ParentOrder.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParentOrder extends Model
{
    protected $fillable = [
        'user_id',
        'midtrans_order_id',
        'total_amount',
        'admin_fee',
        'payment_status',
        'snap_token',
        'shipping_address',
        'recipient_phone',
        'snap_redirect_url',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'admin_fee' => 'decimal:2',
    ];

    protected $appends = ['redirect_url'];

    public function getRedirectUrlAttribute(): ?string
    {
        if (!empty($this->snap_redirect_url)) {
            return $this->snap_redirect_url;
        }
        if (!empty($this->snap_token)) {
            $base = config('midtrans.is_production')
                ? 'https://app.midtrans.com/snap/v2/vtweb/'
                : 'https://app.sandbox.midtrans.com/snap/v2/vtweb/';
            return $base . $this->snap_token;
        }
        return null;
    }

    /**
     * Get the user that placed the parent order.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the vendor orders associated with the parent order.
     */
    public function vendorOrders(): HasMany
    {
        return $this->hasMany(VendorOrder::class, 'parent_order_id');
    }
}
