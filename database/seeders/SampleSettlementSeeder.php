<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Product;
use App\Models\Settlement;
use App\Models\SettlementCharge;
use App\Models\SettlementQualityTest;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorLedger;
use App\Services\SettlementCalculationService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SampleSettlementSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('code', 'laxmi')->first() ?? Company::first();
        $branch = Branch::where('company_id', $company->id)->first() ?? Branch::first();
        $user = User::where('email', 'admin@laxmitraders.com')->first() ?? User::first();
        $today = Carbon::today();

        if (!FinancialYear::where('fy_code', '2026-27')->exists()) {
            FinancialYear::create([
                'fy_code' => '2026-27',
                'start_date' => '2026-04-01',
                'end_date' => '2027-03-31',
                'is_current' => true,
                'status' => 'active',
            ]);
        }

        foreach ([['MT', 'Metric Tonne'], ['KG', 'Kilogram'], ['BAG', 'Bags'], ['TON', 'Tons']] as [$code, $name]) {
            Unit::firstOrCreate(['code' => $code], ['name' => $name, 'status' => 'ACTIVE']);
        }
        $catAgro = ItemCategory::firstOrCreate(['code' => 'AGRO'], ['name' => 'Agro Commodities', 'status' => 'ACTIVE']);
        $unitMt = Unit::where('code', 'MT')->first();

        $products = [
            ['product_name' => 'Rice Bran Grade II', 'product_code' => 'PROD-RB2', 'product_type' => 'Rice Bran', 'default_rate' => 203.00, 'oil' => 13.50],
            ['product_name' => 'Boiled Bran (Steam)', 'product_code' => 'PROD-BB1', 'product_type' => 'Boiled Bran', 'default_rate' => 183.00, 'oil' => 12.80],
            ['product_name' => 'Raw Rice Bran', 'product_code' => 'PROD-RRB', 'product_type' => 'Rice Bran', 'default_rate' => 215.00, 'oil' => 14.20],
            ['product_name' => 'De-oiled Rice Bran (DORB)', 'product_code' => 'PROD-DORB', 'product_type' => 'De-oiled Bran', 'default_rate' => 140.00, 'oil' => 2.00],
            ['product_name' => 'Rice Bran Oil', 'product_code' => 'PROD-RBO', 'product_type' => 'Oil', 'default_rate' => 110.00, 'oil' => 0.00],
            ['product_name' => 'Broken Rice', 'product_code' => 'PROD-BRK', 'product_type' => 'Rice', 'default_rate' => 32.00, 'oil' => 0.00],
        ];

        $productModels = [];
        foreach ($products as $p) {
            $productModels[$p['product_code']] = Product::updateOrCreate(
                ['product_code' => $p['product_code'], 'company_id' => $company->id],
                [
                    'product_name' => $p['product_name'],
                    'product_type' => $p['product_type'],
                    'unit' => 'MT',
                    'default_rate' => $p['default_rate'],
                    'default_cgst' => 2.500,
                    'default_sgst' => 2.500,
                    'default_igst' => 5.000,
                    'status' => 'active',
                ]
            );

            Item::updateOrCreate(
                ['code' => $p['product_code'], 'company_id' => $company->id],
                [
                    'name' => $p['product_name'],
                    'material_type' => $p['product_type'],
                    'default_oil_pct' => $p['oil'],
                    'category_id' => $catAgro->id,
                    'unit_id' => $unitMt?->id,
                    'min_stock_level' => 10.000,
                    'max_stock_level' => 1000.000,
                    'status' => 'ACTIVE',
                ]
            );
        }

        $vendors = [
            ['vendor_code' => 'VEND-001', 'vendor_name' => 'S.K. Sathana Traders', 'business_name' => 'Sathana Agro Mill & Traders', 'city' => 'Gobichettipalayam', 'district' => 'Erode', 'gstin' => '33AABCS5544R1Z8', 'pan' => 'AABCS5544R', 'phone' => '9842155678', 'email' => 'sathanatraders@gmail.com', 'contact_person' => 'K. Sathasivam', 'bank_name' => 'Canara Bank', 'account_number' => '120938472910', 'ifsc' => 'CNRB0001209'],
            ['vendor_code' => 'VEND-002', 'vendor_name' => 'Sri Murugan Modern Rice Mill', 'business_name' => 'Sri Murugan Agro Foods', 'city' => 'Thanjavur', 'district' => 'Thanjavur', 'gstin' => '33BCDPS1298K1ZQ', 'pan' => 'BCDPS1298K', 'phone' => '9443218765', 'email' => 'muruganmill@yahoo.com', 'contact_person' => 'M. Murugesan', 'bank_name' => 'HDFC Bank', 'account_number' => '50200012938471', 'ifsc' => 'HDFC0000456'],
            ['vendor_code' => 'VEND-003', 'vendor_name' => 'Lakshmi Rice Industries', 'business_name' => 'Lakshmi Agro Processors', 'city' => 'Namakkal', 'district' => 'Namakkal', 'gstin' => '33AADFL9921E1Z3', 'pan' => 'AADFL9921E', 'phone' => '9842765432', 'email' => 'lakshmirice@gmail.com', 'contact_person' => 'V. Lakshmanan', 'bank_name' => 'Indian Overseas Bank', 'account_number' => '019283746519', 'ifsc' => 'IOBA0000192'],
            ['vendor_code' => 'VEND-004', 'vendor_name' => 'Kongu Vinayagar Mills', 'business_name' => 'Kongu Agro Products', 'city' => 'Erode', 'district' => 'Erode', 'gstin' => '33AAFCK7788M1Z5', 'pan' => 'AAFCK7788M', 'phone' => '9894123456', 'email' => 'kongumills@example.com', 'contact_person' => 'R. Velmurugan', 'bank_name' => 'State Bank of India', 'account_number' => '3344556677889900', 'ifsc' => 'SBIN0001234'],
            ['vendor_code' => 'VEND-005', 'vendor_name' => 'Pandiyan Rice Traders', 'business_name' => 'Pandiyan Trading Co', 'city' => 'Madurai', 'district' => 'Madurai', 'gstin' => '33AAGCP1122N1Z7', 'pan' => 'AAGCP1122N', 'phone' => '9843765412', 'email' => 'pandyantrading@example.com', 'contact_person' => 'S. Pandiyan', 'bank_name' => 'Axis Bank', 'account_number' => '9110012345678901', 'ifsc' => 'UTIB0001111'],
            ['vendor_code' => 'VEND-006', 'vendor_name' => 'Cauvery Agro Exports', 'business_name' => 'Cauvery Agro Exports Pvt Ltd', 'city' => 'Thanjavur', 'district' => 'Thanjavur', 'gstin' => '33AACCC9900P1Z9', 'pan' => 'AACCC9900P', 'phone' => '9842009988', 'email' => 'cauveryagro@example.com', 'contact_person' => 'A. Rajesh', 'bank_name' => 'ICICI Bank', 'account_number' => '000701234567890', 'ifsc' => 'ICIC0000007'],
            ['vendor_code' => 'VEND-007', 'vendor_name' => 'Nellai Rice Mill', 'business_name' => 'Nellai Agro Mills', 'city' => 'Tirunelveli', 'district' => 'Tirunelveli', 'gstin' => '33AABFN5566Q1Z4', 'pan' => 'AABFN5566Q', 'phone' => '9843112233', 'email' => 'nellaimill@example.com', 'contact_person' => 'T. Murugan', 'bank_name' => 'Indian Bank', 'account_number' => '6677889900112233', 'ifsc' => 'IDIB0001234'],
            ['vendor_code' => 'VEND-008', 'vendor_name' => 'Chola Steamed Rice Bran Co', 'business_name' => 'Chola Agro Works', 'city' => 'Trichy', 'district' => 'Tiruchirappalli', 'gstin' => '33AABCC3344R1Z6', 'pan' => 'AABCC3344R', 'phone' => '9842556677', 'email' => 'cholaagro@example.com', 'contact_person' => 'B. Karthik', 'bank_name' => 'Kotak Mahindra Bank', 'account_number' => '8899001122334455', 'ifsc' => 'KKBK0001234'],
        ];

        $vendorModels = [];
        foreach ($vendors as $v) {
            $vendorModels[$v['vendor_code']] = Vendor::updateOrCreate(
                ['vendor_code' => $v['vendor_code'], 'company_id' => $company->id],
                $v + [
                    'address' => 'Main Road, ' . $v['city'],
                    'state' => 'Tamil Nadu',
                    'pincode' => '638' . str_pad((string) (array_search($v, $vendors) + 100), 3, '0', STR_PAD_LEFT),
                    'opening_balance' => 0.00,
                    'party_type' => 'vendor',
                    'status' => 'active',
                ]
            );
        }

        if (Settlement::where('settlement_number', 'SB/2026/' . $branch->branch_code . '/0001')->exists()) {
            $this->command?->info('Sample vendors, products, items and settlement already seeded.');
            return;
        }

        $vendor = $vendorModels['VEND-001'];
        $product = $productModels['PROD-RB2'];

        $invoice = Invoice::create([
            'invoice_number' => 'INV-68',
            'invoice_date' => $today->copy()->subDays(5),
            'branch_id' => $branch->id,
            'vendor_id' => $vendor->id,
            'product_id' => $product->id,
            'lot_number' => 'LOT-2026-081',
            'vehicle_number' => 'TN 33 BM 4567',
            'net_weight_mt' => 15.095,
            'rate' => 27320.00,
            'taxable_amount' => 412503.00,
            'is_interstate' => false,
            'cgst_rate' => 2.500,
            'sgst_rate' => 2.500,
            'igst_rate' => 0.000,
            'cgst_amount' => 10312.58,
            'sgst_amount' => 10312.58,
            'igst_amount' => 0.00,
            'total_amount' => 433128.16,
            'status' => 'approved',
            'remarks' => 'Original Vendor Delivery Invoice #68',
            'created_by' => $user?->id,
        ]);

        $calc = app(SettlementCalculationService::class)->calculate([
            'net_weight_mt' => 15.095,
            'final_weight_mt' => 15.095,
            'oil_content' => 13.10,
            'contract_unit_rate' => 203.00,
            'final_unit_rate' => 203.00,
            'original_invoice_value' => 412503.00,
            'is_interstate' => false,
            'cgst_rate' => 2.500,
            'sgst_rate' => 2.500,
            'bag_amount' => 1200.00,
            'loading_charges' => 800.00,
            'unloading_charges' => 600.00,
            'tds' => 400.00,
            'advance_paid' => 50000.00,
            'lorry_freight' => 8500.00,
            'rounding_mode' => 'nearest_rupee',
        ]);

        $settlement = Settlement::create([
            'settlement_number' => 'SB/2026/' . $branch->branch_code . '/0001',
            'financial_year' => '2026-27',
            'branch_id' => $branch->id,
            'vendor_id' => $vendor->id,
            'product_id' => $product->id,
            'invoice_id' => $invoice->id,
            'lot_number' => 'LOT-2026-081',
            'bill_number' => '68',
            'bill_date' => $invoice->invoice_date,
            'settlement_date' => $today->copy()->subDays(1),
            'vehicle_number' => 'TN 33 BM 4567',
            'net_weight_mt' => 15.095,
            'jute_bag' => 0.000,
            'pp_bag' => 0.000,
            'final_weight_mt' => $calc['final_weight_mt'],
            'final_weight_kg' => $calc['final_weight_kg'],
            'oil_content' => 13.10,
            'ffa' => '6.20',
            'ss' => '1.10',
            'fibre' => '9.50',
            'moisture' => '8.20',
            'iv' => '98.0',
            'calcium' => '0.45',
            'protein' => '14.50',
            'contract_unit_rate' => 203.00,
            'final_unit_rate' => 203.00,
            'oil_quantity_kg' => $calc['oil_quantity_kg'],
            'bran_value' => $calc['bran_value'],
            'bag_amount' => 1200.00,
            'loading_charges' => 800.00,
            'unloading_charges' => 600.00,
            'tds' => 400.00,
            'advance_paid' => 50000.00,
            'lorry_freight' => 8500.00,
            'other_charges' => 0.00,
            'original_invoice_value' => 412503.00,
            'rebate_amount' => $calc['rebate_amount'],
            'subtotal_amount' => $calc['subtotal_amount'],
            'is_interstate' => false,
            'cgst_rate' => 2.500,
            'sgst_rate' => 2.500,
            'igst_rate' => 0.000,
            'cgst_amount' => $calc['cgst_amount'],
            'sgst_amount' => $calc['sgst_amount'],
            'igst_amount' => 0.00,
            'total_amount' => $calc['total_amount'],
            'net_payable' => $calc['net_payable'],
            'status' => 'approved',
            'remarks' => 'Sample settlement seed data',
            'created_by' => $user?->id,
            'approved_by' => $user?->id,
        ]);

        foreach ([
            ['Oil Content', '13.10', '12.00 - 15.00'],
            ['FFA', '6.20', 'PASS'],
            ['SS', '1.10', 'PASS'],
            ['Fibre', '9.50', 'PASS'],
            ['Moisture', '8.20', 'MAX 12.00'],
            ['IV', '98.0', '95 - 105'],
            ['Calcium', '0.45', 'PASS'],
            ['Protein', '14.50', 'MIN 13.00'],
        ] as [$name, $value, $std]) {
            SettlementQualityTest::create([
                'settlement_id' => $settlement->id,
                'parameter_name' => $name,
                'parameter_value' => $value,
                'standard_value' => $std,
            ]);
        }

        SettlementCharge::create(['settlement_id' => $settlement->id, 'charge_name' => 'Bag Amount', 'charge_type' => 'addition', 'amount' => 1200.00, 'remarks' => 'Jute/PP bag deduction']);
        SettlementCharge::create(['settlement_id' => $settlement->id, 'charge_name' => 'Loading Charges', 'charge_type' => 'addition', 'amount' => 800.00]);
        SettlementCharge::create(['settlement_id' => $settlement->id, 'charge_name' => 'Unloading Charges', 'charge_type' => 'deduction', 'amount' => 600.00]);
        SettlementCharge::create(['settlement_id' => $settlement->id, 'charge_name' => 'TDS', 'charge_type' => 'deduction', 'amount' => 400.00]);
        SettlementCharge::create(['settlement_id' => $settlement->id, 'charge_name' => 'Advance Paid', 'charge_type' => 'deduction', 'amount' => 50000.00]);
        SettlementCharge::create(['settlement_id' => $settlement->id, 'charge_name' => 'Lorry Freight', 'charge_type' => 'deduction', 'amount' => 8500.00]);

        VendorLedger::postTransaction([
            'vendor_id' => $vendor->id,
            'branch_id' => $branch->id,
            'transaction_date' => $invoice->invoice_date,
            'document_type' => 'invoice',
            'document_id' => $invoice->id,
            'document_number' => 'Invoice 68',
            'lot_number' => $invoice->lot_number,
            'description' => 'Original Invoice 68 - Rice Bran Grade II',
            'debit' => 0,
            'credit' => 433128.16,
            'created_by' => $user?->id,
        ]);

        VendorLedger::postTransaction([
            'vendor_id' => $vendor->id,
            'branch_id' => $branch->id,
            'transaction_date' => $settlement->settlement_date,
            'document_type' => 'settlement',
            'document_id' => $settlement->id,
            'document_number' => $settlement->settlement_number,
            'lot_number' => $settlement->lot_number,
            'description' => 'Settlement Bill - Rice Bran Grade II',
            'debit' => 0,
            'credit' => $settlement->net_payable,
            'created_by' => $user?->id,
        ]);

        $this->command?->info("Seeded " . count($vendors) . " vendors, " . count($products) . " products/items, 1 invoice, 1 settlement ({$settlement->settlement_number}).");
    }
}
