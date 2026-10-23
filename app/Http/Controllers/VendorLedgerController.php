<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Vendor;
use App\Models\VendorLedger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class VendorLedgerController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'vendor_id' => 'nullable|integer|exists:vendors,id',
        ]);

        $vendor = null;
        if ($request->filled('vendor_id')) {
            $vendor = Vendor::findOrFail($request->vendor_id);
            $this->guardCompanyOwnership($vendor);
        }

        // Settlement bills already post real debit/credit notes to the ledger.
        // Their mirrored 'settlement' rows are intentionally NOT shown.
        $query = VendorLedger::with(['branch', 'creator', 'vendor:id,vendor_name,vendor_code,opening_balance,company_id'])
            ->whereNotIn('document_type', ['invoice', 'INVOICE', 'settlement', 'settlement_cancellation']);

        if ($vendor) {
            $query->where('vendor_id', $vendor->id);
        } else {
            // No vendor picked → every entry belonging to the ACTIVE COMPANY only.
            // vendor_ledger has no company_id, so scope through its parent vendor.
            $this->scopeLedgerCompany($query);
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('document_type')) {
            $docType = strtolower($request->document_type);
            $query->where(function ($q) use ($docType) {
                if ($docType === 'debit_note') {
                    $q->whereIn('document_type', ['debit_note', 'DEBIT_NOTE', 'payment_adjustment', 'ADVANCE']);
                } elseif ($docType === 'credit_note') {
                    $q->whereIn('document_type', ['credit_note', 'CREDIT_NOTE', 'receipt_adjustment']);
                } else {
                    $q->whereRaw('LOWER(document_type) = ?', [$docType]);
                }
            });
        }

        if ($request->filled('from_date') && $request->filled('to_date')) {
            $query->whereBetween('transaction_date', [$request->from_date, $request->to_date]);
        } elseif ($request->filled('from_date')) {
            $query->where('transaction_date', '>=', $request->from_date);
        } elseif ($request->filled('to_date')) {
            $query->where('transaction_date', '<=', $request->to_date);
        }

        $entries = $query->orderBy('transaction_date', 'asc')->orderBy('id', 'asc')->get();

        // Calculate summary
        $totalDebit = $entries->sum('debit');
        $totalCredit = $entries->sum('credit');

        return response()->json([
            'success' => true,
            'data' => [
                'vendor' => $vendor,
                'opening_balance' => $vendor ? $vendor->opening_balance : 0,
                'entries' => $entries,
                'entry_count' => $entries->count(),
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'closing_balance' => $vendor ? $vendor->current_balance : null,
            ],
        ]);
    }

    /**
     * Restrict a vendor_ledger query to the active company.
     * The table has no company_id — scope through the parent vendor row.
     */
    private function scopeLedgerCompany($query): void
    {
        $companyId = $this->activeCompanyId();
        $allowed = request('allowed_company_ids');

        if ($companyId) {
            $query->whereHas('vendor', fn ($q) => $q->where('company_id', $companyId));
        } elseif (is_array($allowed) && count($allowed) > 0) {
            $query->whereHas('vendor', fn ($q) => $q->whereIn('company_id', $allowed));
        }
    }

    public function storeAdjustment(Request $request)
    {
        $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'branch_id' => 'nullable|exists:branches,id',
            'transaction_date' => 'required|date',
            'transaction_type' => 'required|in:debit,credit',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:255',
            'document_number' => 'nullable|string|max:50',
        ]);

        $vendor = Vendor::findOrFail($request->vendor_id);
        $this->guardCompanyOwnership($vendor);

        $branchId = $request->branch_id;
        if (empty($branchId)) {
            $branchId = Branch::where('company_id', $vendor->company_id)->value('id') ?? Branch::value('id');
        }

        $isDebit = $request->transaction_type === 'debit';
        $entry = VendorLedger::postTransaction([
            'vendor_id' => $request->vendor_id,
            'branch_id' => $branchId,
            'transaction_date' => $request->transaction_date,
            'document_type' => $isDebit ? 'payment_adjustment' : 'receipt_adjustment',
            'document_number' => $request->document_number ?? 'ADJ-' . time(),
            'description' => $request->description,
            'debit' => $isDebit ? $request->amount : 0,
            'credit' => $isDebit ? 0 : $request->amount,
        ]);

        AuditLog::logAction('manual_adjustment', 'VendorLedger', $entry->id, $entry->document_number, null, $entry->toArray(), 'Manual ledger adjustment');

        return response()->json([
            'success' => true,
            'message' => 'Ledger adjustment posted successfully',
            'data' => $entry,
        ], 201);
    }

    public function exportPdf(Request $request)
    {
        $request->validate([
            'vendor_id' => 'nullable|integer|exists:vendors,id',
        ]);

        $vendor = null;
        if ($request->filled('vendor_id')) {
            $vendor = Vendor::findOrFail($request->vendor_id);
            $this->guardCompanyOwnership($vendor);
        }

        $query = VendorLedger::with(['branch', 'vendor:id,vendor_name,vendor_code,opening_balance'])
            ->whereNotIn('document_type', ['invoice', 'INVOICE', 'settlement', 'settlement_cancellation']);

        if ($vendor) {
            $query->where('vendor_id', $vendor->id);
        } else {
            $this->scopeLedgerCompany($query);
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('document_type')) {
            $docType = strtolower($request->document_type);
            $query->where(function ($q) use ($docType) {
                if ($docType === 'debit_note') {
                    $q->whereIn('document_type', ['debit_note', 'DEBIT_NOTE', 'payment_adjustment', 'ADVANCE'])
                        ->orWhere(function ($s) {
                            $s->where('document_type', 'settlement')->where('debit', '>', 0);
                        });
                } elseif ($docType === 'credit_note') {
                    $q->whereIn('document_type', ['credit_note', 'CREDIT_NOTE', 'receipt_adjustment'])
                        ->orWhere(function ($s) {
                            $s->where('document_type', 'settlement')->where('credit', '>', 0);
                        });
                } else {
                    $q->whereRaw('LOWER(document_type) = ?', [$docType]);
                }
            });
        }
        if ($request->filled('from_date') && $request->filled('to_date')) {
            $query->whereBetween('transaction_date', [$request->from_date, $request->to_date]);
        } elseif ($request->filled('from_date')) {
            $query->where('transaction_date', '>=', $request->from_date);
        } elseif ($request->filled('to_date')) {
            $query->where('transaction_date', '<=', $request->to_date);
        }

        $entries = $query->orderBy('transaction_date', 'asc')->orderBy('id', 'asc')->get();
        $company = app(\App\Services\PdfService::class)->resolveCompany($vendor?->branch_id ?? null);

        $totalDebit = (float) $entries->sum('debit');
        $totalCredit = (float) $entries->sum('credit');
        $closingBalance = $vendor ? (float) $vendor->current_balance : 0.0;

        $pdf = Pdf::loadView('pdf.vendor_ledger', [
            'vendor' => $vendor,
            'entries' => $entries,
            'company' => $company,
            'fromDate' => $request->from_date,
            'toDate' => $request->to_date,
            'totalDebit' => $totalDebit,
            'totalCredit' => $totalCredit,
            'closingBalance' => $closingBalance,
        ])->setPaper('a4', 'portrait');

        return $pdf->stream('Vendor_Ledger_' . ($vendor->vendor_code ?? $company->code ?? 'ALL') . '.pdf');
    }

    private function renderLedgerHtml($vendor, $entries, $company, $fromDate, $toDate)
    {
        $totalDebit = $entries->sum('debit');
        $totalCredit = $entries->sum('credit');

        $rows = '';
        foreach ($entries as $e) {
            $date = date('d-M-Y', strtotime($e->transaction_date));
            $docType = strtoupper(str_replace('_', ' ', $e->document_type));
            $debit = $e->debit > 0 ? '₹' . number_format($e->debit, 2) : '-';
            $credit = $e->credit > 0 ? '₹' . number_format($e->credit, 2) : '-';

            $rows .= "<tr>
                <td>{$date}</td>
                <td><strong>{$e->document_number}</strong></td>
                <td>{$docType}</td>
                <td>{$e->description}</td>
                <td style='text-align: right;'>{$debit}</td>
                <td style='text-align: right;'>{$credit}</td>
            </tr>";
        }

        $dateRange = ($fromDate && $toDate) ? "Statement Period: {$fromDate} to {$toDate}" : "Complete Statement";

        return "<!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: sans-serif; font-size: 10px; color: #1f2937; }
                table { width: 100%; border-collapse: collapse; margin-top: 15px; }
                th, td { border: 1px solid #d1d5db; padding: 5px 8px; text-align: left; }
                th { background-color: #f3f4f6; font-weight: bold; }
                .text-right { text-align: right; }
                .header { border-bottom: 2px solid #059669; padding-bottom: 8px; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h2 style='margin:0; color:#065f46;'>{$company->company_name}</h2>
                <p style='margin:2px 0;'>{$company->address}, {$company->city} | GSTIN: {$company->gstin}</p>
                <h3 style='margin:6px 0 0 0;'>VENDOR STATEMENT OF ACCOUNT / LEDGER</h3>
                <p style='margin:2px 0; color:#4b5563;'>{$dateRange}</p>
            </div>
            <div style='margin-top: 10px; background-color: #f9fafb; padding: 8px; border: 1px solid #e5e7eb;'>
                <strong>Vendor:</strong> {$vendor->vendor_name} ({$vendor->vendor_code})<br>
                <strong>GSTIN:</strong> {$vendor->gstin} | <strong>PAN:</strong> {$vendor->pan}<br>
                <strong>Address:</strong> {$vendor->address}, {$vendor->city}, {$vendor->state}<br>
                <strong>Opening Balance:</strong> ₹" . number_format($vendor->opening_balance, 2) . "
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Doc No</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th style='text-align: right;'>Debit (₹)</th>
                        <th style='text-align: right;'>Credit (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    {$rows}
                    <tr style='background-color: #f3f4f6; font-weight: bold;'>
                        <td colspan='4' style='text-align: right;'>TOTALS:</td>
                        <td style='text-align: right;'>₹" . number_format($totalDebit, 2) . "</td>
                        <td style='text-align: right;'>₹" . number_format($totalCredit, 2) . "</td>
                    </tr>
                </tbody>
            </table>
        </body>
        </html>";
    }
}
