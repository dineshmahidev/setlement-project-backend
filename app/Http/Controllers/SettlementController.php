<?php

namespace App\Http\Controllers;

use App\Http\Requests\SettlementRequest;
use App\Models\AuditLog;
use App\Models\DebitNote;
use App\Models\DebitNoteItem;
use App\Models\DocumentSequence;
use App\Models\FinancialYear;
use App\Models\Settlement;
use App\Models\SettlementCharge;
use App\Models\SettlementQualityTest;
use App\Models\VendorLedger;
use App\Services\PdfService;
use App\Services\SettlementCalculationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettlementController extends Controller
{
    protected $calcService;
    protected $pdfService;

    public function __construct(SettlementCalculationService $calcService, PdfService $pdfService)
    {
        $this->calcService = $calcService;
        $this->pdfService = $pdfService;
    }

    protected function authorizeSettlementAccess(?Request $request = null): void
    {
        $user = ($request ?? request())->user();
        if ($user && ($user->isBranchManager() || $user->isStaff()) && ! $user->isSuperAdmin() && ! $user->isAdmin()) {
            abort(403, 'Unauthorized: Branch Manager and Staff roles are restricted to the Inventory section only.');
        }
    }

    /**
     * 404 when the settlement's branch belongs to another company.
     * (guardBranchOwnership is a no-op when allowed_branch_ids is null.)
     */
    protected function guardSettlementCompany(Settlement $settlement): void
    {
        $companyId = $this->activeCompanyId();
        if ($companyId === null) {
            return; // no company context (super admin global view)
        }

        $branchCompanyId = $settlement->branch?->company_id;
        if ($branchCompanyId === null || (int) $branchCompanyId !== $companyId) {
            abort(404, 'Not found.');
        }
    }

    /**
     * Instant calculation endpoint for frontend preview and validation
     */
    public function calculate(Request $request)
    {
        $this->authorizeSettlementAccess($request);
        $data = $request->all();
        $result = $this->calcService->calculate($data);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function index(Request $request)
    {
        $this->authorizeSettlementAccess($request);
        $companyId = $this->activeCompanyId();
        $query = Settlement::with(['branch.company', 'vendor', 'product', 'invoice', 'debitNotes', 'creator', 'items.product']);

        if (! $request->filled('vendor_id')) {
            $query->whereHas('vendor', function ($vq) {
                $vq->where('status', 'active');
            });
        }

        if ($companyId) {
            $query->whereHas('branch', function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            });
        } else {
            $allowedCompanyIds = request('allowed_company_ids');
            if (is_array($allowedCompanyIds)) {
                $query->whereHas('branch', function ($q) use ($allowedCompanyIds) {
                    $q->whereIn('company_id', $allowedCompanyIds);
                });
            }
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('from_date') && $request->filled('to_date')) {
            $query->whereBetween('settlement_date', [$request->from_date, $request->to_date]);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('settlement_number', 'like', "%{$search}%")
                  ->orWhere('lot_number', 'like', "%{$search}%")
                  ->orWhere('bill_number', 'like', "%{$search}%")
                  ->orWhere('vehicle_number', 'like', "%{$search}%")
                  ->orWhereHas('vendor', function ($vq) use ($search) {
                      $vq->where('vendor_name', 'like', "%{$search}%");
                  });
            });
        }

        $settlements = $query->orderBy('settlement_date', 'desc')->orderBy('id', 'desc')->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $settlements,
        ]);
    }

    public function store(SettlementRequest $request)
    {
        $this->authorizeSettlementAccess($request);
        $validated = $request->validated();

        $companyId = $this->activeCompanyId();

        // Settlements are company-level: If branch_id is provided, verify it belongs to active company
        if (! empty($validated['branch_id']) && $companyId) {
            $belongsToCompany = \App\Models\Branch::where('id', $validated['branch_id'])
                ->where('company_id', $companyId)
                ->exists();
            if (! $belongsToCompany) {
                $validated['branch_id'] = null;
            }
        }

        // Auto-resolve branch for active company if not specified
        if (empty($validated['branch_id'])) {
            $branch = null;
            if ($companyId) {
                $branch = \App\Models\Branch::where('company_id', $companyId)->first();
            }
            if (! $branch) {
                $branch = \App\Models\Branch::first();
            }
            if (! $branch && $companyId) {
                $company = \App\Models\Company::find($companyId);
                $branch = \App\Models\Branch::create([
                    'company_id' => $companyId,
                    'branch_code' => 'HQ',
                    'branch_name' => ($company?->company_name ?? 'Company') . ' - Head Office',
                    'status' => 'active',
                ]);
            }
            $validated['branch_id'] = $branch?->id;
        }

        $settlement = DB::transaction(function () use ($validated, $request) {
            // 1. Recalculate everything on backend via Service class
            $calc = $this->calcService->calculate($validated);

            $branchId = $validated['branch_id'];
            $settlementDate = $validated['settlement_date'];
            $fy = FinancialYear::getFinancialYearForDate($settlementDate);

            // 2. Generate Number if not present
            $settlementNumber = $validated['settlement_number'] ?? null;
            if (empty($settlementNumber)) {
                $settlementNumber = DocumentSequence::getNextNumber('settlement', $branchId, $settlementDate);
            }

            // 3. Assemble full settlement model payload
            $modelData = array_merge($validated, [
                'settlement_number' => $settlementNumber,
                'settlement_type' => $validated['settlement_type'] ?? ($calc['settlement_type'] ?? 'single'),
                'financial_year' => $fy,
                'final_weight_mt' => $calc['final_weight_mt'],
                'final_weight_kg' => $calc['final_weight_kg'],
                'oil_quantity_kg' => $calc['oil_quantity_kg'],
                'bran_value' => $calc['bran_value'],
                'bag_amount' => $calc['bag_amount'],
                'loading_charges' => $calc['loading_charges'],
                'unloading_charges' => $calc['unloading_charges'],
                'tds_rate' => $calc['tds_rate'],
                'tds' => $calc['tds'],
                'advance_paid' => $calc['advance_paid'],
                'lorry_freight' => $calc['lorry_freight'],
                'other_charges' => $calc['other_charges'],
                'original_invoice_value' => $calc['original_invoice_value'],
                'rebate_amount' => $calc['rebate_amount'],
                'subtotal_amount' => $calc['subtotal_amount'],
                'is_interstate' => $calc['is_interstate'],
                'cgst_rate' => $calc['cgst_rate'],
                'sgst_rate' => $calc['sgst_rate'],
                'igst_rate' => $calc['igst_rate'],
                'cgst_amount' => $calc['cgst_amount'],
                'sgst_amount' => $calc['sgst_amount'],
                'igst_amount' => $calc['igst_amount'],
                'total_amount' => $calc['total_amount'],
                'net_payable' => $calc['net_payable'],
                'status' => 'approved',
                'created_by' => auth()->id(),
            ]);

            $settlement = Settlement::create($modelData);

            // 3.1 Save Settlement Items if multiple
            if (($modelData['settlement_type'] ?? 'single') === 'multiple' && !empty($calc['items'])) {
                foreach ($calc['items'] as $it) {
                    \App\Models\SettlementItem::create([
                        'settlement_id' => $settlement->id,
                        'product_id' => $it['product_id'] ?? null,
                        'lot_number' => $it['lot_number'] ?? null,
                        'net_weight_mt' => $it['net_weight_mt'],
                        'jute_bag' => $it['jute_bag'],
                        'pp_bag' => $it['pp_bag'],
                        'final_weight_mt' => $it['final_weight_mt'],
                        'final_weight_kg' => $it['final_weight_kg'],
                        'oil_content' => $it['oil_content'],
                        'ffa' => $it['ffa'],
                        'ss' => $it['ss'],
                        'fibre' => $it['fibre'],
                        'moisture' => $it['moisture'],
                        'contract_unit_rate' => $it['contract_unit_rate'],
                        'final_unit_rate' => $it['final_unit_rate'],
                        'oil_quantity_kg' => $it['oil_quantity_kg'],
                        'bran_value' => $it['bran_value'],
                        'bag_amount' => $it['bag_amount'],
                        'remarks' => $it['remarks'] ?? null,
                        'sort_order' => $it['sort_order'] ?? 0,
                    ]);
                }
            }

            // 4. Save Quality Test Breakdown
            $qcParams = [
                'Oil Content' => $validated['oil_content'] ?? null,
                'FFA' => $validated['ffa'] ?? null,
                'SS' => $validated['ss'] ?? null,
                'Fibre' => $validated['fibre'] ?? null,
                'Moisture' => $validated['moisture'] ?? null,
                'IV' => $validated['iv'] ?? null,
                'Calcium' => $validated['calcium'] ?? null,
                'Protein' => $validated['protein'] ?? null,
            ];
            foreach ($qcParams as $name => $val) {
                if ($val !== null) {
                    SettlementQualityTest::create([
                        'settlement_id' => $settlement->id,
                        'parameter_name' => $name,
                        'parameter_value' => $val,
                    ]);
                }
            }

            // 5. Save Charges Breakdown
            if ($calc['bag_amount'] > 0) {
                SettlementCharge::create(['settlement_id' => $settlement->id, 'charge_name' => 'Bag Amount', 'charge_type' => 'addition', 'amount' => $calc['bag_amount']]);
            }
            if ($calc['loading_charges'] > 0) {
                SettlementCharge::create(['settlement_id' => $settlement->id, 'charge_name' => 'Loading Charges', 'charge_type' => 'addition', 'amount' => $calc['loading_charges']]);
            }
            if ($calc['unloading_charges'] > 0) {
                SettlementCharge::create(['settlement_id' => $settlement->id, 'charge_name' => 'Unloading Charges', 'charge_type' => 'deduction', 'amount' => $calc['unloading_charges']]);
            }
            if ($calc['tds'] > 0) {
                SettlementCharge::create(['settlement_id' => $settlement->id, 'charge_name' => 'TDS Deduction', 'charge_type' => 'deduction', 'amount' => $calc['tds']]);
            }
            if ($calc['advance_paid'] > 0) {
                SettlementCharge::create(['settlement_id' => $settlement->id, 'charge_name' => 'Advance Paid', 'charge_type' => 'deduction', 'amount' => $calc['advance_paid']]);
            }
            if ($calc['lorry_freight'] > 0) {
                SettlementCharge::create(['settlement_id' => $settlement->id, 'charge_name' => 'Lorry Freight', 'charge_type' => 'deduction', 'amount' => $calc['lorry_freight']]);
            }

            // 6. Automatic Debit or Credit Note Generation for Rebate / Bonus if original invoice is provided
            if ($calc['rebate_amount'] > 0 || ($calc['note_amount'] ?? 0) > 0) {
                $dnData = $calc['debit_note'];
                $noteType = $dnData['transaction_type'] ?? ($calc['note_type'] ?? 'debit');
                $docType = ($noteType === 'credit') ? 'credit_note' : 'debit_note';
                $dnNumber = DocumentSequence::getNextNumber($docType, $branchId, $settlementDate);

                $reason = ($noteType === 'credit')
                    ? 'Quality Premium / Price Credit on Oil Content Settlement'
                    : 'Rebate on Oil Content Settlement';

                $debitNote = DebitNote::create([
                    'debit_note_number' => $dnNumber,
                    'financial_year' => $fy,
                    'branch_id' => $branchId,
                    'vendor_id' => $settlement->vendor_id,
                    'settlement_id' => $settlement->id,
                    'invoice_id' => $settlement->invoice_id,
                    'invoice_number' => $settlement->bill_number ?? ($settlement->invoice ? $settlement->invoice->invoice_number : null),
                    'invoice_date' => $settlement->bill_date ?? ($settlement->invoice ? $settlement->invoice->invoice_date : null),
                    'lot_number' => $settlement->lot_number,
                    'date' => $settlementDate,
                    'reason' => $reason,
                    'transaction_type' => $noteType,
                    'taxable_amount' => $dnData['taxable_amount'],
                    'is_interstate' => $calc['is_interstate'],
                    'cgst_rate' => $dnData['cgst_rate'],
                    'sgst_rate' => $dnData['sgst_rate'],
                    'igst_rate' => $dnData['igst_rate'],
                    'cgst_amount' => $dnData['cgst_amount'],
                    'sgst_amount' => $dnData['sgst_amount'],
                    'igst_amount' => $dnData['igst_amount'],
                    'total_amount' => $dnData['total_amount'],
                    'status' => 'approved',
                    'remarks' => 'Auto generated ' . strtoupper($noteType) . ' Note from Settlement ' . $settlement->settlement_number,
                    'created_by' => auth()->id(),
                ]);

                DebitNoteItem::create([
                    'debit_note_id' => $debitNote->id,
                    'particulars' => ($noteType === 'credit')
                        ? 'Quality bonus / excess rate difference between settlement and bill'
                        : 'Rebate difference between Invoice value and final Settlement bran value',
                    'amount' => $dnData['taxable_amount'],
                ]);

                // Post to Vendor Ledger
                if ($noteType === 'credit') {
                    VendorLedger::postTransaction([
                        'vendor_id' => $settlement->vendor_id,
                        'branch_id' => $branchId,
                        'transaction_date' => $settlementDate,
                        'document_type' => 'credit_note',
                        'document_id' => $debitNote->id,
                        'document_number' => $debitNote->debit_note_number,
                        'lot_number' => $settlement->lot_number,
                        'description' => 'Credit Note against Bill #' . ($settlement->bill_number ?? $settlement->settlement_number),
                        'debit' => 0,
                        'credit' => $debitNote->total_amount,
                    ]);
                } else {
                    VendorLedger::postTransaction([
                        'vendor_id' => $settlement->vendor_id,
                        'branch_id' => $branchId,
                        'transaction_date' => $settlementDate,
                        'document_type' => 'debit_note',
                        'document_id' => $debitNote->id,
                        'document_number' => $debitNote->debit_note_number,
                        'lot_number' => $settlement->lot_number,
                        'description' => 'Rebate / Debit Note against Bill #' . ($settlement->bill_number ?? $settlement->settlement_number),
                        'debit' => $debitNote->total_amount,
                        'credit' => 0,
                    ]);
                }
            }

            AuditLog::logAction('created', 'Settlement', $settlement->id, $settlement->settlement_number, null, $settlement->toArray(), 'Settlement bill generated');

            return $settlement;
        });

        return response()->json([
            'success' => true,
            'message' => 'Settlement created successfully',
            'data' => $settlement->load(['branch.company', 'vendor', 'product', 'invoice', 'debitNotes', 'qualityTests', 'charges', 'items.product']),
        ], 201);
    }

    public function show(Settlement $settlement)
    {
        $this->authorizeSettlementAccess();
        $this->guardSettlementCompany($settlement);

        $settlement->load(['branch.company', 'vendor', 'product', 'invoice', 'debitNotes.items', 'qualityTests', 'charges', 'creator', 'items.product']);

        $origInv = (float)($settlement->original_invoice_value ?? 0);
        $branVal = (float)($settlement->bran_value ?? 0);
        $manualAdj = (float)($settlement->manual_adjustment ?? 0);
        if ($origInv > 0 || $manualAdj != 0) {
            $diff = ($origInv > 0) ? (($origInv - $branVal) + $manualAdj) : $manualAdj;
            $correctType = ($diff < -0.001) ? 'credit' : 'debit';
            $dn = $settlement->debitNotes->first();
            $prefixMismatch = $dn && str_starts_with(
                (string) $dn->debit_note_number,
                $correctType === 'credit' ? 'DN/' : 'CN/'
            );

            if ($dn && ($dn->transaction_type !== $correctType || $prefixMismatch)) {
                // Financial write on a read endpoint -> keep it atomic.
                DB::transaction(function () use ($dn, $correctType, $settlement) {
                    $newNumber = DocumentSequence::convertNoteNumber(
                        $dn->debit_note_number,
                        $correctType === 'credit' ? 'CN' : 'DN',
                        $settlement->branch_id,
                        $settlement->settlement_date
                    );
                    $dn->update([
                        'transaction_type' => $correctType,
                        'debit_note_number' => $newNumber,
                        'reason' => ($correctType === 'credit')
                            ? 'Quality Premium / Price Credit on Oil Content Settlement'
                            : 'Rebate on Oil Content Settlement',
                    ]);
                    $dn->fill(['transaction_type' => $correctType, 'debit_note_number' => $newNumber]);

                    // The correction may have flipped type/number, so keep the
                    // existing ledger posting in step (never adds a second row).
                    VendorLedger::syncForNote($dn);
                });
            }
        }

        return response()->json([
            'success' => true,
            'data' => $settlement,
        ]);
    }

    public function update(SettlementRequest $request, Settlement $settlement)
    {
        $this->authorizeSettlementAccess($request);
        $this->guardSettlementCompany($settlement);

        if ($settlement->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Cancelled settlement bills cannot be modified.',
            ], 422);
        }

        $validated = $request->validated();
        if (empty($validated['branch_id'])) {
            $validated['branch_id'] = $settlement->branch_id;
        }
        $companyId = $this->activeCompanyId();
        if (! empty($validated['branch_id']) && $companyId) {
            $belongsToCompany = \App\Models\Branch::where('id', $validated['branch_id'])
                ->where('company_id', $companyId)
                ->exists();
            if (! $belongsToCompany) {
                $validated['branch_id'] = \App\Models\Branch::where('company_id', $companyId)->value('id') ?? $settlement->branch_id;
            }
        }
        $old = $settlement->toArray();

        $settlement = DB::transaction(function () use ($settlement, $validated, $old) {
            $calc = $this->calcService->calculate($validated);

            $modelData = array_merge($validated, [
                'settlement_type' => $validated['settlement_type'] ?? $settlement->settlement_type ?? 'single',
                'final_weight_mt' => $calc['final_weight_mt'],
                'final_weight_kg' => $calc['final_weight_kg'],
                'oil_quantity_kg' => $calc['oil_quantity_kg'],
                'bran_value' => $calc['bran_value'],
                'bag_amount' => $calc['bag_amount'],
                'loading_charges' => $calc['loading_charges'],
                'unloading_charges' => $calc['unloading_charges'],
                'tds_rate' => $calc['tds_rate'],
                'tds' => $calc['tds'],
                'advance_paid' => $calc['advance_paid'],
                'lorry_freight' => $calc['lorry_freight'],
                'other_charges' => $calc['other_charges'],
                'original_invoice_value' => $calc['original_invoice_value'],
                'rebate_amount' => $calc['rebate_amount'],
                'subtotal_amount' => $calc['subtotal_amount'],
                'is_interstate' => $calc['is_interstate'],
                'cgst_rate' => $calc['cgst_rate'],
                'sgst_rate' => $calc['sgst_rate'],
                'igst_rate' => $calc['igst_rate'],
                'cgst_amount' => $calc['cgst_amount'],
                'sgst_amount' => $calc['sgst_amount'],
                'igst_amount' => $calc['igst_amount'],
                'total_amount' => $calc['total_amount'],
                'net_payable' => $calc['net_payable'],
                'updated_by' => auth()->id(),
            ]);

            $settlement->update($modelData);

            // Sync Settlement Items
            \App\Models\SettlementItem::where('settlement_id', $settlement->id)->delete();
            if (($modelData['settlement_type'] ?? 'single') === 'multiple' && !empty($calc['items'])) {
                foreach ($calc['items'] as $it) {
                    \App\Models\SettlementItem::create([
                        'settlement_id' => $settlement->id,
                        'product_id' => $it['product_id'] ?? null,
                        'lot_number' => $it['lot_number'] ?? null,
                        'net_weight_mt' => $it['net_weight_mt'],
                        'jute_bag' => $it['jute_bag'],
                        'pp_bag' => $it['pp_bag'],
                        'final_weight_mt' => $it['final_weight_mt'],
                        'final_weight_kg' => $it['final_weight_kg'],
                        'oil_content' => $it['oil_content'],
                        'ffa' => $it['ffa'],
                        'ss' => $it['ss'],
                        'fibre' => $it['fibre'],
                        'moisture' => $it['moisture'],
                        'contract_unit_rate' => $it['contract_unit_rate'],
                        'final_unit_rate' => $it['final_unit_rate'],
                        'oil_quantity_kg' => $it['oil_quantity_kg'],
                        'bran_value' => $it['bran_value'],
                        'bag_amount' => $it['bag_amount'],
                        'remarks' => $it['remarks'] ?? null,
                        'sort_order' => $it['sort_order'] ?? 0,
                    ]);
                }
            }

            // Update QC test values
            SettlementQualityTest::where('settlement_id', $settlement->id)->delete();
            $qcParams = [
                'Oil Content' => $validated['oil_content'] ?? null,
                'FFA' => $validated['ffa'] ?? null,
                'SS' => $validated['ss'] ?? null,
                'Fibre' => $validated['fibre'] ?? null,
                'Moisture' => $validated['moisture'] ?? null,
                'IV' => $validated['iv'] ?? null,
                'Calcium' => $validated['calcium'] ?? null,
                'Protein' => $validated['protein'] ?? null,
            ];
            foreach ($qcParams as $name => $val) {
                if ($val !== null) {
                    SettlementQualityTest::create([
                        'settlement_id' => $settlement->id,
                        'parameter_name' => $name,
                        'parameter_value' => $val,
                    ]);
                }
            }

            // Sync Debit/Credit note on update
            $noteAmount = $calc['rebate_amount'] ?? ($calc['note_amount'] ?? 0);
            $noteType = $calc['note_type'] ?? 'debit';
            $existingNote = DebitNote::where('settlement_id', $settlement->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            $ledgerDescription = (($noteType === 'credit') ? 'Credit Note against Bill #' : 'Rebate / Debit Note against Bill #')
                . ($settlement->bill_number ?? $settlement->settlement_number);

            if ($noteAmount > 0) {
                $dnData = $calc['debit_note'];
                $reason = ($noteType === 'credit')
                    ? 'Quality Premium / Price Credit on Oil Content Settlement'
                    : 'Rebate on Oil Content Settlement';

                if ($existingNote) {
                    $updates = [
                        'transaction_type' => $noteType,
                        'taxable_amount' => $dnData['taxable_amount'],
                        'cgst_amount' => $dnData['cgst_amount'],
                        'sgst_amount' => $dnData['sgst_amount'],
                        'igst_amount' => $dnData['igst_amount'],
                        'total_amount' => $dnData['total_amount'],
                        'reason' => $reason,
                        'remarks' => 'Auto generated ' . strtoupper($noteType) . ' Note from Settlement ' . $settlement->settlement_number,
                    ];

                    // Rewrite the DN/CN prefix only when it disagrees with the
                    // note type - never churn an already correct number.
                    if (str_starts_with((string) $existingNote->debit_note_number, $noteType === 'credit' ? 'DN/' : 'CN/')) {
                        $updates['debit_note_number'] = DocumentSequence::convertNoteNumber(
                            $existingNote->debit_note_number,
                            $noteType === 'credit' ? 'CN' : 'DN',
                            $settlement->branch_id,
                            $settlement->settlement_date
                        );
                    }

                    $existingNote->update($updates);

                    DebitNoteItem::where('debit_note_id', $existingNote->id)->update([
                        'particulars' => ($noteType === 'credit')
                            ? 'Quality bonus / excess rate difference between settlement and bill'
                            : 'Rebate difference between Invoice value and final Settlement bran value',
                        'amount' => $dnData['taxable_amount'],
                    ]);

                    // Reuse the existing ledger row; adjusts amount/direction
                    // in place and repairs the running balance downstream.
                    VendorLedger::syncForNote($existingNote, $ledgerDescription);
                } else {
                    $branchId = $settlement->branch_id;
                    $settlementDate = $settlement->settlement_date;
                    $fy = $settlement->financial_year ?: FinancialYear::getFinancialYearForDate($settlementDate);
                    $dnNumber = DocumentSequence::getNextNumber(
                        ($noteType === 'credit') ? 'credit_note' : 'debit_note',
                        $branchId,
                        $settlementDate
                    );

                    $newNote = DebitNote::create([
                        'debit_note_number' => $dnNumber,
                        'financial_year' => $fy,
                        'branch_id' => $branchId,
                        'vendor_id' => $settlement->vendor_id,
                        'settlement_id' => $settlement->id,
                        'invoice_id' => $settlement->invoice_id,
                        'invoice_number' => $settlement->bill_number,
                        'invoice_date' => $settlement->bill_date,
                        'lot_number' => $settlement->lot_number,
                        'date' => $settlementDate,
                        'reason' => $reason,
                        'transaction_type' => $noteType,
                        'taxable_amount' => $dnData['taxable_amount'],
                        'is_interstate' => $calc['is_interstate'],
                        'cgst_rate' => $dnData['cgst_rate'],
                        'sgst_rate' => $dnData['sgst_rate'],
                        'igst_rate' => $dnData['igst_rate'],
                        'cgst_amount' => $dnData['cgst_amount'],
                        'sgst_amount' => $dnData['sgst_amount'],
                        'igst_amount' => $dnData['igst_amount'],
                        'total_amount' => $dnData['total_amount'],
                        'status' => 'approved',
                        'remarks' => 'Auto generated ' . strtoupper($noteType) . ' Note from Settlement ' . $settlement->settlement_number,
                        'created_by' => auth()->id(),
                    ]);

                    DebitNoteItem::create([
                        'debit_note_id' => $newNote->id,
                        'particulars' => ($noteType === 'credit')
                            ? 'Quality bonus / excess rate difference between settlement and bill'
                            : 'Rebate difference between Invoice value and final Settlement bran value',
                        'amount' => $dnData['taxable_amount'],
                    ]);

                    VendorLedger::syncForNote($newNote, $ledgerDescription);
                }
            } elseif ($existingNote) {
                // Note amount went to zero: drop its posting (no orphan ledger
                // rows) and let the balances behind it be repaired.
                VendorLedger::removeNoteEntries($existingNote);
                $existingNote->delete();
            }

            AuditLog::logAction('updated', 'Settlement', $settlement->id, $settlement->settlement_number, $old, $settlement->toArray(), 'Settlement updated');

            return $settlement;
        });

        return response()->json([
            'success' => true,
            'message' => 'Settlement updated successfully',
            'data' => $settlement->load(['branch.company', 'vendor', 'product', 'invoice', 'debitNotes', 'qualityTests', 'charges', 'items.product']),
        ]);
    }

    public function cancel(Request $request, Settlement $settlement)
    {
        $this->authorizeSettlementAccess($request);
        $this->guardSettlementCompany($settlement);

        $request->validate([
            'cancellation_reason' => 'required|string|min:5',
        ]);

        if ($settlement->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Settlement is already cancelled.',
            ], 422);
        }

        DB::transaction(function () use ($settlement, $request) {
            $settlement->status = 'cancelled';
            $settlement->cancellation_reason = $request->cancellation_reason;
            $settlement->save();

            // Cancel any linked debit notes
            foreach ($settlement->debitNotes as $dn) {
                if ($dn->status !== 'cancelled') {
                    $dn->status = 'cancelled';
                    $dn->cancellation_reason = 'Linked settlement cancelled: ' . $request->cancellation_reason;
                    $dn->save();

                    // Reverse ledger
                    VendorLedger::postTransaction([
                        'vendor_id' => $dn->vendor_id,
                        'branch_id' => $dn->branch_id,
                        'transaction_date' => now()->toDateString(),
                        'document_type' => 'debit_note_reversal',
                        'document_id' => $dn->id,
                        'document_number' => $dn->debit_note_number,
                        'lot_number' => $dn->lot_number,
                        'description' => 'Cancellation reversal of DN #' . $dn->debit_note_number,
                        'debit' => 0,
                        'credit' => $dn->total_amount,
                    ]);
                }
            }

            AuditLog::logAction('cancelled', 'Settlement', $settlement->id, $settlement->settlement_number, null, ['reason' => $request->cancellation_reason], 'Settlement cancelled');
        });

        return response()->json([
            'success' => true,
            'message' => 'Settlement cancelled successfully',
            'data' => $settlement,
        ]);
    }

    public function pdf(Settlement $settlement)
    {
        $this->authorizeSettlementAccess();
        $this->guardSettlementCompany($settlement);

        $pdf = $this->pdfService->generateSettlementBillPdf($settlement);
        $fileName = str_replace(['/', '\\'], '-', $settlement->settlement_number) . '.pdf';

        return $pdf->stream($fileName);
    }

    public function destroy(Settlement $settlement)
    {
        $this->authorizeSettlementAccess();
        $this->guardSettlementCompany($settlement);
        $vendorId = $settlement->vendor_id;

        DB::transaction(function () use ($settlement, $vendorId) {
            // Remove linked debit notes from ledger and soft-delete them
            foreach ($settlement->debitNotes as $dn) {
                VendorLedger::removeNoteEntries($dn);
                $dn->delete();
            }

            // Remove settlement bill ledger entries
            VendorLedger::whereIn('document_type', ['settlement', 'settlement_cancellation'])
                ->where('document_id', $settlement->id)
                ->delete();

            // Soft-delete the settlement (child rows: items, QC, charges remain safe for restore)
            $settlement->delete();

            // Recalculate vendor balance
            VendorLedger::recalculateBalances($vendorId);
        });

        AuditLog::logAction('deleted', 'Settlement', $settlement->id, $settlement->settlement_number, null, null, 'Settlement deleted');

        return response()->json([
            'success' => true,
            'message' => 'Settlement bill deleted successfully',
        ]);
    }
}
