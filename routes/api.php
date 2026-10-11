<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BranchController;
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
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SettlementController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\VendorLedgerController;
use Illuminate\Support\Facades\Route;

// Public Auth routes
Route::post('/login', [AuthController::class, 'login']);

// Authenticated routes
Route::middleware(['auth:sanctum', 'company.scope'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Companies (multi-company switcher + admin CRUD)
    Route::get('/companies/all', [CompanyController::class, 'all']);
    Route::apiResource('companies', CompanyController::class)->except(['create', 'edit']);

    // Branches
    Route::apiResource('branches', BranchController::class);

    // Vendors
    Route::get('/vendors/search', [VendorController::class, 'search']);
    Route::post('/vendors/lookup-gstin', [VendorController::class, 'lookupGstin']);
    Route::apiResource('vendors', VendorController::class);

    // Products & Items
    Route::apiResource('products', ProductController::class);
    Route::apiResource('items', ItemController::class);
Route::apiResource('materials', MaterialController::class);

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
    Route::get('/inventory/stock', [InventoryController::class, 'stock']);
    Route::get('/inventory/ledger', [InventoryController::class, 'ledger']);
    Route::get('/inventory/low-stock', [InventoryController::class, 'lowStock']);
    Route::get('/inventory/dashboard-kpis', [InventoryController::class, 'dashboardKpis']);
    Route::post('/inventory/stock-in', [InventoryController::class, 'stockIn']);
    Route::post('/inventory/stock-out', [InventoryController::class, 'stockOut']);
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
    Route::post('/inward/{inward}/confirm', [InwardController::class, 'confirm'])->middleware('permission:inventory.stock_in');
    Route::post('/inward/{inward}/cancel', [InwardController::class, 'cancel'])->middleware('permission:inventory.stock_in');
    Route::middleware('permission:inventory.view')->group(function () {
        Route::apiResource('inward', InwardController::class)->except(['destroy']);
    });
    Route::post('/inward', [InwardController::class, 'store'])->middleware('permission:inventory.stock_in');

    // Outward / Dispatch (direct + mixed)
    Route::post('/outward/{outward}/confirm', [OutwardController::class, 'confirm'])->middleware('permission:inventory.stock_out');
    Route::post('/outward/{outward}/cancel', [OutwardController::class, 'cancel'])->middleware('permission:inventory.stock_out');
    Route::middleware('permission:inventory.view')->group(function () {
        Route::apiResource('outward', OutwardController::class)->except(['destroy']);
    });
    Route::post('/outward', [OutwardController::class, 'store'])->middleware('permission:inventory.stock_out');

    // Mixing / Production
    Route::post('/production/{production}/confirm', [ProductionController::class, 'confirm'])->middleware('permission:inventory.stock_in');
    Route::middleware('permission:inventory.view')->group(function () {
        Route::apiResource('production', ProductionController::class)->only(['index', 'store', 'show']);
    });

    // Reports
    Route::get('/reports/{type}', [ReportController::class, 'index']);
    Route::get('/reports/{type}/export', [ReportController::class, 'export']);

    // User Management & Roles
    Route::apiResource('users', UserController::class);
    Route::get('/roles', [RoleController::class, 'index']);

    // Settings
    Route::get('/settings', [SettingController::class, 'index']);
    Route::put('/settings', [SettingController::class, 'update']);
    Route::post('/settings/gst-rates', [SettingController::class, 'storeGstRate']);

    // Audit Logs
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show']);
});
