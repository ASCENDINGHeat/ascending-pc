<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $vendorIds = DB::table('vendors')->pluck('id')->toArray();
        if (empty($vendorIds)) {
            return;
        }

        $catalogFixtures = [
            [
                'title' => 'AMD Ryzen 7 7800X3D Gaming Processor',
                'price' => 6850000.00,
                'stock_quantity' => 15,
                'details' => [
                    'category' => 'processor',
                    'brand' => 'AMD',
                    'socket' => 'AM5',
                    'specs' => 'AM5, 8-Core 16-Thread, 3D V-Cache',
                    'description' => 'Prosesor gaming 8-Core 16-Thread berbasis arsitektur Zen 4 dengan teknologi 3D V-Cache.',
                ],
            ],
            [
                'title' => 'Intel Core i5-14600K Desktop Processor',
                'price' => 5190000.00,
                'stock_quantity' => 12,
                'details' => [
                    'category' => 'processor',
                    'brand' => 'Intel',
                    'socket' => 'LGA1700',
                    'specs' => 'LGA1700, 14-Core (6P+8E)',
                    'description' => 'Prosesor Intel generasi ke-14 unlocked untuk gaming dan produktivitas.',
                ],
            ],
            [
                'title' => 'ASUS TUF Gaming B650-PLUS WIFI Motherboard',
                'price' => 3650000.00,
                'stock_quantity' => 10,
                'details' => [
                    'category' => 'motherboard',
                    'brand' => 'ASUS',
                    'socket' => 'AM5',
                    'memory' => 'DDR5',
                    'specs' => 'AM5, DDR5, ATX, WiFi 6',
                    'description' => 'Motherboard ATX socket AM5 dengan dukungan memori DDR5 dan PCIe 5.0 M.2.',
                ],
            ],
            [
                'title' => 'MSI PRO B760M-A WIFI DDR4 Motherboard',
                'price' => 2550000.00,
                'stock_quantity' => 14,
                'details' => [
                    'category' => 'motherboard',
                    'brand' => 'MSI',
                    'socket' => 'LGA1700',
                    'memory' => 'DDR4',
                    'specs' => 'LGA1700, DDR4, mATX',
                    'description' => 'Motherboard Micro-ATX socket LGA1700 untuk prosesor Intel Gen 12/13/14 dengan slot DDR4.',
                ],
            ],
            [
                'title' => 'DeepCool AK620 Dual-Tower CPU Cooler',
                'price' => 980000.00,
                'stock_quantity' => 20,
                'details' => [
                    'category' => 'cooler',
                    'brand' => 'DeepCool',
                    'socket' => 'AM5',
                    'specs' => 'AM4, AM5, LGA1700, LGA1200, 260W TDP',
                    'description' => 'Pendingin CPU dual-tower performa tinggi kompatibel multi-socket Intel dan AMD.',
                ],
            ],
            [
                'title' => 'Corsair Vengeance RGB 32GB (2x16GB) DDR5-6000',
                'price' => 2150000.00,
                'stock_quantity' => 25,
                'details' => [
                    'category' => 'ram',
                    'brand' => 'Corsair',
                    'memory' => 'DDR5',
                    'specs' => 'DDR5, 32GB (2x16GB), 6000MHz CL36',
                    'description' => 'Kit memori DDR5 performa tinggi dengan dukungan AMD EXPO dan Intel XMP.',
                ],
            ],
            [
                'title' => 'Kingston Fury Beast 16GB (2x8GB) DDR4-3200',
                'price' => 720000.00,
                'stock_quantity' => 30,
                'details' => [
                    'category' => 'ram',
                    'brand' => 'Kingston',
                    'memory' => 'DDR4',
                    'specs' => 'DDR4, 16GB (2x8GB), 3200MHz CL16',
                    'description' => 'Memori DDR4 dual-channel andal untuk sistem gaming mainstream.',
                ],
            ],
            [
                'title' => 'Gigabyte GeForce RTX 4070 SUPER Windforce OC 12GB',
                'price' => 10450000.00,
                'stock_quantity' => 8,
                'details' => [
                    'category' => 'vga',
                    'brand' => 'Gigabyte',
                    'specs' => '12GB GDDR6X, DLSS 3',
                    'description' => 'Kartu grafis NVIDIA GeForce RTX 4070 SUPER dengan sistem pendingin triple-fan Windforce.',
                ],
            ],
            [
                'title' => 'Samsung 990 PRO 1TB NVMe PCIe 4.0 SSD',
                'price' => 1850000.00,
                'stock_quantity' => 18,
                'details' => [
                    'category' => 'storage',
                    'brand' => 'Samsung',
                    'specs' => '1TB M.2 NVMe PCIe 4.0, Read 7450MB/s',
                    'description' => 'SSD M.2 NVMe generasi PCIe 4.0 berkecepatan tinggi untuk OS dan game berat.',
                ],
            ],
            [
                'title' => 'Seasonic Focus GX-750 750W 80+ Gold Modular PSU',
                'price' => 1950000.00,
                'stock_quantity' => 15,
                'details' => [
                    'category' => 'psu',
                    'brand' => 'Seasonic',
                    'specs' => '750W, 80+ Gold, Full Modular, ATX 3.0',
                    'description' => 'Power supply 750W efisiensi 80+ Gold full modular dengan kabel 12VHPWR.',
                ],
            ],
            [
                'title' => 'NZXT H6 Flow Compact Dual-Chamber ATX Case',
                'price' => 1790000.00,
                'stock_quantity' => 10,
                'details' => [
                    'category' => 'casing',
                    'brand' => 'NZXT',
                    'specs' => 'Mid-Tower ATX, Panoramic Tempered Glass',
                    'description' => 'Casing ATX dual-chamber dengan aliran udara optimal dan panel kaca panoramik.',
                ],
            ],
            [
                'title' => 'LG UltraGear 27GP850-B 27" QHD Nano IPS 180Hz Monitor',
                'price' => 4590000.00,
                'stock_quantity' => 9,
                'details' => [
                    'category' => 'monitor',
                    'brand' => 'LG',
                    'specs' => '27 Inch, 2560x1440 QHD, 180Hz, 1ms',
                    'description' => 'Monitor gaming Nano IPS QHD 180Hz dengan dukungan NVIDIA G-SYNC Compatible.',
                ],
            ],
            [
                'title' => 'Ascending Titan X3D Ready-Built Gaming PC',
                'price' => 24900000.00,
                'stock_quantity' => 5,
                'details' => [
                    'category' => 'pc-ready',
                    'brand' => 'AMD',
                    'specs' => 'Ryzen 7 7800X3D, RTX 4070 SUPER, 32GB DDR5, 1TB NVMe',
                    'description' => 'PC Gaming siap pakai rakitan profesional untuk gaming 1440p/4K ultra settings.',
                ],
            ],
        ];

        foreach ($catalogFixtures as $index => $item) {
            $vendorId = $vendorIds[$index % count($vendorIds)];
            DB::table('products')->insert([
                'vendor_id' => $vendorId,
                'title' => $item['title'],
                'price' => $item['price'],
                'stock_quantity' => $item['stock_quantity'],
                'details' => json_encode(array_merge($item['details'], [
                    'images' => ['main' => null, 'gallery' => []],
                ])),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
