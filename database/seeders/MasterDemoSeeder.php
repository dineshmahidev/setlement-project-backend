<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Unit;
use App\Models\Vehicle;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

class MasterDemoSeeder extends Seeder
{
    public function run(): void
    {
        // Units
        $units = [
            ['name' => 'Kilogram', 'code' => 'KG'],
            ['name' => 'Metric Ton', 'code' => 'MT'],
            ['name' => 'Bag', 'code' => 'BAG'],
            ['name' => 'Litre', 'code' => 'LITRE'],
            ['name' => 'Numbers', 'code' => 'NOS'],
        ];
        foreach ($units as $u) {
            Unit::updateOrCreate(['code' => $u['code']], $u);
        }

        // Categories
        foreach (['Oil Cake', 'Grains', 'By Products', 'Packaging'] as $i => $name) {
            ItemCategory::updateOrCreate(['name' => $name], ['code' => 'CAT'.($i + 1), 'status' => 'ACTIVE']);
        }

        // Items
        $kg = Unit::where('code', 'KG')->first();
        $items = [
            ['code' => 'RB-G2', 'name' => 'Rice Bran Grade II', 'category' => 'Oil Cake', 'min' => 1000, 'max' => 50000],
            ['code' => 'SB-CP', 'name' => 'Soybean Cake', 'category' => 'Oil Cake', 'min' => 500, 'max' => 30000],
            ['code' => 'GC-RAW', 'name' => 'Groundnut Cake', 'category' => 'Oil Cake', 'min' => 500, 'max' => 20000],
            ['code' => 'RICE-BRN', 'name' => 'Rice Bran', 'category' => 'By Products', 'min' => 2000, 'max' => 100000],
        ];
        foreach ($items as $it) {
            Item::updateOrCreate(['code' => $it['code']], [
                'name' => $it['name'],
                'category_id' => ItemCategory::where('name', $it['category'])->first()?->id,
                'unit_id' => $kg?->id,
                'min_stock_level' => $it['min'],
                'max_stock_level' => $it['max'],
                'status' => 'ACTIVE',
            ]);
        }

        // Vendors
        $tpr = Branch::where('code', 'TPR')->first();
        $dgl = Branch::where('code', 'DGL')->first();
        $vendors = [
            ['code' => 'VEN001', 'name' => 'S.K. Sathana Traders', 'city' => 'Tiruppur', 'state' => 'Tamil Nadu', 'gst' => '33AAGFS1234A1Z5', 'pan' => 'AAGFS1234A', 'po' => 'PO-2026-001', 'branch' => $tpr],
            ['code' => 'VEN002', 'name' => 'Anna Agro Mills', 'city' => 'Dindigul', 'state' => 'Tamil Nadu', 'gst' => '33AACFA5678B1Z2', 'pan' => 'AACFA5678B', 'po' => 'PO-2026-002', 'branch' => $dgl],
            ['code' => 'VEN003', 'name' => 'Kaveri Enterprises', 'city' => 'Erode', 'state' => 'Tamil Nadu', 'gst' => '33AADFK9012C1Z9', 'pan' => 'AADFK9012C', 'po' => 'PO-2026-003', 'branch' => $tpr],
        ];
        foreach ($vendors as $v) {
            Vendor::updateOrCreate(['code' => $v['code']], [
                'name' => $v['name'],
                'address' => $v['city'].' Main Road',
                'city' => $v['city'],
                'district' => $v['city'],
                'state' => $v['state'],
                'pincode' => '641001',
                'gst_number' => $v['gst'],
                'phone' => '9876543210',
                'email' => strtolower(str_replace(' ', '', $v['name'])).'@example.com',
                'pan' => $v['pan'],
                'po_number' => $v['po'],
                'default_branch_id' => $v['branch']?->id,
                'status' => 'ACTIVE',
            ]);
        }

        // Vehicles
        $vehicles = [
            ['number' => 'TN28AC3072', 'type' => 'Truck', 'driver' => 'Ravi Kumar', 'mobile' => '9000000001', 'branch' => $tpr],
            ['number' => 'TN38BX4411', 'type' => 'Truck', 'driver' => 'Suresh Babu', 'mobile' => '9000000002', 'branch' => $dgl],
            ['number' => 'TN69CZ1209', 'type' => 'Lorry', 'driver' => 'Mani', 'mobile' => '9000000003', 'branch' => $tpr],
        ];
        foreach ($vehicles as $v) {
            Vehicle::updateOrCreate(['number' => $v['number']], [
                'type' => $v['type'],
                'owner_name' => 'SVMA Fleet',
                'driver_name' => $v['driver'],
                'driver_mobile' => $v['mobile'],
                'rc_number' => 'RC-'.substr($v['number'], -4),
                'insurance_expiry' => now()->addMonths(8)->toDateString(),
                'permit_expiry' => now()->addMonths(10)->toDateString(),
                'fitness_expiry' => now()->addMonths(6)->toDateString(),
                'branch_id' => $v['branch']?->id,
                'status' => 'ACTIVE',
            ]);
        }
    }
}
