<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DebitNoteController;
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
Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::get('/public/cms/{slug?}', [CompanyController::class, 'publicCms']);
Route::post('/public/enquiries', [EnquiryController::class, 'publicStore']);
Route::post('/public/track-visit', [EnquiryController::class, 'publicTrackVisit']);

// Authenticated routes
Route::middleware(['auth:sanctum', 'company.scope'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::get('/me', [AuthController::class, 'me']);

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('permission:dashboard.view');

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
    Route::middleware('permission:vendors.view')->group(function () {
        Route::get('/vendors/search', [VendorController::class, 'search']);
        Route::post('/vendors/lookup-gstin', [VendorController::class, 'lookupGstin']);
        Route::get('/vendors', [VendorController::class, 'index']);
        Route::get('/vendors/{vendor}', [VendorController::class, 'show']);
    });
    Route::post('/vendors', [VendorController::class, 'store'])->middleware('permission:vendors.create');
    Route::match(['put', 'patch'], '/vendors/{vendor}', [VendorController::class, 'update'])->middleware('permission:vendors.edit');
    Route::patch('/vendors/{vendor}/status', [VendorController::class, 'toggleStatus'])->middleware('permission:vendors.edit');
    Route::post('/vendors/{vendor}/toggle-status', [VendorController::class, 'toggleStatus'])->middleware('permission:vendors.edit');
    Route::delete('/vendors/{vendor}', [VendorController::class, 'destroy'])->middleware('permission:vendors.delete');

    // Products & Items & Units
    Route::middleware('permission:products.view')->group(function () {
        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/products/{product}', [ProductController::class, 'show']);
        Route::get('/items', [ItemController::class, 'index']);
        Route::get('/items/{item}', [ItemController::class, 'show']);
        Route::get('/materials', [MaterialController::class, 'index']);
        Route::get('/materials/{material}', [MaterialController::class, 'show']);
        Route::get('/units', [UnitController::class, 'index']);
        Route::get('/units/{unit}', [UnitController::class, 'show']);
        Route::get('/categories', [CategoryController::class, 'index']);
        Route::get('/categories/{category}', [CategoryController::class, 'show']);
    });
    Route::middleware('permission:products.create')->group(function () {
        Route::post('/products', [ProductController::class, 'store']);
        Route::post('/items', [ItemController::class, 'store']);
        Route::post('/materials', [MaterialController::class, 'store']);
        Route::post('/units', [UnitController::class, 'store']);
        Route::post('/categories', [CategoryController::class, 'store']);
    });
    Route::middleware('permission:products.edit')->group(function () {
        Route::match(['put', 'patch'], '/products/{product}', [ProductController::class, 'update']);
        Route::match(['put', 'patch'], '/items/{item}', [ItemController::class, 'update']);
        Route::match(['put', 'patch'], '/materials/{material}', [MaterialController::class, 'update']);
        Route::match(['put', 'patch'], '/units/{unit}', [UnitController::class, 'update']);
        Route::match(['put', 'patch'], '/categories/{category}', [CategoryController::class, 'update']);
    });
    Route::middleware('permission:products.delete')->group(function () {
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);
        Route::delete('/items/{item}', [ItemController::class, 'destroy']);
        Route::delete('/materials/{material}', [MaterialController::class, 'destroy']);
        Route::delete('/units/{unit}', [UnitController::class, 'destroy']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
    });

    // Invoices
    Route::middleware('permission:invoices.view')->group(function () {
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
    });
    Route::post('/invoices', [InvoiceController::class, 'store'])->middleware('permission:invoices.create');
    Route::match(['put', 'patch'], '/invoices/{invoice}', [InvoiceController::class, 'update'])->middleware('permission:invoices.edit');
    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->middleware('permission:invoices.edit');
    Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->middleware('permission:invoices.cancel');

    // Settlements
    Route::middleware('permission:settlements.view')->group(function () {
        Route::get('/settlements', [SettlementController::class, 'index']);
        Route::get('/settlements/{settlement}', [SettlementController::class, 'show']);
    });
    Route::post('/settlements', [SettlementController::class, 'store'])->middleware('permission:settlements.create');
    Route::post('/settlements/calculate', [SettlementController::class, 'calculate'])->middleware('permission:settlements.create');
    Route::match(['put', 'patch'], '/settlements/{settlement}', [SettlementController::class, 'update'])->middleware('permission:settlements.edit');
    Route::delete('/settlements/{settlement}', [SettlementController::class, 'destroy'])->middleware('permission:settlements.edit');
    Route::post('/settlements/{settlement}/cancel', [SettlementController::class, 'cancel'])->middleware('permission:settlements.cancel');
    Route::get('/settlements/{settlement}/pdf', [SettlementController::class, 'pdf'])->middleware('permission:settlements.pdf');

    // Debit Notes
    Route::middleware('permission:debit_notes.view')->group(function () {
        Route::get('/debit-notes', [DebitNoteController::class, 'index']);
        Route::get('/debit-notes/{debit_note}', [DebitNoteController::class, 'show']);
    });
    Route::post('/debit-notes', [DebitNoteController::class, 'store'])->middleware('permission:debit_notes.create');
    Route::match(['put', 'patch'], '/debit-notes/{debit_note}', [DebitNoteController::class, 'update'])->middleware('permission:debit_notes.edit');
    Route::delete('/debit-notes/{debit_note}', [DebitNoteController::class, 'destroy'])->middleware('permission:debit_notes.edit');
    Route::post('/debit-notes/{debit_note}/cancel', [DebitNoteController::class, 'cancel'])->middleware('permission:debit_notes.cancel');
    Route::get('/debit-notes/{debit_note}/pdf', [DebitNoteController::class, 'pdf'])->middleware('permission:debit_notes.pdf');

    // Vendor Ledger & Adjustments
    Route::get('/ledger', [VendorLedgerController::class, 'index'])->middleware('permission:vendor_ledger.view');
    Route::post('/ledger/adjustment', [VendorLedgerController::class, 'storeAdjustment'])->middleware('permission:vendor_ledger.adjust');
    Route::get('/ledger/pdf', [VendorLedgerController::class, 'exportPdf'])->middleware('permission:vendor_ledger.export');

    // Inventory Dashboard & Transactions
    Route::middleware('permission:inventory.view')->group(function () {
        Route::get('/inventory/party-summary', [InventoryController::class, 'partySummary']);
        Route::get('/inventory/party-summary/pdf', [InventoryController::class, 'partySummaryPdf']);
        Route::get('/inventory/stock', [InventoryController::class, 'stock']);
        Route::get('/inventory/stock/pdf', [InventoryController::class, 'stockPdf']);
        Route::get('/inventory/ledger', [InventoryController::class, 'ledger']);
        Route::get('/inventory/ledger/pdf', [InventoryController::class, 'ledgerPdf']);
        Route::get('/inventory/low-stock', [InventoryController::class, 'lowStock']);
        Route::get('/inventory/dashboard-kpis', [InventoryController::class, 'dashboardKpis']);
        Route::get('/inventory/transfers', [InventoryController::class, 'transfers']);
        Route::get('/inventory/transfers/pdf', [InventoryController::class, 'transfersPdf']);
        Route::get('/inventory/transfers/{transfer}', [InventoryController::class, 'showTransfer']);
        Route::get('/inventory/transfers/{transfer}/pdf', [InventoryController::class, 'singleTransferPdf']);
    });
    Route::post('/inventory/stock-in', [InventoryController::class, 'stockIn'])->middleware('permission:inventory.stock_in');
    Route::post('/inventory/stock-out', [InventoryController::class, 'stockOut'])->middleware('permission:inventory.stock_out');
    Route::put('/inventory/transfers/{transfer}', [InventoryController::class, 'updateTransfer'])->middleware('permission:inventory.transfer');
    Route::delete('/inventory/transfers/{transfer}', [InventoryController::class, 'destroyTransfer'])->middleware('permission:inventory.transfer');
    Route::post('/inventory/transfer', [InventoryController::class, 'transfer'])->middleware('permission:inventory.transfer');
    Route::post('/inventory/transfer-lot', [InventoryController::class, 'transferLot'])->middleware('permission:inventory.transfer');
    Route::post('/inventory/adjustment', [InventoryController::class, 'adjustment'])->middleware('permission:inventory.adjust');

// Grades removed (oil%-grade feature discontinued). Grade columns stay nullable/inert.

    // Lots / lot-wise stock
    Route::middleware('permission:inventory.view')->group(function () {
        Route::get('/lots/summary', [LotController::class, 'summary']);
        Route::get('/lots/pdf', [LotController::class, 'pdf']);
        Route::get('/lots/{lot}/movements/pdf', [LotController::class, 'movementsPdf']);
        Route::get('/lots', [LotController::class, 'index']);
        Route::get('/lots/{lot}', [LotController::class, 'show']);
    });
    Route::delete('/lots/{lot}', [LotController::class, 'destroy'])->middleware('permission:inventory.adjust');

    // Inward (multi-item stock receipt)
    Route::middleware('permission:inventory.view')->group(function () {
        Route::get('/inward-report-pdf', [InwardController::class, 'reportPdf']);
        Route::get('/inward/{inward}/pdf', [InwardController::class, 'pdf']);
        Route::get('/inward', [InwardController::class, 'index']);
        Route::get('/inward/{inward}', [InwardController::class, 'show']);
    });
    Route::post('/inward', [InwardController::class, 'store'])->middleware('permission:inventory.stock_in');
    Route::match(['put', 'patch'], '/inward/{inward}', [InwardController::class, 'update'])->middleware('permission:inventory.stock_in');
    Route::delete('/inward/{inward}', [InwardController::class, 'destroy'])->middleware('permission:inventory.stock_in');
    Route::post('/inward/{inward}/confirm', [InwardController::class, 'confirm'])->middleware('permission:inventory.stock_in');
    Route::post('/inward/{inward}/cancel', [InwardController::class, 'cancel'])->middleware('permission:inventory.stock_in');

    // Outward / Dispatch (direct + mixed)
    Route::middleware('permission:inventory.view')->group(function () {
        Route::get('/outward-report-pdf', [OutwardController::class, 'reportPdf']);
        Route::get('/outward/{outward}/pdf', [OutwardController::class, 'pdf']);
        Route::get('/outward', [OutwardController::class, 'index']);
        Route::get('/outward/{outward}', [OutwardController::class, 'show']);
    });
    Route::post('/outward', [OutwardController::class, 'store'])->middleware('permission:inventory.stock_out');
    Route::match(['put', 'patch'], '/outward/{outward}', [OutwardController::class, 'update'])->middleware('permission:inventory.stock_out');
    Route::delete('/outward/{outward}', [OutwardController::class, 'destroy'])->middleware('permission:inventory.stock_out');
    Route::post('/outward/{outward}/confirm', [OutwardController::class, 'confirm'])->middleware('permission:inventory.stock_out');
    Route::post('/outward/{outward}/cancel', [OutwardController::class, 'cancel'])->middleware('permission:inventory.stock_out');

    // Mixing / Production
    Route::middleware('permission:inventory.view')->group(function () {
        Route::get('/production/{production}/pdf', [ProductionController::class, 'pdf']);
        Route::get('/production', [ProductionController::class, 'index']);
        Route::get('/production/{production}', [ProductionController::class, 'show']);
    });
    Route::post('/production', [ProductionController::class, 'store'])->middleware('permission:inventory.stock_in');
    Route::match(['put', 'patch'], '/production/{production}', [ProductionController::class, 'update'])->middleware('permission:inventory.stock_in');
    Route::delete('/production/{production}', [ProductionController::class, 'destroy'])->middleware('permission:inventory.stock_in');
    Route::post('/production/{production}/confirm', [ProductionController::class, 'confirm'])->middleware('permission:inventory.stock_in');

    // Reports
    Route::get('/reports/{type}', [ReportController::class, 'index'])->middleware('permission:reports.view');
    Route::get('/reports/{type}/export', [ReportController::class, 'export'])->middleware('permission:reports.export');

    // User Management & Roles (permission based; role editing is Super Admin only)
    Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.view');
    Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:users.view');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.create');
    Route::put('/users/{user}', [UserController::class, 'update'])->middleware('permission:users.edit');
    Route::patch('/users/{user}', [UserController::class, 'update'])->middleware('permission:users.edit');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.delete');
    Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:users.view');

    // Confidential Modules - STRICTLY RESTRICTED TO SUPER ADMIN / MASTER ADMIN ONLY
    Route::middleware('super_admin')->group(function () {
        // Role permission editor
        Route::put('/roles/{role}', [RoleController::class, 'update']);

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
