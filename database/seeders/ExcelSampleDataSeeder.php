<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\DebitNote;
use App\Models\DebitNoteItem;
use App\Models\FinancialYear;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Settlement;
use App\Models\SettlementQualityTest;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorLedger;
use Illuminate\Database\Seeder;

class ExcelSampleDataSeeder extends Seeder
{
    public function run(): void
    {
        $adminUser = User::first();
        $adminId = $adminUser ? $adminUser->id : 1;

        // 1. Branch: LAXMI TRADERS -TRICHY
        $branch = Branch::updateOrCreate(
            ['branch_code' => 'LT-TRY'],
            [
                'branch_name' => 'LAXMI TRADERS -TRICHY',
                'address' => 'No 54-PALLAVARAYAN PALAYAM, VEERANAMPALAYAM, PO., KANGEYAM',
                'city' => 'TRICHY',
                'district' => 'Tiruchirappalli',
                'state' => 'Tamil Nadu',
                'pincode' => '620010',
                'gstin' => '33BWLPR6363A3ZN',
                'phone' => '0431-2456789',
                'email' => 'laxmitraders.trichy@example.com',
                'status' => 'active',
            ]
        );

        // 2. Vendor 1: SRI RAM AGRO (from Excel image)
        $vendor1 = Vendor::updateOrCreate(
            ['vendor_code' => 'VEN-SRA'],
            [
                'vendor_name' => 'SRI RAM AGRO',
                'business_name' => 'SRI RAM AGRO TRADERS',
                'contact_person' => 'Ramachandran S.',
                'phone' => '9842156789',
                'email' => 'sriramagro@example.com',
                'address' => 'ARIYALUR Main Road',
                'city' => 'ARIYALUR',
                'district' => 'Ariyalur',
                'state' => 'Tamil Nadu',
                'pincode' => '621704',
                'gstin' => '33BWLPR6363A3ZN',
                'pan' => 'BWLPR6363A',
                'status' => 'active',
            ]
        );

        // Vendor 2: LAXMI TRADERS (Vendor party from Debit note picture)
        $vendor2 = Vendor::updateOrCreate(
            ['vendor_code' => 'VEN-LTK'],
            [
                'vendor_name' => 'LAXMI TRADERS',
                'business_name' => 'LAXMI TRADERS GRAINS',
                'contact_person' => 'N. Velusamy',
                'phone' => '9443123456',
                'email' => 'laxmitraders.kng@example.com',
                'address' => 'No 54-PALLAVARAYAN PALAYAM, VEERANAMPALAYAM, PO., KANGEYAM',
                'city' => 'KANGEYAM',
                'district' => 'Tirupur',
                'state' => 'Tamil Nadu',
                'pincode' => '620010',
                'gstin' => '33BWLPR6363A3ZN',
                'pan' => 'BWLPR6363A',
                'status' => 'active',
            ]
        );

        // 3. Product: BOILED BRAN
        $product = Product::updateOrCreate(
            ['product_code' => 'BOILED-BRAN'],
            [
                'product_name' => 'BOILED BRAN',
                'product_type' => 'Raw Material',
                'unit' => 'MT',
                'default_rate' => 1830.00,
                'default_cgst' => 2.50,
                'default_sgst' => 2.50,
                'default_igst' => 5.00,
                'status' => 'active',
            ]
        );

        // =========================================================================
        // SAMPLE 1: EXACT EXCEL SETTLEMENT BILL (SRI RAM AGRO - 22.800 MT)
        // =========================================================================
        $settlement1 = Settlement::updateOrCreate(
            ['settlement_number' => 'SB/2026/TRICHY/1020'],
            [
                'financial_year' => '2026-27',
                'branch_id' => $branch->id,
                'vendor_id' => $vendor1->id,
                'product_id' => $product->id,
                'lot_number' => 'LOT-2026-ARIYALUR',
                'bill_number' => '68',
                'bill_date' => '2026-08-30',
                'settlement_date' => '2026-08-30',
                'vehicle_number' => 'TN28AH3345',
                'net_weight_mt' => 22.800,
                'jute_bag' => 0.000,
                'pp_bag' => 0.000,
                'final_weight_mt' => 22.800,
                'final_weight_kg' => 22800.00,
                'oil_content' => 25.52,
                'ffa' => 2.50,
                'ss' => 1.20,
                'fibre' => 9.80,
                'moisture' => 7.50,
                'iv' => 95.0,
                'calcium' => 0.0,
                'protein' => 0.0,
                'contract_unit_rate' => 1880.00,
                'final_unit_rate' => 1830.00,
                'oil_quantity_kg' => 5818.560,
                'bran_value' => 1064796.48,
                'bag_amount' => 0.00,
                'loading_charges' => 0.00,
                'unloading_charges' => 0.00,
                'tds' => 0.00,
                'advance_paid' => 0.00,
                'lorry_freight' => 0.00,
                'other_charges' => 0.00,
                'original_invoice_value' => 1064796.48,
                'rebate_amount' => 0.00,
                'subtotal_amount' => 1064796.48,
                'is_interstate' => false,
                'cgst_rate' => 2.500,
                'sgst_rate' => 2.500,
                'igst_rate' => 0.000,
                'cgst_amount' => 26619.91,
                'sgst_amount' => 26619.91,
                'igst_amount' => 0.00,
                'total_amount' => 1118036.30,
                'net_payable' => 1118036.30,
                'status' => 'approved',
                'remarks' => 'Exact sample fed from Excel Settlement Bill',
                'created_by' => $adminId,
            ]
        );

        // Quality tests for Sample 1
        SettlementQualityTest::where('settlement_id', $settlement1->id)->delete();
        $qcList1 = [
            'Oil Content' => 25.52,
            'FFA' => 'PASS',
            'SS' => 'PASS',
            'Fibre' => 'PASS',
            'Moisture' => 'PASS',
        ];
        foreach ($qcList1 as $k => $v) {
            SettlementQualityTest::create([
                'settlement_id' => $settlement1->id,
                'parameter_name' => $k,
                'parameter_value' => is_numeric($v) ? $v : 0,
            ]);
        }

        // Ledger entry for Sample 1
        VendorLedger::updateOrCreate(
            ['document_number' => 'SB/2026/TRICHY/1020'],
            [
                'vendor_id' => $vendor1->id,
                'branch_id' => $branch->id,
                'transaction_date' => '2026-08-30',
                'document_type' => 'settlement',
                'document_id' => $settlement1->id,
                'lot_number' => 'LOT-2026-ARIYALUR',
                'description' => 'Settlement Bill - 22.800 MT Boiled Bran (Oil 25.52%)',
                'debit' => 0,
                'credit' => 1118036.30,
                'created_by' => $adminId,
            ]
        );

        // =========================================================================
        // SAMPLE 2: EXACT COMBINED SETTLEMENT BILL & DEBIT NOTE (LAXMI TRADERS 15.095 MT)
        // =========================================================================
        // Original Invoice 68
        $inv2 = Invoice::updateOrCreate(
            ['invoice_number' => 'INV-68-KNG'],
            [
                'invoice_date' => '2026-08-07',
                'branch_id' => $branch->id,
                'vendor_id' => $vendor2->id,
                'product_id' => $product->id,
                'lot_number' => 'RB-G2/080803/26-27',
                'vehicle_number' => 'TN28AH3345',
                'net_weight_mt' => 15.095,
                'rate' => 27327.13,
                'taxable_amount' => 412503.00,
                'is_interstate' => false,
                'cgst_rate' => 2.500,
                'sgst_rate' => 2.500,
                'igst_rate' => 0.000,
                'cgst_amount' => 10312.58,
                'sgst_amount' => 10312.58,
                'igst_amount' => 0.00,
                'total_amount' => 433128.00,
                'status' => 'approved',
                'remarks' => 'Original Invoice 68 for 15.095 MT',
                'created_by' => $adminId,
            ]
        );

        // Settlement Bill SB/2627/001019
        $settlement2 = Settlement::updateOrCreate(
            ['settlement_number' => 'SB/2627/001019'],
            [
                'financial_year' => '2026-27',
                'branch_id' => $branch->id,
                'vendor_id' => $vendor2->id,
                'product_id' => $product->id,
                'invoice_id' => $inv2->id,
                'lot_number' => 'RB-G2/080803/26-27',
                'bill_number' => '68',
                'bill_date' => '2026-08-08',
                'settlement_date' => '2026-08-08',
                'vehicle_number' => 'TN28AH3345',
                'net_weight_mt' => 15.095,
                'jute_bag' => 0.000,
                'pp_bag' => 0.000,
                'final_weight_mt' => 15.095,
                'final_weight_kg' => 15095.00,
                'oil_content' => 13.10,
                'ffa' => 2.92,
                'ss' => 11.77,
                'fibre' => 32.79,
                'moisture' => 6.32,
                'iv' => 0.905,
                'calcium' => 0.0,
                'protein' => 0.0,
                'contract_unit_rate' => 210.00,
                'final_unit_rate' => 203.00,
                'oil_quantity_kg' => 1977.445,
                'bran_value' => 401421.00,
                'bag_amount' => 0.00,
                'loading_charges' => 0.00,
                'unloading_charges' => 0.00,
                'tds' => 0.00,
                'advance_paid' => 0.00,
                'lorry_freight' => 0.00,
                'other_charges' => 0.00,
                'original_invoice_value' => 412503.00,
                'rebate_amount' => 11082.00,
                'subtotal_amount' => 401421.00,
                'is_interstate' => false,
                'cgst_rate' => 2.500,
                'sgst_rate' => 2.500,
                'igst_rate' => 0.000,
                'cgst_amount' => 10036.00,
                'sgst_amount' => 10036.00,
                'igst_amount' => 0.00,
                'total_amount' => 421493.00,
                'net_payable' => 421493.00,
                'status' => 'approved',
                'remarks' => 'Exact sample combined Settlement Bill with Debit Note',
                'created_by' => $adminId,
            ]
        );

        // Quality tests for Sample 2
        SettlementQualityTest::where('settlement_id', $settlement2->id)->delete();
        $qcList2 = [
            'Oil Content' => 13.10,
            'FFA' => 2.92,
            'SS' => 11.77,
            'Fibre' => 32.79,
            'Moisture' => 6.32,
            'IV' => 0.905,
            'Calcium' => 0.0,
            'Protein' => 0.0,
        ];
        foreach ($qcList2 as $k => $v) {
            SettlementQualityTest::create([
                'settlement_id' => $settlement2->id,
                'parameter_name' => $k,
                'parameter_value' => $v,
            ]);
        }

        // Linked Debit Note: DN/1019/26-27
        $dn2 = DebitNote::updateOrCreate(
            ['debit_note_number' => 'DN/1019/26-27'],
            [
                'financial_year' => '2026-27',
                'branch_id' => $branch->id,
                'vendor_id' => $vendor2->id,
                'settlement_id' => $settlement2->id,
                'invoice_id' => $inv2->id,
                'invoice_number' => '68',
                'invoice_date' => '2026-08-07',
                'lot_number' => 'RB-G2/080803/26-27',
                'date' => '2026-08-08',
                'reason' => 'Rebate on Oil Content Settlement',
                'transaction_type' => 'debit',
                'taxable_amount' => 11082.00,
                'is_interstate' => false,
                'cgst_rate' => 2.500,
                'sgst_rate' => 2.500,
                'igst_rate' => 0.000,
                'cgst_amount' => 277.00,
                'sgst_amount' => 277.00,
                'igst_amount' => 0.00,
                'total_amount' => 11636.00,
                'status' => 'approved',
                'remarks' => 'Auto generated Rebate Debit Note #DN/1019/26-27',
                'created_by' => $adminId,
            ]
        );

        DebitNoteItem::where('debit_note_id', $dn2->id)->delete();
        DebitNoteItem::create([
            'debit_note_id' => $dn2->id,
            'particulars' => 'Rebate',
            'amount' => 11082.00,
        ]);

        // Ledger entries
        VendorLedger::updateOrCreate(
            ['document_number' => 'DN/1019/26-27'],
            [
                'vendor_id' => $vendor2->id,
                'branch_id' => $branch->id,
                'transaction_date' => '2026-08-08',
                'document_type' => 'debit_note',
                'document_id' => $dn2->id,
                'lot_number' => 'RB-G2/080803/26-27',
                'description' => 'Debit Note - Rebate on Oil Content Settlement SB/2627/001019',
                'debit' => 11636.00,
                'credit' => 0,
                'created_by' => $adminId,
            ]
        );
    }
}
