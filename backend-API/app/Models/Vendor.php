<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Vendor extends Model
{
    use HasFactory;

    protected $primaryKey = 'id';

    protected $fillable = [
        'user_id',
        'store_name',
        'store_description',
        'store_logo',
        'commission_rate',
        'balance'
    ];

    protected $casts = [
        'commission_rate' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    public function getStoreLogoUrlAttribute()
    {
        if ($this->store_logo) {
            return str_starts_with($this->store_logo, 'http')
                ? $this->store_logo
                : asset('storage/' . $this->store_logo);
        }
        return null; // or return a default placeholder URL
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'vendor_id', 'id');
    }

    public function vendorOrders()
    {
        return $this->hasMany(VendorOrder::class, 'vendor_id', 'id');
    }

    public function payouts()
    {
        return $this->hasMany(Payout::class, 'vendor_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
