<?php

namespace App\Http\Controllers;

use App\Http\Requests\DebitNoteRequest;
use App\Models\AuditLog;
use App\Models\DebitNote;
use App\Models\DebitNoteItem;
use App\Models\DocumentSequence;
use App\Models\FinancialYear;
use App\Models\VendorLedger;
use App\Services\PdfService;
use App\Services\SettlementCalculationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DebitNoteController extends Controller
{
    protected $pdfService;
    protected $calcService;

    public function __construct(PdfService $pdfService, SettlementCalculationService $calcService)
    {
        $this->pdfService = $pdfService;
        $this->calcService = $calcService;
    }

    public function index(Request $request)
    {
        // Debit notes are isolated through their branch (which belongs to a company)
        $query = $this->scopeBranches(DebitNote::with(['branch', 'vendor', 'settlement', 'invoice', 'creator']));

        if (! $request->filled('vendor_id')) {
            $query->whereHas('vendor', function ($vq) {
                $vq->where('status', 'active');
            });
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('transaction_type')) {
            $type = strtolower($request->transaction_type);
            if ($type === 'credit') {
                $query->where('transaction_type', 'credit');
            } elseif ($type === 'debit') {
                $query->where(function ($q) {
                    $q->where('transaction_type', 'debit')
                      ->orWhereNull('transaction_type');
                });
            } else {
                $query->where('transaction_type', $type);
            }
        }

        if ($request->filled('from_date') && $request->filled('to_date')) {
            $query->whereBetween('date', [$request->from_date, $request->to_date]);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('debit_note_number', 'like', "%{$search}%")
                  ->orWhere('invoice_number', 'like', "%{$search}%")
                  ->orWhere('lot_number', 'like', "%{$search}%")
                  ->orWhereHas('vendor', function ($vq) use ($search) {
                      $vq->where('vendor_name', 'like', "%{$search}%");
                  });
            });
        }

        $debitNotes = $query->orderBy('date', 'desc')->orderBy('id', 'desc')->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $debitNotes,
        ]);
    }

    public function store(DebitNoteRequest $request)
    {
        $data = $request->validated();

        $this->guardBranchAccess($data['branch_id'] ?? null);

        $debitNote = DB::transaction(function () use ($data) {
            $branchId = $data['branch_id'];
            $date = $data['date'];
            $fy = FinancialYear::getFinancialYearForDate($date);

            if (empty($data['debit_note_number'])) {
                $data['debit_note_number'] = DocumentSequence::getNextNumber('debit_note', $branchId, $date);
            }

            $taxable = (float)($data['taxable_amount'] ?? 0);
            $isInterstate = !empty($data['is_interstate']);

            $cgstRate = $isInterstate ? 0 : (float)($data['cgst_rate'] ?? 2.500);
            $sgstRate = $isInterstate ? 0 : (float)($data['sgst_rate'] ?? 2.500);
            $igstRate = $isInterstate ? (float)($data['igst_rate'] ?? 5.000) : 0;

            $cgstAmount = round(($taxable * $cgstRate) / 100.0, 2);
            $sgstAmount = round(($taxable * $sgstRate) / 100.0, 2);
            $igstAmount = round(($taxable * $igstRate) / 100.0, 2);
            $total = round($taxable + $cgstAmount + $sgstAmount + $igstAmount, 2);

            $data['financial_year'] = $fy;
            $data['is_interstate'] = $isInterstate;
            $data['cgst_rate'] = $cgstRate;
            $data['sgst_rate'] = $sgstRate;
            $data['igst_rate'] = $igstRate;
            $data['cgst_amount'] = $cgstAmount;
            $data['sgst_amount'] = $sgstAmount;
            $data['igst_amount'] = $igstAmount;
            $data['total_amount'] = $total;
            $data['status'] = 'approved';
            $data['created_by'] = auth()->id();

            $items = $data['items'] ?? [];
            unset($data['items']);

            $dn = DebitNote::create($data);

            if (!empty($items)) {
                foreach ($items as $item) {
                    DebitNoteItem::create([
                        'debit_note_id' => $dn->id,
                        'particulars' => $item['particulars'],
                        'amount' => (float)$item['amount'],
                        'remarks' => $item['remarks'] ?? null,
                    ]);
                }
            } else {
                DebitNoteItem::create([
                    'debit_note_id' => $dn->id,
                    'particulars' => $dn->reason,
                    'amount' => $dn->taxable_amount,
                ]);
            }

            // Post to Vendor Ledger
            // Debit Note decreases payable to vendor (debit entry)
            // Credit Note increases payable (credit entry)
            $isDebit = ($dn->transaction_type === 'debit');
            VendorLedger::postTransaction([
                'vendor_id' => $dn->vendor_id,
                'branch_id' => $dn->branch_id,
                'transaction_date' => $dn->date,
                'document_type' => $dn->transaction_type === 'credit' ? 'credit_note' : 'debit_note',
                'document_id' => $dn->id,
                'document_number' => $dn->debit_note_number,
                'lot_number' => $dn->lot_number,
                'description' => $dn->reason . ' against ' . ($dn->invoice_number ? 'Inv #' . $dn->invoice_number : 'Settlement'),
                'debit' => $isDebit ? $dn->total_amount : 0,
                'credit' => $isDebit ? 0 : $dn->total_amount,
            ]);

            AuditLog::logAction('created', 'DebitNote', $dn->id, $dn->debit_note_number, null, $dn->toArray(), 'Debit Note created');

            return $dn;
        });

        return response()->json([
            'success' => true,
            'message' => 'Debit Note created successfully',
            'data' => $debitNote->load(['branch', 'vendor', 'items']),
        ], 201);
    }

    public function show(DebitNote $debitNote)
    {
        $this->guardBranchOwnership($debitNote);

        $debitNote->load(['branch', 'vendor', 'settlement', 'invoice', 'items', 'creator']);

        return response()->json([
            'success' => true,
            'data' => $debitNote,
        ]);
    }

    public function update(DebitNoteRequest $request, DebitNote $debitNote)
    {
        $this->guardBranchOwnership($debitNote);

        if ($debitNote->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Cancelled debit notes cannot be updated.',
            ], 422);
        }

        $old = $debitNote->toArray();
        $data = $request->validated();

        $debitNote = DB::transaction(function () use ($debitNote, $data, $old) {
            $taxable = (float)($data['taxable_amount'] ?? $debitNote->taxable_amount);
            $isInterstate = isset($data['is_interstate']) ? (bool)$data['is_interstate'] : $debitNote->is_interstate;

            $cgstRate = $isInterstate ? 0 : (float)($data['cgst_rate'] ?? 2.500);
            $sgstRate = $isInterstate ? 0 : (float)($data['sgst_rate'] ?? 2.500);
            $igstRate = $isInterstate ? (float)($data['igst_rate'] ?? 5.000) : 0;

            $cgstAmount = round(($taxable * $cgstRate) / 100.0, 2);
            $sgstAmount = round(($taxable * $sgstRate) / 100.0, 2);
            $igstAmount = round(($taxable * $igstRate) / 100.0, 2);
            $total = round($taxable + $cgstAmount + $sgstAmount + $igstAmount, 2);

            $data['is_interstate'] = $isInterstate;
            $data['cgst_rate'] = $cgstRate;
            $data['sgst_rate'] = $sgstRate;
            $data['igst_rate'] = $igstRate;
            $data['cgst_amount'] = $cgstAmount;
            $data['sgst_amount'] = $sgstAmount;
            $data['igst_amount'] = $igstAmount;
            $data['total_amount'] = $total;
            $data['updated_by'] = auth()->id();

            $items = $data['items'] ?? null;
            unset($data['items']);

            $debitNote->update($data);

            if ($items !== null) {
                DebitNoteItem::where('debit_note_id', $debitNote->id)->delete();
                foreach ($items as $item) {
                    DebitNoteItem::create([
                        'debit_note_id' => $debitNote->id,
                        'particulars' => $item['particulars'],
                        'amount' => (float)$item['amount'],
                        'remarks' => $item['remarks'] ?? null,
                    ]);
                }
            }

            AuditLog::logAction('updated', 'DebitNote', $debitNote->id, $debitNote->debit_note_number, $old, $debitNote->toArray(), 'Debit note updated');

            return $debitNote;
        });

        return response()->json([
            'success' => true,
            'message' => 'Debit Note updated successfully',
            'data' => $debitNote->load(['branch', 'vendor', 'items']),
        ]);
    }

    public function cancel(Request $request, DebitNote $debitNote)
    {
        $this->guardBranchOwnership($debitNote);

        $request->validate([
            'cancellation_reason' => 'required|string|min:5',
        ]);

        if ($debitNote->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Debit note is already cancelled.',
            ], 422);
        }

        DB::transaction(function () use ($debitNote, $request) {
            $debitNote->status = 'cancelled';
            $debitNote->cancellation_reason = $request->cancellation_reason;
            $debitNote->save();

            // Reverse vendor ledger
            $isDebit = ($debitNote->transaction_type === 'debit');
            VendorLedger::postTransaction([
                'vendor_id' => $debitNote->vendor_id,
                'branch_id' => $debitNote->branch_id,
                'transaction_date' => now()->toDateString(),
                'document_type' => 'debit_note_cancellation',
                'document_id' => $debitNote->id,
                'document_number' => $debitNote->debit_note_number,
                'lot_number' => $debitNote->lot_number,
                'description' => 'Cancellation of ' . strtoupper($debitNote->transaction_type) . ' Note #' . $debitNote->debit_note_number . ' - ' . $request->cancellation_reason,
                'debit' => $isDebit ? 0 : $debitNote->total_amount,
                'credit' => $isDebit ? $debitNote->total_amount : 0,
            ]);

            AuditLog::logAction('cancelled', 'DebitNote', $debitNote->id, $debitNote->debit_note_number, null, ['reason' => $request->cancellation_reason], 'Debit note cancelled');
        });

        return response()->json([
            'success' => true,
            'message' => 'Debit note cancelled successfully',
            'data' => $debitNote,
        ]);
    }

    public function pdf(DebitNote $debitNote)
    {
        $this->guardBranchOwnership($debitNote);

        $pdf = $this->pdfService->generateDebitNotePdf($debitNote);
        $fileName = str_replace(['/', '\\'], '-', $debitNote->debit_note_number) . '.pdf';

        return $pdf->stream($fileName);
    }

    public function destroy(DebitNote $debitNote)
    {
        $this->guardBranchOwnership($debitNote);
        $debitNote->delete();
        AuditLog::logAction('deleted', 'DebitNote', $debitNote->id, $debitNote->debit_note_number, null, null, 'Debit note moved to trash');
        return response()->json([
            'success' => true,
            'message' => 'Debit note moved to trash successfully',
        ]);
    }
}
