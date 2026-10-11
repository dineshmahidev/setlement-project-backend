<?php

namespace Database\Seeders;

use App\Models\ChargeType;
use App\Models\FieldConfiguration;
use Illuminate\Database\Seeder;

class ConfigSeeder extends Seeder
{
    public function run(): void
    {
        // Field configurations — Settlement Bill (spec §28)
        $billFields = [
            ['field_name' => 'Lot Number', 'field_key' => 'lot_number', 'visible' => true, 'required' => false, 'mode' => 'MANUAL', 'sort_order' => 1],
            ['field_name' => 'Vehicle Number', 'field_key' => 'vehicle_number', 'visible' => true, 'required' => false, 'mode' => 'MANUAL', 'sort_order' => 2],
            ['field_name' => 'Net Weight', 'field_key' => 'net_weight', 'visible' => true, 'required' => true, 'mode' => 'MANUAL', 'sort_order' => 3],
            ['field_name' => 'Jute Bag Quantity', 'field_key' => 'jute_bag_quantity', 'visible' => true, 'required' => false, 'mode' => 'MANUAL', 'sort_order' => 4],
            ['field_name' => 'Container Unit Rate', 'field_key' => 'container_unit_rate', 'visible' => true, 'required' => false, 'mode' => 'MANUAL', 'sort_order' => 5],
            ['field_name' => 'Final Unit Rate', 'field_key' => 'final_unit_rate', 'visible' => true, 'required' => true, 'mode' => 'MANUAL', 'sort_order' => 6],
            ['field_name' => 'Final Weight', 'field_key' => 'final_weight', 'visible' => true, 'required' => true, 'mode' => 'MANUAL', 'sort_order' => 7],
            ['field_name' => 'Total Value', 'field_key' => 'total_value', 'visible' => true, 'required' => false, 'mode' => 'AUTOMATIC', 'calculation_rule' => 'BillingQuantity * FinalUnitRate', 'sort_order' => 8],
            ['field_name' => 'PO Number', 'field_key' => 'po_number', 'visible' => false, 'required' => false, 'mode' => 'MANUAL', 'sort_order' => 9],
        ];
        foreach ($billFields as $f) {
            FieldConfiguration::updateOrCreate(
                ['module' => 'SETTLEMENT_BILL', 'field_key' => $f['field_key']],
                $f
            );
        }

        // QC fields
        $qcFields = ['oil_content', 'ffa', 'ss', 'fibre', 'moisture', 'iv', 'calcium', 'protein'];
        $qcNames = ['Oil Content', 'FFA', 'SS', 'Fibre', 'Moisture', 'IV', 'Calcium', 'Protein'];
        foreach ($qcFields as $i => $key) {
            FieldConfiguration::updateOrCreate(
                ['module' => 'QC', 'field_key' => $key],
                ['field_name' => $qcNames[$i], 'visible' => true, 'required' => false, 'mode' => 'MANUAL', 'sort_order' => $i + 1]
            );
        }

        // Charges — all visible by default; Admin can disable (e.g. weighing charges → disappears)
        $charges = [
            ['Bag Amount', 'bag_amount', 'ADD'],
            ['Bran Value', 'bran_value', 'ADD'],
            ['Weighing Charges', 'weighing_charges', 'ADD'],
            ['Unloading Charges', 'unloading_charges', 'DEDUCT'],
            ['Loading Charges', 'loading_charges', 'DEDUCT'],
            ['Advance Paid', 'advance_paid', 'DEDUCT'],
            ['Lorry Freight', 'lorry_freight', 'DEDUCT'],
            ['Other Charges', 'other_charges', 'ADD'],
            ['Rebate', 'rebate', 'DEDUCT'],
        ];
        foreach ($charges as $i => [$name, $key, $direction]) {
            FieldConfiguration::updateOrCreate(
                ['module' => 'CHARGES', 'field_key' => $key],
                ['field_name' => $name, 'visible' => true, 'required' => false, 'mode' => 'MANUAL', 'sort_order' => $i + 1]
            );

            ChargeType::updateOrCreate(
                ['code' => $key],
                [
                    'name' => $name,
                    'mode' => 'MANUAL',
                    'direction' => $direction,
                    'calculation_rule' => null,
                    'default_amount' => 0,
                    'active' => true,
                    'sort_order' => $i + 1,
                ]
            );
        }
    }
}
