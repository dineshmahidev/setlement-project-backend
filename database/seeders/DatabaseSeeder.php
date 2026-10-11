<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
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
        DB::table('user_companies')->truncate();
        Company::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // 1. Roles
        $superAdminRole = Role::create(['name' => 'super_admin', 'label' => 'Super Admin']);
        $adminRole = Role::create(['name' => 'admin', 'label' => 'Admin']);
        $managerRole = Role::create(['name' => 'branch_manager', 'label' => 'Branch Manager']);
        $staffRole = Role::create(['name' => 'staff', 'label' => 'Staff / Operator']);

        // 2. Permissions
        $modules = [
            'companies' => ['view', 'manage'],
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

        $superAdminRole->permissions()->sync($allPermIds);
        $adminRole->permissions()->sync($allPermIds);

        $managerPerms = Permission::whereIn('module', ['companies', 'vendors', 'products', 'invoices', 'settlements', 'debit_notes', 'vendor_ledger', 'reports'])->pluck('id');
        $managerRole->permissions()->sync($managerPerms);

        $staffPerms = Permission::whereIn('name', [
            'vendors.view', 'vendors.create', 'products.view',
            'invoices.view', 'invoices.create', 'settlements.view',
            'settlements.create', 'settlements.pdf', 'debit_notes.view',
            'debit_notes.pdf', 'vendor_ledger.view', 'reports.view',
        ])->pluck('id');
        $staffRole->permissions()->sync($staffPerms);

        // 3. Companies + their branches
        $this->call(CompanySeeder::class);

        $laxmi = Company::where('code', 'laxmi')->firstOrFail();
        $sm = Company::where('code', 'sm')->firstOrFail();
        $shriee = Company::where('code', 'shriee')->firstOrFail();

        $laxmiHq = Branch::where('branch_code', 'BR-LT01')->firstOrFail();
        $laxmiBazaar = Branch::where('branch_code', 'BR-LT02')->firstOrFail();
        $smHq = Branch::where('branch_code', 'BR-SM01')->firstOrFail();
        $shrieeHq = Branch::where('branch_code', 'BR-SF01')->firstOrFail();

        // 4. Users
        $adminUser = User::create([
            'name' => 'Laxmi Administrator',
            'email' => 'admin@laxmitraders.com',
            'password' => Hash::make('password'),
            'employee_id' => 'EMP-001',
            'mobile' => '9876543210',
            'role_id' => $superAdminRole->id,
            'default_branch_id' => $laxmiHq->id,
            'status' => 'active',
        ]);
        $adminUser->branches()->sync([$laxmiHq->id, $laxmiBazaar->id, $smHq->id, $shrieeHq->id]);
        $adminUser->companies()->sync([$laxmi->id, $sm->id, $shriee->id]);

        $managerUser = User::create([
            'name' => 'Ramesh Kumar (Manager)',
            'email' => 'manager@laxmitraders.com',
            'password' => Hash::make('password'),
            'employee_id' => 'EMP-002',
            'mobile' => '9876543211',
            'role_id' => $managerRole->id,
            'default_branch_id' => $laxmiHq->id,
            'status' => 'active',
        ]);
        $managerUser->branches()->sync([$laxmiHq->id, $laxmiBazaar->id]);
        $managerUser->companies()->sync([$laxmi->id]);

        $staffUser = User::create([
            'name' => 'Priya Staff',
            'email' => 'staff@laxmitraders.com',
            'password' => Hash::make('password'),
            'employee_id' => 'EMP-003',
            'mobile' => '9876543212',
            'role_id' => $staffRole->id,
            'default_branch_id' => $laxmiHq->id,
            'status' => 'active',
        ]);
        $staffUser->branches()->sync([$laxmiHq->id]);
        $staffUser->companies()->sync([$laxmi->id]);

        $smManager = User::create([
            'name' => 'S M Manager',
            'email' => 'manager@smtraders.com',
            'password' => Hash::make('password'),
            'employee_id' => 'EMP-101',
            'mobile' => '9876543213',
            'role_id' => $managerRole->id,
            'default_branch_id' => $smHq->id,
            'status' => 'active',
        ]);
        $smManager->branches()->sync([$smHq->id]);
        $smManager->companies()->sync([$sm->id]);

        $shrieeManager = User::create([
            'name' => 'Shriee Manager',
            'email' => 'manager@shrieefeeds.com',
            'password' => Hash::make('password'),
            'employee_id' => 'EMP-201',
            'mobile' => '9876543214',
            'role_id' => $managerRole->id,
            'default_branch_id' => $shrieeHq->id,
            'status' => 'active',
        ]);
        $shrieeManager->branches()->sync([$shrieeHq->id]);
        $shrieeManager->companies()->sync([$shriee->id]);

        // 5. Financial Years
        FinancialYear::create(['fy_code' => '2025-26', 'start_date' => '2025-04-01', 'end_date' => '2026-03-31', 'is_current' => false, 'status' => 'active']);
        FinancialYear::create(['fy_code' => '2026-27', 'start_date' => '2026-04-01', 'end_date' => '2027-03-31', 'is_current' => true, 'status' => 'active']);
        FinancialYear::create(['fy_code' => '2027-28', 'start_date' => '2027-04-01', 'end_date' => '2028-03-31', 'is_current' => false, 'status' => 'active']);

        // 6. GST Rates
        GstRate::create(['name' => 'Agro Bran 5% (CGST 2.5% + SGST 2.5%)', 'cgst_rate' => 2.500, 'sgst_rate' => 2.500, 'igst_rate' => 5.000, 'is_default' => true, 'status' => 'active']);
        GstRate::create(['name' => 'Exempted / Zero GST (0%)', 'cgst_rate' => 0.000, 'sgst_rate' => 0.000, 'igst_rate' => 0.000, 'is_default' => false, 'status' => 'active']);
        GstRate::create(['name' => 'Standard GST 18%', 'cgst_rate' => 9.000, 'sgst_rate' => 9.000, 'igst_rate' => 18.000, 'is_default' => false, 'status' => 'active']);

        // 7. Products (company scoped)
        $p1 = Product::create(['company_id' => $laxmi->id, 'product_name' => 'Rice Bran Grade II', 'product_code' => 'PROD-RB2', 'product_type' => 'Rice Bran', 'unit' => 'MT', 'default_rate' => 203.00, 'default_cgst' => 2.500, 'default_sgst' => 2.500, 'default_igst' => 5.000, 'status' => 'active']);
        $p2 = Product::create(['company_id' => $laxmi->id, 'product_name' => 'Boiled Bran (Steam)', 'product_code' => 'PROD-BB1', 'product_type' => 'Boiled Bran', 'unit' => 'MT', 'default_rate' => 183.00, 'default_cgst' => 2.500, 'default_sgst' => 2.500, 'default_igst' => 5.000, 'status' => 'active']);
        $p3 = Product::create(['company_id' => $laxmi->id, 'product_name' => 'Raw Rice Bran', 'product_code' => 'PROD-RRB', 'product_type' => 'Rice Bran', 'unit' => 'MT', 'default_rate' => 215.00, 'default_cgst' => 2.500, 'default_sgst' => 2.500, 'default_igst' => 5.000, 'status' => 'active']);
        $p4 = Product::create(['company_id' => $laxmi->id, 'product_name' => 'De-oiled Rice Bran (DORB)', 'product_code' => 'PROD-DORB', 'product_type' => 'De-oiled Bran', 'unit' => 'MT', 'default_rate' => 140.00, 'default_cgst' => 2.500, 'default_sgst' => 2.500, 'default_igst' => 5.000, 'status' => 'active']);

        $pSm = Product::create(['company_id' => $sm->id, 'product_name' => 'Rice Bran (Trading)', 'product_code' => 'PROD-SM-RB', 'product_type' => 'Rice Bran', 'unit' => 'MT', 'default_rate' => 198.00, 'default_cgst' => 2.500, 'default_sgst' => 2.500, 'default_igst' => 5.000, 'status' => 'active']);
        Product::create(['company_id' => $sm->id, 'product_name' => 'Oil Cake', 'product_code' => 'PROD-SM-OC', 'product_type' => 'Oil Cake', 'unit' => 'MT', 'default_rate' => 320.00, 'default_cgst' => 2.500, 'default_sgst' => 2.500, 'default_igst' => 5.000, 'status' => 'active']);

        Product::create(['company_id' => $shriee->id, 'product_name' => 'Cattle Feed Mash', 'product_code' => 'PROD-SF-CFM', 'product_type' => 'Cattle Feed', 'unit' => 'MT', 'default_rate' => 285.00, 'default_cgst' => 2.500, 'default_sgst' => 2.500, 'default_igst' => 5.000, 'status' => 'active']);
        Product::create(['company_id' => $shriee->id, 'product_name' => 'Poultry Layer Mash', 'product_code' => 'PROD-SF-PLM', 'product_type' => 'Poultry Feed', 'unit' => 'MT', 'default_rate' => 340.00, 'default_cgst' => 2.500, 'default_sgst' => 2.500, 'default_igst' => 5.000, 'status' => 'active']);

        // 8. Vendors (company scoped)
        $v1 = Vendor::create([
            'company_id' => $laxmi->id,
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
            'company_id' => $laxmi->id,
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

        Vendor::create([
            'company_id' => $laxmi->id,
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

        Vendor::create([
            'company_id' => $sm->id,
            'vendor_code' => 'VEND-101',
            'vendor_name' => 'Ariyamangalam Traders',
            'business_name' => 'Ariyamangalam Rice Traders',
            'address' => 'Ariyamangalam Main Road',
            'city' => 'Tiruchirappalli',
            'district' => 'Tiruchirappalli',
            'state' => 'Tamil Nadu',
            'pincode' => '620010',
            'gstin' => '33ABCDE1234F1Z1',
            'pan' => 'ABCDE1234F',
            'phone' => '9842000001',
            'email' => 'ariyamangalamtraders@example.com',
            'contact_person' => 'R. Karthik',
            'bank_name' => 'Canara Bank',
            'account_number' => '110022334455',
            'ifsc' => 'CNRB0001100',
            'opening_balance' => 0.00,
            'status' => 'active',
        ]);

        Vendor::create([
            'company_id' => $shriee->id,
            'vendor_code' => 'VEND-201',
            'vendor_name' => 'Manapparai Feed Traders',
            'business_name' => 'Manapparai Cattle Feed',
            'address' => 'Trichy Main Road, Manapparai',
            'city' => 'Manapparai',
            'district' => 'Tiruchirappalli',
            'state' => 'Tamil Nadu',
            'pincode' => '621306',
            'gstin' => '33FGHIJ5678K1Z2',
            'pan' => 'FGHIJ5678K',
            'phone' => '9842000002',
            'email' => 'manapparaifeed@example.com',
            'contact_person' => 'S. Suresh',
            'bank_name' => 'Indian Bank',
            'account_number' => '334455667788',
            'ifsc' => 'IDIB0000334',
            'opening_balance' => 0.00,
            'status' => 'active',
        ]);

        // 9. Sample workflow (Laxmi Traders)
        $calcService = new SettlementCalculationService();

        $inv1 = Invoice::create([
            'invoice_number' => 'INV-68',
            'invoice_date' => Carbon::now()->subDays(5)->toDateString(),
            'branch_id' => $laxmiHq->id,
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

        VendorLedger::postTransaction([
            'vendor_id' => $v1->id,
            'branch_id' => $laxmiHq->id,
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
            'settlement_number' => 'SB/2026/BR-LT01/0001',
            'financial_year' => '2026-27',
            'branch_id' => $laxmiHq->id,
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

        SettlementQualityTest::create(['settlement_id' => $settlement1->id, 'parameter_name' => 'Oil Content', 'parameter_value' => 13.10]);
        SettlementQualityTest::create(['settlement_id' => $settlement1->id, 'parameter_name' => 'FFA', 'parameter_value' => 6.20]);
        SettlementQualityTest::create(['settlement_id' => $settlement1->id, 'parameter_name' => 'SS', 'parameter_value' => 1.10]);
        SettlementQualityTest::create(['settlement_id' => $settlement1->id, 'parameter_name' => 'Moisture', 'parameter_value' => 8.20]);

        $dn1 = DebitNote::create([
            'debit_note_number' => 'DN/1019/26-27',
            'financial_year' => '2026-27',
            'branch_id' => $laxmiHq->id,
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

        VendorLedger::postTransaction([
            'vendor_id' => $v1->id,
            'branch_id' => $laxmiHq->id,
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

        $calc2 = $calcService->calculate([
            'net_weight_mt' => 22.800,
            'final_weight_mt' => 22.800,
            'oil_content' => 25.52,
            'contract_unit_rate' => 183.00,
            'final_unit_rate' => 183.00,
            'rounding_mode' => 'two_decimal',
        ]);

        $settlement2 = Settlement::create([
            'settlement_number' => 'SB/2026/BR-LT02/0002',
            'financial_year' => '2026-27',
            'branch_id' => $laxmiBazaar->id,
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

        VendorLedger::postTransaction([
            'vendor_id' => $v2->id,
            'branch_id' => $laxmiBazaar->id,
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

        // 10. Initial Document Sequences
        DocumentSequence::create(['document_type' => 'settlement', 'prefix' => $laxmi->settlement_prefix, 'current_number' => 2, 'financial_year' => '2026-27', 'branch_id' => $laxmiHq->id]);
        DocumentSequence::create(['document_type' => 'debit_note', 'prefix' => 'DN', 'current_number' => 1019, 'financial_year' => '2026-27', 'branch_id' => $laxmiHq->id]);
        DocumentSequence::create(['document_type' => 'invoice', 'prefix' => 'INV', 'current_number' => 68, 'financial_year' => '2026-27', 'branch_id' => $laxmiHq->id]);
        DocumentSequence::create(['document_type' => 'settlement', 'prefix' => $sm->settlement_prefix, 'current_number' => 0, 'financial_year' => '2026-27', 'branch_id' => $smHq->id]);
        DocumentSequence::create(['document_type' => 'settlement', 'prefix' => $shriee->settlement_prefix, 'current_number' => 0, 'financial_year' => '2026-27', 'branch_id' => $shrieeHq->id]);

        AuditLog::logAction('seeded', 'System', null, null, null, null, 'Initial database migration and seed data populated successfully');
    }
}
