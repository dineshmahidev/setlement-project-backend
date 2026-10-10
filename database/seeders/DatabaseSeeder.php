<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\DebitNote;
use App\Models\DebitNoteItem;
use App\Models\DocumentSequence;
use App\Models\FinancialYear;
use App\Models\GstRate;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Settlement;
use App\Models\SettlementQualityTest;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorLedger;
use App\Services\SettlementCalculationService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        SettlementQualityTest::truncate();
        Settlement::truncate();
        DebitNoteItem::truncate();
        DebitNote::truncate();
        Invoice::truncate();
        VendorLedger::truncate();
        AuditLog::truncate();
        DocumentSequence::truncate();
        GstRate::truncate();
        FinancialYear::truncate();
        CompanySetting::truncate();
        Product::truncate();
        Vendor::truncate();
        Branch::truncate();
        User::truncate();
        Role::truncate();
        Permission::truncate();
        DB::table('role_permissions')->truncate();
        DB::table('user_branches')->truncate();
        DB::table('user_permissions')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // 1. Roles
        $superAdminRole = Role::create(['name' => 'super_admin', 'label' => 'Super Admin']);
        $adminRole = Role::create(['name' => 'admin', 'label' => 'Admin']);
        $managerRole = Role::create(['name' => 'branch_manager', 'label' => 'Branch Manager']);
        $staffRole = Role::create(['name' => 'staff', 'label' => 'Staff / Operator']);

        // 2. Permissions
        $modules = [
            'branches' => ['view', 'create', 'edit', 'delete', 'manage'],
            'vendors' => ['view', 'create', 'edit', 'delete', 'manage'],
            'products' => ['view', 'create', 'edit', 'delete', 'manage'],
            'invoices' => ['view', 'create', 'edit', 'cancel', 'manage'],
            'settlements' => ['view', 'create', 'edit', 'approve', 'cancel', 'pdf'],
            'debit_notes' => ['view', 'create', 'edit', 'approve', 'cancel', 'pdf'],
            'vendor_ledger' => ['view', 'adjust', 'export'],
            'reports' => ['view', 'export'],
            'users' => ['view', 'create', 'edit', 'delete', 'manage'],
            'settings' => ['view', 'edit', 'manage'],
            'audit' => ['view'],
        ];

        $allPermIds = [];
        foreach ($modules as $module => $actions) {
            foreach ($actions as $action) {
                $p = Permission::create([
                    'name' => "{$module}.{$action}",
                    'label' => ucwords("{$action} {$module}"),
                    'module' => $module,
                ]);
                $allPermIds[] = $p->id;
            }
        }

        // Assign permissions to roles
        $superAdminRole->permissions()->sync($allPermIds);
        $adminRole->permissions()->sync($allPermIds);

        // Branch Manager permissions
        $managerPerms = Permission::whereIn('module', ['vendors', 'products', 'invoices', 'settlements', 'debit_notes', 'vendor_ledger', 'reports'])->pluck('id');
        $managerRole->permissions()->sync($managerPerms);

        // Staff permissions
        $staffPerms = Permission::whereIn('name', [
            'vendors.view', 'vendors.create', 'products.view', 
            'invoices.view', 'invoices.create', 'settlements.view', 
            'settlements.create', 'settlements.pdf', 'debit_notes.view', 
            'debit_notes.pdf', 'vendor_ledger.view', 'reports.view'
        ])->pluck('id');
        $staffRole->permissions()->sync($staffPerms);

        // 3. Branches
        $branchHO = Branch::create([
            'branch_code' => 'BR-KNG',
            'branch_name' => 'Kangeyam Plant (Head Office)',
            'address' => 'SF No. 124/2, Kangeyam Road, Muthur',
            'city' => 'Kangeyam',
            'district' => 'Tirupur',
            'state' => 'Tamil Nadu',
            'pincode' => '638105',
            'gstin' => '33AABCS1234F1Z5',
            'phone' => '04257-241234',
            'email' => 'kangeyam@svmaagro.com',
            'status' => 'active',
        ]);

        $branchErode = Branch::create([
            'branch_code' => 'BR-ERD',
            'branch_name' => 'Erode Processing Unit',
            'address' => 'Bhavani Main Road, Perundurai',
            'city' => 'Erode',
            'district' => 'Erode',
            'state' => 'Tamil Nadu',
            'pincode' => '638052',
            'gstin' => '33AABCS1234F1Z5',
            'phone' => '0424-2256789',
            'email' => 'erode@svmaagro.com',
            'status' => 'active',
        ]);

        $branchThanjavur = Branch::create([
            'branch_code' => 'BR-TNJ',
            'branch_name' => 'Thanjavur Agro Depot',
            'address' => 'Kumbakonam Highway, Vallam',
            'city' => 'Thanjavur',
            'district' => 'Thanjavur',
            'state' => 'Tamil Nadu',
            'pincode' => '613403',
            'gstin' => '33AABCS1234F1Z5',
            'phone' => '04362-234567',
            'email' => 'thanjavur@svmaagro.com',
            'status' => 'active',
        ]);

        // 4. Users
        $adminUser = User::create([
            'name' => 'SVMA Administrator',
            'email' => 'admin@svmaagro.com',
            'password' => Hash::make('password'),
            'employee_id' => 'EMP-001',
            'mobile' => '9876543210',
            'role_id' => $superAdminRole->id,
            'default_branch_id' => $branchHO->id,
            'status' => 'active',
        ]);
        $adminUser->branches()->sync([$branchHO->id, $branchErode->id, $branchThanjavur->id]);

        $managerUser = User::create([
            'name' => 'Ramesh Kumar (Manager)',
            'email' => 'manager@svmaagro.com',
            'password' => Hash::make('password'),
            'employee_id' => 'EMP-002',
            'mobile' => '9876543211',
            'role_id' => $managerRole->id,
            'default_branch_id' => $branchHO->id,
            'status' => 'active',
        ]);
        $managerUser->branches()->sync([$branchHO->id]);

        $staffUser = User::create([
            'name' => 'Priya Staff',
            'email' => 'staff@svmaagro.com',
            'password' => Hash::make('password'),
            'employee_id' => 'EMP-003',
            'mobile' => '9876543212',
            'role_id' => $staffRole->id,
            'default_branch_id' => $branchHO->id,
            'status' => 'active',
        ]);
        $staffUser->branches()->sync([$branchHO->id]);

        // 5. Financial Years
        FinancialYear::create(['fy_code' => '2025-26', 'start_date' => '2025-04-01', 'end_date' => '2026-03-31', 'is_current' => false, 'status' => 'active']);
        FinancialYear::create(['fy_code' => '2026-27', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31', 'is_current' => true, 'status' => 'active']);
        FinancialYear::create(['fy_code' => '2027-28', 'start_date' => '2027-04-01', 'end_date' => '2028-03-31', 'is_current' => false, 'status' => 'active']);

        // 6. GST Rates
        GstRate::create(['name' => 'Agro Bran 5% (CGST 2.5% + SGST 2.5%)', 'cgst_rate' => 2.500, 'sgst_rate' => 2.500, 'igst_rate' => 5.000, 'is_default' => true, 'status' => 'active']);
        GstRate::create(['name' => 'Exempted / Zero GST (0%)', 'cgst_rate' => 0.000, 'sgst_rate' => 0.000, 'igst_rate' => 0.000, 'is_default' => false, 'status' => 'active']);
        GstRate::create(['name' => 'Standard GST 18%', 'cgst_rate' => 9.000, 'sgst_rate' => 9.000, 'igst_rate' => 18.000, 'is_default' => false, 'status' => 'active']);

        // 7. Company Settings
        CompanySetting::create([
            'company_name' => 'SVMA AGRO PRODUCTS PRIVATE LIMITED',
            'trade_name' => 'SVMA Agro Industries',
            'address' => 'SF No. 124/2, Kangeyam Road, Muthur',
            'city' => 'Kangeyam',
            'district' => 'Tirupur',
            'state' => 'Tamil Nadu',
            'pincode' => '638105',
            'gstin' => '33AABCS1234F1Z5',
            'cin' => 'U15400TN2020PTC123456',
            'pan' => 'AABCS1234F',
            'phone' => '+91 98765 43210',
            'email' => 'accounts@svmaagro.com',
            'website' => 'www.svmaagro.com',
            'bank_name' => 'State Bank of India',
            'account_number' => '398201948201',
            'ifsc_code' => 'SBIN0001234',
            'branch_bank' => 'Kangeyam Town Branch',
            'rounding_mode' => 'nearest_rupee',
            'settlement_prefix' => 'SB',
            'debit_note_prefix' => 'DN',
            'invoice_prefix' => 'INV',
            'terms_conditions' => "1. All quality settlements subject to authorized lab test approval.\n2. Weight certified by calibrated electronic weighbridge.\n3. Goods once accepted cannot be returned without inspection.",
            'authorized_signatory_title' => 'Authorised Signatory',
        ]);

        // 8. Products
        $p1 = Product::create(['product_name' => 'Rice Bran Grade II', 'product_code' => 'PROD-RB2', 'product_type' => 'Rice Bran', 'unit' => 'MT', 'default_rate' => 203.00, 'default_cgst' => 2.500, 'default_sgst' => 2.500, 'default_igst' => 5.000, 'status' => 'active']);
        $p2 = Product::create(['product_name' => 'Boiled Bran (Steam)', 'product_code' => 'PROD-BB1', 'product_type' => 'Boiled Bran', 'unit' => 'MT', 'default_rate' => 183.00, 'default_cgst' => 2.500, 'default_sgst' => 2.500, 'default_igst' => 5.000, 'status' => 'active']);
        $p3 = Product::create(['product_name' => 'Raw Rice Bran', 'product_code' => 'PROD-RRB', 'product_type' => 'Rice Bran', 'unit' => 'MT', 'default_rate' => 215.00, 'default_cgst' => 2.500, 'default_sgst' => 2.500, 'default_igst' => 5.000, 'status' => 'active']);
        $p4 = Product::create(['product_name' => 'De-oiled Rice Bran (DORB)', 'product_code' => 'PROD-DORB', 'product_type' => 'De-oiled Bran', 'unit' => 'MT', 'default_rate' => 140.00, 'default_cgst' => 2.500, 'default_sgst' => 2.500, 'default_igst' => 5.000, 'status' => 'active']);

        // 9. Vendors
        $v1 = Vendor::create([
            'vendor_code' => 'VEND-001',
            'vendor_name' => 'S.K. Sathana Traders',
            'business_name' => 'Sathana Agro Mill & Traders',
            'address' => '45/B, Mill Road, Sathy Main Road',
            'city' => 'Gobichettipalayam',
            'district' => 'Erode',
            'state' => 'Tamil Nadu',
            'pincode' => '638452',
            'gstin' => '33AABCS5544R1Z8',
            'pan' => 'AABCS5544R',
            'phone' => '9842155678',
            'email' => 'sathanatraders@gmail.com',
            'contact_person' => 'K. Sathasivam',
            'bank_name' => 'Canara Bank',
            'account_number' => '120938472910',
            'ifsc' => 'CNRB0001209',
            'opening_balance' => 0.00,
            'status' => 'active',
        ]);

        $v2 = Vendor::create([
            'vendor_code' => 'VEND-002',
            'vendor_name' => 'Sri Murugan Modern Rice Mill',
            'business_name' => 'Sri Murugan Agro Foods',
            'address' => '12, Trichy Bypass Road',
            'city' => 'Thanjavur',
            'district' => 'Thanjavur',
            'state' => 'Tamil Nadu',
            'pincode' => '613005',
            'gstin' => '33BCDPS1298K1ZQ',
            'pan' => 'BCDPS1298K',
            'phone' => '9443218765',
            'email' => 'muruganmill@yahoo.com',
            'contact_person' => 'M. Murugesan',
            'bank_name' => 'HDFC Bank',
            'account_number' => '50200012938471',
            'ifsc' => 'HDFC0000456',
            'opening_balance' => 0.00,
            'status' => 'active',
        ]);

        $v3 = Vendor::create([
            'vendor_code' => 'VEND-003',
            'vendor_name' => 'Lakshmi Rice Industries',
            'business_name' => 'Lakshmi Agro Processors',
            'address' => '88, Industrial Estate',
            'city' => 'Namakkal',
            'district' => 'Namakkal',
            'state' => 'Tamil Nadu',
            'pincode' => '637001',
            'gstin' => '33AADFL9921E1Z3',
            'pan' => 'AADFL9921E',
            'phone' => '9842765432',
            'email' => 'lakshmirice@gmail.com',
            'contact_person' => 'V. Lakshmanan',
            'bank_name' => 'Indian Overseas Bank',
            'account_number' => '019283746519',
            'ifsc' => 'IOBA0000192',
            'opening_balance' => 0.00,
            'status' => 'active',
        ]);

        // 10. Sample Workflow Demonstrating Exact Calculation from User Spec
        // Spec Example 1:
        // Final Weight = 15.095 MT
        // Oil Content = 13.10%
        // Final Unit Rate = 203
        // Weight KG = 15,095
        // Oil Qty = 1,977.445 KG
        // Bran Value = 4,01,421.34 (or nearest rupee 4,01,421)
        // Original Invoice Value = 4,12,503
        // Rebate = 11,082
        // CGST 2.5% = 277
        // SGST 2.5% = 277
        // Debit Note Total = 11,636

        $calcService = new SettlementCalculationService();

        // 10a. Original Invoice 68
        $inv1 = Invoice::create([
            'invoice_number' => 'INV-68',
            'invoice_date' => Carbon::now()->subDays(5)->toDateString(),
            'branch_id' => $branchHO->id,
            'vendor_id' => $v1->id,
            'product_id' => $p1->id,
            'lot_number' => 'LOT-2026-081',
            'vehicle_number' => 'TN 33 BM 4567',
            'net_weight_mt' => 15.095,
            'rate' => 26000.00,
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
            'remarks' => 'Original Vendor Delivery Invoice #68',
            'created_by' => $adminUser->id,
        ]);

        // Post original invoice to vendor ledger
        VendorLedger::postTransaction([
            'vendor_id' => $v1->id,
            'branch_id' => $branchHO->id,
            'transaction_date' => $inv1->invoice_date,
            'document_type' => 'invoice',
            'document_id' => $inv1->id,
            'document_number' => 'Invoice 68',
            'lot_number' => $inv1->lot_number,
            'description' => 'Original Invoice 68 - Rice Bran Grade II',
            'debit' => 0,
            'credit' => 433128.00,
            'created_by' => $adminUser->id,
        ]);

        // 10b. Settlement Bill SB/2026/BR-KNG/0001
        $calc1 = $calcService->calculate([
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
        ]);

        $settlementDate = Carbon::now()->subDays(4)->toDateString();
        $settlement1 = Settlement::create([
            'settlement_number' => 'SB/2026/BR-KNG/0001',
            'financial_year' => '2026-27',
            'branch_id' => $branchHO->id,
            'vendor_id' => $v1->id,
            'product_id' => $p1->id,
            'invoice_id' => $inv1->id,
            'lot_number' => 'LOT-2026-081',
            'bill_number' => '68',
            'bill_date' => $inv1->invoice_date,
            'settlement_date' => $settlementDate,
            'vehicle_number' => 'TN 33 BM 4567',
            'net_weight_mt' => 15.095,
            'jute_bag' => 0.000,
            'pp_bag' => 0.000,
            'final_weight_mt' => 15.095,
            'final_weight_kg' => 15095.00,
            'oil_content' => 13.10,
            'ffa' => 6.20,
            'ss' => 1.10,
            'fibre' => 9.50,
            'moisture' => 8.20,
            'iv' => 98.0,
            'calcium' => 0.45,
            'protein' => 14.50,
            'contract_unit_rate' => 203.00,
            'final_unit_rate' => 203.00,
            'oil_quantity_kg' => 1977.445,
            'bran_value' => 401421.00,
            'bag_amount' => 1200.00,
            'loading_charges' => 800.00,
            'unloading_charges' => 600.00,
            'tds' => 400.00,
            'advance_paid' => 50000.00,
            'lorry_freight' => 8500.00,
            'other_charges' => 0.00,
            'original_invoice_value' => 412503.00,
            'rebate_amount' => 11082.00,
            'subtotal_amount' => $calc1['subtotal_amount'],
            'is_interstate' => false,
            'cgst_rate' => 2.500,
            'sgst_rate' => 2.500,
            'igst_rate' => 0.000,
            'cgst_amount' => $calc1['cgst_amount'],
            'sgst_amount' => $calc1['sgst_amount'],
            'igst_amount' => 0.00,
            'total_amount' => $calc1['total_amount'],
            'net_payable' => $calc1['net_payable'],
            'status' => 'approved',
            'created_by' => $adminUser->id,
        ]);

        // QC tests
        SettlementQualityTest::create(['settlement_id' => $settlement1->id, 'parameter_name' => 'Oil Content', 'parameter_value' => 13.10]);
        SettlementQualityTest::create(['settlement_id' => $settlement1->id, 'parameter_name' => 'FFA', 'parameter_value' => 6.20]);
        SettlementQualityTest::create(['settlement_id' => $settlement1->id, 'parameter_name' => 'SS', 'parameter_value' => 1.10]);
        SettlementQualityTest::create(['settlement_id' => $settlement1->id, 'parameter_name' => 'Moisture', 'parameter_value' => 8.20]);

        // 10c. Debit Note DN/1019/26-27 (Exactly as user specified)
        $dn1 = DebitNote::create([
            'debit_note_number' => 'DN/1019/26-27',
            'financial_year' => '2026-27',
            'branch_id' => $branchHO->id,
            'vendor_id' => $v1->id,
            'settlement_id' => $settlement1->id,
            'invoice_id' => $inv1->id,
            'invoice_number' => '68',
            'invoice_date' => $inv1->invoice_date,
            'lot_number' => 'LOT-2026-081',
            'date' => Carbon::now()->subDays(3)->toDateString(),
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
            'remarks' => 'Auto generated Rebate Debit Note',
            'created_by' => $adminUser->id,
        ]);

        DebitNoteItem::create([
            'debit_note_id' => $dn1->id,
            'particulars' => 'Rebate',
            'amount' => 11082.00,
        ]);

        // Post Debit Note to Vendor Ledger
        VendorLedger::postTransaction([
            'vendor_id' => $v1->id,
            'branch_id' => $branchHO->id,
            'transaction_date' => $dn1->date,
            'document_type' => 'debit_note',
            'document_id' => $dn1->id,
            'document_number' => 'DN/1019/26-27',
            'lot_number' => 'LOT-2026-081',
            'description' => 'Rebate / Debit Note against Invoice 68',
            'debit' => 11636.00,
            'credit' => 0,
            'created_by' => $adminUser->id,
        ]);

        // Spec Example 2:
        // Final Weight = 22.800 MT
        // Oil = 25.52%
        // Rate = 183
        // Expected Bran Value = ₹10,64,796.48
        $calc2 = $calcService->calculate([
            'net_weight_mt' => 22.800,
            'final_weight_mt' => 22.800,
            'oil_content' => 25.52,
            'contract_unit_rate' => 183.00,
            'final_unit_rate' => 183.00,
            'rounding_mode' => 'two_decimal',
        ]);

        $settlement2 = Settlement::create([
            'settlement_number' => 'SB/2026/BR-ERD/0002',
            'financial_year' => '2026-27',
            'branch_id' => $branchErode->id,
            'vendor_id' => $v2->id,
            'product_id' => $p2->id,
            'lot_number' => 'LOT-2026-085',
            'bill_number' => 'MM-542',
            'bill_date' => Carbon::now()->subDays(2)->toDateString(),
            'settlement_date' => Carbon::now()->subDays(1)->toDateString(),
            'vehicle_number' => 'TN 28 AB 9081',
            'net_weight_mt' => 22.800,
            'final_weight_mt' => 22.800,
            'final_weight_kg' => 22800.00,
            'oil_content' => 25.52,
            'ffa' => 5.40,
            'ss' => 0.80,
            'fibre' => 8.20,
            'moisture' => 7.80,
            'contract_unit_rate' => 183.00,
            'final_unit_rate' => 183.00,
            'oil_quantity_kg' => 5818.560,
            'bran_value' => 1064796.48,
            'bag_amount' => 0.00,
            'loading_charges' => 0.00,
            'unloading_charges' => 0.00,
            'tds' => 1064.00,
            'advance_paid' => 200000.00,
            'lorry_freight' => 12000.00,
            'other_charges' => 0.00,
            'original_invoice_value' => 1064796.48,
            'rebate_amount' => 0.00,
            'subtotal_amount' => $calc2['subtotal_amount'],
            'is_interstate' => false,
            'cgst_rate' => 2.500,
            'sgst_rate' => 2.500,
            'igst_rate' => 0.000,
            'cgst_amount' => $calc2['cgst_amount'],
            'sgst_amount' => $calc2['sgst_amount'],
            'igst_amount' => 0.00,
            'total_amount' => $calc2['total_amount'],
            'net_payable' => $calc2['net_payable'],
            'status' => 'approved',
            'created_by' => $adminUser->id,
        ]);

        SettlementQualityTest::create(['settlement_id' => $settlement2->id, 'parameter_name' => 'Oil Content', 'parameter_value' => 25.52]);
        SettlementQualityTest::create(['settlement_id' => $settlement2->id, 'parameter_name' => 'FFA', 'parameter_value' => 5.40]);

        // Post settlement 2 to vendor ledger
        VendorLedger::postTransaction([
            'vendor_id' => $v2->id,
            'branch_id' => $branchErode->id,
            'transaction_date' => $settlement2->settlement_date,
            'document_type' => 'settlement',
            'document_id' => $settlement2->id,
            'document_number' => $settlement2->settlement_number,
            'lot_number' => $settlement2->lot_number,
            'description' => 'Settlement Bill - Boiled Bran (Steam)',
            'debit' => 0,
            'credit' => $settlement2->net_payable,
            'created_by' => $adminUser->id,
        ]);

        // 11. Initial Document Sequences
        DocumentSequence::create(['document_type' => 'settlement', 'prefix' => 'SB', 'current_number' => 2, 'financial_year' => '2026-27', 'branch_id' => $branchHO->id]);
        DocumentSequence::create(['document_type' => 'debit_note', 'prefix' => 'DN', 'current_number' => 1019, 'financial_year' => '2026-27', 'branch_id' => $branchHO->id]);
        DocumentSequence::create(['document_type' => 'invoice', 'prefix' => 'INV', 'current_number' => 68, 'financial_year' => '2026-27', 'branch_id' => $branchHO->id]);

        // Audit trail initial entry
        AuditLog::logAction('seeded', 'System', null, null, null, null, 'Initial database migration and seed data populated successfully');
    }
}
