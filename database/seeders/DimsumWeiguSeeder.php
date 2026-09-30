<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ExpenseCategory;
use App\Models\IngredientCategory;
use App\Models\Outlet;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class DimsumWeiguSeeder extends Seeder
{
    public function run(): void
    {
        Setting::set('store_name', 'Dimsum Weigu', 'Nama usaha');
        Setting::set('store_address', 'Bekasi', 'Alamat usaha');
        Setting::set('store_phone', '083129701342', 'Nomor kontak usaha');
        Setting::set('store_email', 'faridindrawan@gmail.com', 'Email usaha');
        Setting::set('store_business_type', 'food', 'Jenis usaha');
        Setting::set('app_setup_completed', true, 'Profil dan data awal diatur melalui seeder');

        foreach (['Dimsum', 'Lumpia', 'Chiquro', 'Paket Besar'] as $name) {
            Category::firstOrCreate(['name' => $name]);
        }

        foreach ([
            ['code' => 'GRAM', 'name' => 'Gram', 'symbol' => 'g'],
            ['code' => 'ML', 'name' => 'Mililiter', 'symbol' => 'ml'],
        ] as $unit) {
            Unit::updateOrCreate(['code' => $unit['code']], $unit);
        }

        foreach (['Protein', 'Kulit & Tepung', 'Saus', 'Dairy', 'Bumbu', 'Kemasan'] as $name) {
            IngredientCategory::firstOrCreate(['name' => $name]);
        }

        foreach ([
            ['from' => 'KG', 'to' => 'GRAM', 'factor' => 1000],
            ['from' => 'GRAM', 'to' => 'KG', 'factor' => 0.001],
            ['from' => 'LITER', 'to' => 'ML', 'factor' => 1000],
            ['from' => 'ML', 'to' => 'LITER', 'factor' => 0.001],
        ] as $conversion) {
            UnitConversion::updateOrCreate([
                'from_unit_id' => Unit::where('code', $conversion['from'])->value('id'),
                'to_unit_id' => Unit::where('code', $conversion['to'])->value('id'),
            ], ['conversion_factor' => $conversion['factor']]);
        }

        foreach ([
            ['code' => 'CASH', 'name' => 'Tunai', 'type' => 'cash'],
            ['code' => 'QRIS-1', 'name' => 'QRIS 1', 'type' => 'digital'],
            ['code' => 'QRIS-2', 'name' => 'QRIS 2', 'type' => 'digital'],
            ['code' => 'QRIS-3', 'name' => 'QRIS 3', 'type' => 'digital'],
            ['code' => 'GOFOOD', 'name' => 'GoFood / Online', 'type' => 'online'],
        ] as $index => $method) {
            PaymentMethod::updateOrCreate(['code' => $method['code']], $method + ['sort_order' => $index + 1, 'is_active' => true]);
        }

        foreach (['Sewa', 'Lapak', 'Energi/Gas', 'Gaji Karyawan', 'Pembelanjaan Bahan', 'Pembelanjaan Operasional Outlet'] as $name) {
            ExpenseCategory::firstOrCreate(['name' => $name]);
        }

        $branches = [
            [
                'code' => 'GAL-BP',
                'name' => 'Galaxy — Depan SPBU BP',
                'address' => 'Di depan SPBU BP, Galaxy',
                'map_url' => 'https://maps.app.goo.gl/u9Gg5ufsxPXNCmwd9',
                'opening_hours' => [['days' => 'Setiap hari', 'time' => '14.00–00.00']],
            ],
            [
                'code' => 'GAL-HER',
                'name' => 'Galaxy — Samping RS Hermina',
                'address' => 'Di samping RS Hermina, Galaxy',
                'map_url' => 'https://maps.app.goo.gl/xyGKHdRHhcAeBMkA7',
                'opening_hours' => [['days' => 'Setiap hari', 'time' => '08.00–18.00']],
            ],
            [
                'code' => 'PEK-JAYA',
                'name' => 'Pekayon Jaya',
                'address' => 'Pekayon Jaya',
                'map_url' => 'https://maps.app.goo.gl/vBkAyD4WVhMoQUvm6',
                'opening_hours' => [
                    ['days' => 'Senin–Sabtu', 'time' => '14.00–22.00'],
                    ['days' => 'Minggu', 'time' => '06.00–16.00'],
                ],
            ],
            [
                'code' => 'JL-RAYA-PEK',
                'name' => 'Jalan Raya Pekayon — Pakuwon Mall',
                'address' => 'Di samping Pakuwon Mall Bekasi, Jalan Raya Pekayon',
                'map_url' => 'https://maps.app.goo.gl/6efBgdCTcRjrqytH7',
                'opening_hours' => [['days' => 'Setiap hari', 'time' => '14.30–22.15']],
            ],
        ];

        foreach ($branches as $branch) {
            Outlet::updateOrCreate(
                ['code' => $branch['code']],
                [
                    'name' => $branch['name'],
                    'is_active' => true,
                    'is_sales_enabled' => true,
                    'address' => $branch['address'],
                    'map_url' => $branch['map_url'],
                    'opening_hours' => $branch['opening_hours'],
                    'phone' => '083129701342',
                    'email' => 'faridindrawan@gmail.com',
                ],
            );

        }

        $legacyCodes = ['GAL-BP' => 'WH-GAL-BP', 'GAL-HER' => 'WH-GAL-HER', 'PEK-JAYA' => 'WH-PEK-JAYA', 'JL-RAYA-PEK' => 'WH-RAYA-PEK'];
        foreach ($legacyCodes as $outletCode => $warehouseCode) {
            Warehouse::updateOrCreate(
                ['code' => $warehouseCode],
                ['outlet_id' => Outlet::where('code', $outletCode)->value('id'), 'name' => 'Stok Outlet '.$outletCode, 'type' => 'branch', 'is_active' => true],
            );
        }
        Warehouse::updateOrCreate(
            ['code' => 'PUSAT'],
            [
                'outlet_id' => Outlet::where('code', 'PUSAT')->value('id'),
                'name' => 'Gudang Bersama Dimsum Weigu',
                'type' => 'main',
                'is_active' => true,
                'sort_order' => 0,
            ],
        );
        Warehouse::where('code', 'PUSAT')->firstOrFail()->outlets()->sync([]);
    }
}
