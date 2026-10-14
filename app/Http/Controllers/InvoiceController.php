<?php

namespace App\Http\Controllers;

use App\Http\Requests\InvoiceRequest;
use App\Models\AuditLog;
use App\Models\DocumentSequence;
use App\Models\Invoice;
use App\Models\VendorLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        // Invoices are isolated through their branch (which belongs to a company)
        $query = $this->scopeBranches(Invoice::with(['branch', 'vendor', 'product', 'creator']));

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhere('lot_number', 'like', "%{$search}%")
                  ->orWhere('vehicle_number', 'like', "%{$search}%")
                  ->orWhereHas('vendor', function ($vq) use ($search) {
                      $vq->where('vendor_name', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('from_date') && $request->filled('to_date')) {
            $query->whereBetween('invoice_date', [$request->from_date, $request->to_date]);
        }

        $invoices = $query->orderBy('invoice_date', 'desc')->orderBy('id', 'desc')->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $invoices,
        ]);
    }

    public function store(InvoiceRequest $request)
    {
        $data = $request->validated();

        $this->guardBranchAccess($data['branch_id'] ?? null);

        $invoice = DB::transaction(function () use ($data) {
            // Auto number if not provided
            if (empty($data['invoice_number'])) {
                $data['invoice_number'] = DocumentSequence::getNextNumber('invoice', $data['branch_id'], $data['invoice_date']);
            }

            // Calculation
            $netWeight = (float)($data['net_weight_mt'] ?? 0);
            $rate = (float)($data['rate'] ?? 0);
            $taxable = round($netWeight * $rate, 2);
            $data['taxable_amount'] = $taxable;

            $isInterstate = !empty($data['is_interstate']);
            $cgstRate = $isInterstate ? 0 : (float)($data['cgst_rate'] ?? 2.500);
            $sgstRate = $isInterstate ? 0 : (float)($data['sgst_rate'] ?? 2.500);
            $igstRate = $isInterstate ? (float)($data['igst_rate'] ?? 5.000) : 0;

            $cgstAmount = round(($taxable * $cgstRate) / 100.0, 2);
            $sgstAmount = round(($taxable * $sgstRate) / 100.0, 2);
            $igstAmount = round(($taxable * $igstRate) / 100.0, 2);
            $total = round($taxable + $cgstAmount + $sgstAmount + $igstAmount, 2);

            $data['cgst_rate'] = $cgstRate;
            $data['sgst_rate'] = $sgstRate;
            $data['igst_rate'] = $igstRate;
            $data['cgst_amount'] = $cgstAmount;
            $data['sgst_amount'] = $sgstAmount;
            $data['igst_amount'] = $igstAmount;
            $data['total_amount'] = $total;
            $data['created_by'] = auth()->id();
            $data['status'] = 'approved';

            $inv = Invoice::create($data);

            // Post to vendor ledger (Purchase / Bill invoice credits the vendor)
            VendorLedger::postTransaction([
                'vendor_id' => $inv->vendor_id,
                'branch_id' => $inv->branch_id,
                'transaction_date' => $inv->invoice_date,
                'document_type' => 'invoice',
                'document_id' => $inv->id,
                'document_number' => $inv->invoice_number,
                'lot_number' => $inv->lot_number,
                'description' => 'Original Invoice Purchase ' . ($inv->product ? $inv->product->product_name : ''),
                'debit' => 0,
                'credit' => $inv->total_amount,
            ]);

            AuditLog::logAction('created', 'Invoice', $inv->id, $inv->invoice_number, null, $inv->toArray(), 'Invoice created and ledger updated');

            return $inv;
        });

        return response()->json([
            'success' => true,
            'message' => 'Invoice created successfully',
            'data' => $invoice->load(['branch', 'vendor', 'product']),
        ], 201);
    }

    public function show(Invoice $invoice)
    {
        $this->guardBranchOwnership($invoice);
        $invoice->load(['branch', 'vendor', 'product', 'settlements', 'debitNotes']);

        return response()->json([
            'success' => true,
            'data' => $invoice,
        ]);
    }

    public function update(InvoiceRequest $request, Invoice $invoice)
    {
        $this->guardBranchOwnership($invoice);

        if ($invoice->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Cancelled invoices cannot be modified.',
            ], 422);
        }

        $old = $invoice->toArray();
        $data = $request->validated();

        $invoice = DB::transaction(function () use ($invoice, $data, $old) {
            $netWeight = (float)($data['net_weight_mt'] ?? $invoice->net_weight_mt);
            $rate = (float)($data['rate'] ?? $invoice->rate);
            $taxable = round($netWeight * $rate, 2);
            $data['taxable_amount'] = $taxable;

            $isInterstate = isset($data['is_interstate']) ? (bool)$data['is_interstate'] : $invoice->is_interstate;
            $cgstRate = $isInterstate ? 0 : (float)($data['cgst_rate'] ?? 2.500);
            $sgstRate = $isInterstate ? 0 : (float)($data['sgst_rate'] ?? 2.500);
            $igstRate = $isInterstate ? (float)($data['igst_rate'] ?? 5.000) : 0;

            $cgstAmount = round(($taxable * $cgstRate) / 100.0, 2);
            $sgstAmount = round(($taxable * $sgstRate) / 100.0, 2);
            $igstAmount = round(($taxable * $igstRate) / 100.0, 2);
            $total = round($taxable + $cgstAmount + $sgstAmount + $igstAmount, 2);

            $data['cgst_rate'] = $cgstRate;
            $data['sgst_rate'] = $sgstRate;
            $data['igst_rate'] = $igstRate;
            $data['cgst_amount'] = $cgstAmount;
            $data['sgst_amount'] = $sgstAmount;
            $data['igst_amount'] = $igstAmount;
            $data['total_amount'] = $total;

            $invoice->update($data);

            AuditLog::logAction('updated', 'Invoice', $invoice->id, $invoice->invoice_number, $old, $invoice->toArray(), 'Invoice updated');

            return $invoice;
        });

        return response()->json([
            'success' => true,
            'message' => 'Invoice updated successfully',
            'data' => $invoice->load(['branch', 'vendor', 'product']),
        ]);
    }

    public function cancel(Request $request, Invoice $invoice)
    {
        $this->guardBranchOwnership($invoice);

        $request->validate([
            'cancellation_reason' => 'required|string|min:5',
        ]);

        if ($invoice->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Invoice is already cancelled.',
            ], 422);
        }

        DB::transaction(function () use ($invoice, $request) {
            $invoice->status = 'cancelled';
            $invoice->cancellation_reason = $request->cancellation_reason;
            $invoice->save();

            // Reverse ledger
            VendorLedger::postTransaction([
                'vendor_id' => $invoice->vendor_id,
                'branch_id' => $invoice->branch_id,
                'transaction_date' => now()->toDateString(),
                'document_type' => 'invoice_cancellation',
                'document_id' => $invoice->id,
                'document_number' => $invoice->invoice_number,
                'lot_number' => $invoice->lot_number,
                'description' => 'Cancellation of Invoice #' . $invoice->invoice_number . ' - ' . $request->cancellation_reason,
                'debit' => $invoice->total_amount,
                'credit' => 0,
            ]);

            AuditLog::logAction('cancelled', 'Invoice', $invoice->id, $invoice->invoice_number, null, ['reason' => $request->cancellation_reason], 'Invoice cancelled');
        });

        return response()->json([
            'success' => true,
            'message' => 'Invoice cancelled successfully',
            'data' => $invoice,
        ]);
    }

    public function destroy(Invoice $invoice)
    {
        $this->guardBranchOwnership($invoice);
        $invoice->delete();
        AuditLog::logAction('deleted', 'Invoice', $invoice->id, $invoice->invoice_number, null, null, 'Invoice moved to trash');
        return response()->json([
            'success' => true,
            'message' => 'Invoice moved to trash successfully',
        ]);
    }
}
