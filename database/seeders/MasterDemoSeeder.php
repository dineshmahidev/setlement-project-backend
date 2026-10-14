<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Unit;
use App\Models\Vehicle;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class MasterDemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('code', 'laxmi')->first() ?? Company::first();
        $companyId = $company?->id ?? 1;

        // 1. Units
        $units = [
            ['name' => 'Kilogram', 'code' => 'KG', 'status' => 'ACTIVE'],
            ['name' => 'Metric Ton', 'code' => 'MT', 'status' => 'ACTIVE'],
            ['name' => 'Bag', 'code' => 'BAG', 'status' => 'ACTIVE'],
            ['name' => 'Tons', 'code' => 'TON', 'status' => 'ACTIVE'],
            ['name' => 'Litre', 'code' => 'LITRE', 'status' => 'ACTIVE'],
            ['name' => 'Numbers', 'code' => 'NOS', 'status' => 'ACTIVE'],
        ];
        foreach ($units as $u) {
            Unit::updateOrCreate(['code' => $u['code']], $u);
        }

        // 2. Categories
        if (Schema::hasTable('item_categories')) {
            foreach (['Oil Cake', 'Grains', 'By Products', 'Packaging'] as $i => $name) {
                ItemCategory::updateOrCreate(['name' => $name], ['code' => 'CAT'.($i + 1), 'status' => 'ACTIVE']);
            }
        }

        // 3. Items
        if (Schema::hasTable('items')) {
            $kg = Unit::where('code', 'KG')->first();
            $items = [
                ['code' => 'RB-G2', 'name' => 'Rice Bran Grade II', 'category' => 'Oil Cake', 'min' => 1000, 'max' => 50000],
                ['code' => 'SB-CP', 'name' => 'Soybean Cake', 'category' => 'Oil Cake', 'min' => 500, 'max' => 30000],
                ['code' => 'GC-RAW', 'name' => 'Groundnut Cake', 'category' => 'Oil Cake', 'min' => 500, 'max' => 20000],
                ['code' => 'RICE-BRN', 'name' => 'Rice Bran', 'category' => 'By Products', 'min' => 2000, 'max' => 100000],
            ];
            foreach ($items as $it) {
                Item::updateOrCreate(['code' => $it['code']], [
                    'company_id' => $companyId,
                    'name' => $it['name'],
                    'category_id' => Schema::hasTable('item_categories') ? ItemCategory::where('name', $it['category'])->first()?->id : null,
                    'unit_id' => $kg?->id,
                    'min_stock_level' => $it['min'],
                    'max_stock_level' => $it['max'],
                    'status' => 'ACTIVE',
                ]);
            }
        }

        // 4. Vendors
        if (Schema::hasTable('vendors')) {
            $vendors = [
                ['code' => 'VEN001', 'name' => 'S.K. Sathana Traders', 'city' => 'Tiruppur', 'state' => 'Tamil Nadu', 'gst' => '33AAGFS1234A1Z5', 'pan' => 'AAGFS1234A'],
                ['code' => 'VEN002', 'name' => 'Anna Agro Mills', 'city' => 'Dindigul', 'state' => 'Tamil Nadu', 'gst' => '33AACFA5678B1Z2', 'pan' => 'AACFA5678B'],
                ['code' => 'VEN003', 'name' => 'Kaveri Enterprises', 'city' => 'Erode', 'state' => 'Tamil Nadu', 'gst' => '33AADFK9012C1Z9', 'pan' => 'AADFK9012C'],
            ];
            foreach ($vendors as $v) {
                Vendor::updateOrCreate(['vendor_code' => $v['code']], [
                    'company_id' => $companyId,
                    'vendor_name' => $v['name'],
                    'address' => $v['city'].' Main Road',
                    'city' => $v['city'],
                    'district' => $v['city'],
                    'state' => $v['state'],
                    'pincode' => '641001',
                    'gstin' => $v['gst'],
                    'phone' => '9876543210',
                    'email' => strtolower(str_replace(' ', '', $v['name'])).'@example.com',
                    'pan' => $v['pan'],
                    'party_type' => 'VENDOR',
                    'status' => 'ACTIVE',
                ]);
            }
        }

        // 5. Vehicles
        if (Schema::hasTable('vehicles')) {
            $vehicles = [
                ['number' => 'TN28AC3072', 'type' => 'Truck', 'driver' => 'Ravi Kumar', 'mobile' => '9000000001'],
                ['number' => 'TN38BX4411', 'type' => 'Truck', 'driver' => 'Suresh Babu', 'mobile' => '9000000002'],
                ['number' => 'TN69CZ1209', 'type' => 'Lorry', 'driver' => 'Mani', 'mobile' => '9000000003'],
            ];
            foreach ($vehicles as $v) {
                Vehicle::updateOrCreate(['vehicle_number' => $v['number']], [
                    'company_id' => $companyId,
                    'vehicle_type' => $v['type'],
                    'driver_name' => $v['driver'],
                    'driver_mobile' => $v['mobile'],
                    'status' => 'ACTIVE',
                ]);
            }
        }
    }
}
