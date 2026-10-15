<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DebitNoteController;
use App\Http\Controllers\GradeController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InwardController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\LotController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\OutwardController;
use App\Http\Controllers\ProductionController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\EnquiryController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SettlementController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\TrashController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\VendorLedgerController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::get('/public/cms/{slug?}', [CompanyController::class, 'publicCms']);
Route::post('/public/enquiries', [EnquiryController::class, 'publicStore']);
Route::post('/public/track-visit', [EnquiryController::class, 'publicTrackVisit']);

// Authenticated routes
Route::middleware(['auth:sanctum', 'company.scope'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Leads & Traffic Analytics
    Route::get('/enquiries', [EnquiryController::class, 'index']);
    Route::get('/enquiries/{enquiry}', [EnquiryController::class, 'show']);
    Route::patch('/enquiries/{enquiry}', [EnquiryController::class, 'update']);
    Route::delete('/enquiries/{enquiry}', [EnquiryController::class, 'destroy']);
    Route::get('/traffic-stats', [EnquiryController::class, 'getTrafficStats']);

    // Companies (multi-company switcher + admin CRUD)
    Route::get('/companies', [CompanyController::class, 'index']);
    Route::middleware('super_admin')->group(function () {
        Route::get('/companies/all', [CompanyController::class, 'all']);
        Route::get('/companies/{company}', [CompanyController::class, 'show']);
        Route::post('/companies', [CompanyController::class, 'store']);
        Route::put('/companies/{company}', [CompanyController::class, 'update']);
        Route::patch('/companies/{company}', [CompanyController::class, 'update']);
        Route::delete('/companies/{company}', [CompanyController::class, 'destroy']);
        Route::post('/companies/{company}/upload-signature', [CompanyController::class, 'uploadSignature']);
    });

    // Branches / Godowns (Listing allowed for dropdowns; CRUD restricted to Super Admin)
    Route::get('/branches', [BranchController::class, 'index']);
    Route::get('/branches/{branch}', [BranchController::class, 'show']);
    Route::middleware('super_admin')->group(function () {
        Route::post('/branches', [BranchController::class, 'store']);
        Route::put('/branches/{branch}', [BranchController::class, 'update']);
        Route::patch('/branches/{branch}', [BranchController::class, 'update']);
        Route::delete('/branches/{branch}', [BranchController::class, 'destroy']);
    });

    // Vendors
    Route::get('/vendors/search', [VendorController::class, 'search']);
    Route::post('/vendors/lookup-gstin', [VendorController::class, 'lookupGstin']);
    Route::apiResource('vendors', VendorController::class);

    // Products & Items & Units
    Route::apiResource('products', ProductController::class);
    Route::apiResource('items', ItemController::class);
    Route::apiResource('materials', MaterialController::class);
    Route::apiResource('units', UnitController::class);
    Route::apiResource('categories', CategoryController::class);

    // Invoices
    Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel']);
    Route::apiResource('invoices', InvoiceController::class);

    // Settlements
    Route::post('/settlements/calculate', [SettlementController::class, 'calculate']);
    Route::post('/settlements/{settlement}/cancel', [SettlementController::class, 'cancel']);
    Route::get('/settlements/{settlement}/pdf', [SettlementController::class, 'pdf']);
    Route::apiResource('settlements', SettlementController::class);

    // Debit Notes
    Route::post('/debit-notes/{debit_note}/cancel', [DebitNoteController::class, 'cancel']);
    Route::get('/debit-notes/{debit_note}/pdf', [DebitNoteController::class, 'pdf']);
    Route::apiResource('debit-notes', DebitNoteController::class);

    // Vendor Ledger & Adjustments
    Route::get('/ledger', [VendorLedgerController::class, 'index']);
    Route::post('/ledger/adjustment', [VendorLedgerController::class, 'storeAdjustment']);
    Route::get('/ledger/pdf', [VendorLedgerController::class, 'exportPdf']);

    // Inventory Dashboard & Transactions
    Route::get('/inventory/party-summary', [InventoryController::class, 'partySummary']);
    Route::get('/inventory/party-summary/pdf', [InventoryController::class, 'partySummaryPdf']);
    Route::get('/inventory/stock', [InventoryController::class, 'stock']);
    Route::get('/inventory/stock/pdf', [InventoryController::class, 'stockPdf']);
    Route::get('/inventory/ledger', [InventoryController::class, 'ledger']);
    Route::get('/inventory/ledger/pdf', [InventoryController::class, 'ledgerPdf']);
    Route::get('/inventory/low-stock', [InventoryController::class, 'lowStock']);
    Route::get('/inventory/dashboard-kpis', [InventoryController::class, 'dashboardKpis']);
    Route::post('/inventory/stock-in', [InventoryController::class, 'stockIn']);
    Route::post('/inventory/stock-out', [InventoryController::class, 'stockOut']);
    Route::get('/inventory/transfers', [InventoryController::class, 'transfers']);
    Route::get('/inventory/transfers/pdf', [InventoryController::class, 'transfersPdf']);
    Route::post('/inventory/transfer', [InventoryController::class, 'transfer']);
    Route::post('/inventory/transfer-lot', [InventoryController::class, 'transferLot'])->middleware('permission:inventory.transfer');
    Route::post('/inventory/adjustment', [InventoryController::class, 'adjustment']);

    // Grades (configurable oil% grade rules)
    Route::middleware('permission:inventory.view')->group(function () {
        Route::apiResource('grades', GradeController::class)->except(['create', 'edit']);
    });

    // Lots / lot-wise stock
    Route::middleware('permission:inventory.view')->group(function () {
        Route::get('/lots/summary', [LotController::class, 'summary']);
        Route::apiResource('lots', LotController::class)->only(['index', 'show']);
    });

    // Inward (multi-item stock receipt)
    Route::get('/inward-report-pdf', [InwardController::class, 'reportPdf']);
    Route::get('/inward/{inward}/pdf', [InwardController::class, 'pdf']);
    Route::post('/inward/{inward}/confirm', [InwardController::class, 'confirm'])->middleware('permission:inventory.stock_in');
    Route::post('/inward/{inward}/cancel', [InwardController::class, 'cancel'])->middleware('permission:inventory.stock_in');
    Route::middleware('permission:inventory.view')->group(function () {
        Route::apiResource('inward', InwardController::class);
    });
    Route::post('/inward', [InwardController::class, 'store'])->middleware('permission:inventory.stock_in');

    // Outward / Dispatch (direct + mixed)
    Route::get('/outward-report-pdf', [OutwardController::class, 'reportPdf']);
    Route::get('/outward/{outward}/pdf', [OutwardController::class, 'pdf']);
    Route::post('/outward/{outward}/confirm', [OutwardController::class, 'confirm'])->middleware('permission:inventory.stock_out');
    Route::post('/outward/{outward}/cancel', [OutwardController::class, 'cancel'])->middleware('permission:inventory.stock_out');
    Route::middleware('permission:inventory.view')->group(function () {
        Route::apiResource('outward', OutwardController::class);
    });
    Route::post('/outward', [OutwardController::class, 'store'])->middleware('permission:inventory.stock_out');

    // Mixing / Production
    Route::get('/production/{production}/pdf', [ProductionController::class, 'pdf']);
    Route::post('/production/{production}/confirm', [ProductionController::class, 'confirm'])->middleware('permission:inventory.stock_in');
    Route::middleware('permission:inventory.view')->group(function () {
        Route::apiResource('production', ProductionController::class)->only(['index', 'store', 'show', 'destroy']);
    });

    // Reports
    Route::get('/reports/{type}', [ReportController::class, 'index']);
    Route::get('/reports/{type}/export', [ReportController::class, 'export']);

    // User Management & Roles (Accessible by Super Admin and Admin)
    Route::middleware('admin_or_super')->group(function () {
        Route::apiResource('users', UserController::class);
        Route::get('/roles', [RoleController::class, 'index']);
    });

    // Confidential Modules - STRICTLY RESTRICTED TO SUPER ADMIN / MASTER ADMIN ONLY
    Route::middleware('super_admin')->group(function () {
        // System Settings
        Route::get('/settings', [SettingController::class, 'index']);
        Route::put('/settings', [SettingController::class, 'update']);
        Route::post('/settings/upload-signature', [SettingController::class, 'uploadSignature']);
        Route::post('/settings/gst-rates', [SettingController::class, 'storeGstRate']);

        // Audit Trail Logs
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show']);

        // Trash (Soft Delete management)
        Route::get('/trash', [TrashController::class, 'index']);
        Route::post('/trash/{type}/{id}/restore', [TrashController::class, 'restore']);
        Route::delete('/trash/{type}/{id}/force-delete', [TrashController::class, 'forceDelete']);
        Route::post('/trash/empty', [TrashController::class, 'emptyTrash']);
    });
});
