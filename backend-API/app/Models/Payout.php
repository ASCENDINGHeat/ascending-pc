<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payout extends Model
{
    protected $table = 'payouts';

    protected $fillable = [
        'vendor_id',
        'amount',
        'status',
        'payout_date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payout_date' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
