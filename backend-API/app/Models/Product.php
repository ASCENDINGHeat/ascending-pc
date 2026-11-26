<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    // Specify the table name if it doesn't follow convention (plural 'products' is standard, but good to be explicit)
    protected $table = 'products';

    // Specify the primary key since it is 'product_id', not 'id'
    protected $primaryKey = 'id';

    // Disable timestamps since the table definition in pbpsql.sql doesn't include them
    public $timestamps = true;

    protected $fillable = [
        'vendor_id',
        'title',
        'price',
        'stock_quantity',
        'details',
    ];

    // Cast JSONB column 'details' to array and price to decimal for API responses
    protected $casts = [
        'details' => 'array',
        'price' => 'decimal:2',
        'stock_quantity' => 'integer',
    ];

    protected $appends = ['image_url', 'category']; // Append these to the JSON output automatically

    // Accessor for Main Image URL
    public function getImageUrlAttribute()
    {
        if (is_array($this->details) && !empty($this->details['images']['main'])) {
            $main = $this->details['images']['main'];
            return str_starts_with($main, 'http') ? $main : asset('storage/' . $main);
        }
        return null;
    }

    // Accessor for Gallery URLs
    public function getGalleryUrlsAttribute()
    {
        $urls = [];
        $paths = $this->details['images']['gallery'] ?? $this->details['gallery_paths'] ?? [];
        if (is_array($paths)) {
            foreach ($paths as $path) {
                if (!empty($path)) {
                    $urls[] = str_starts_with($path, 'http') ? $path : asset('storage/' . $path);
                }
            }
        }
        return $urls;
    }

    public function getCategoryAttribute()
    {
        return $this->details['category'] ?? null;
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function Vendor()
    {
        return $this->vendor();
    }
}
