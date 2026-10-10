<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Settlement;
use App\Models\Vendor;
use App\Models\VendorLedger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function index(Request $request, string $type)
    {
        $data = $this->fetchReportData($type, $request);

        return response()->json([
            'success' => true,
            'report_type' => $type,
            'data' => $data,
        ]);
    }

    public function export(Request $request, string $type)
    {
        $format = $request->get('format', 'csv'); // csv, pdf
        $data = $this->fetchReportData($type, $request);

        if ($format === 'csv') {
            return $this->exportCsv($type, $data);
        } elseif ($format === 'pdf') {
            return $this->exportPdf($type, $data, $request);
        }

        return response()->json(['success' => false, 'message' => 'Invalid export format'], 400);
    }

    protected function fetchReportData(string $type, Request $request)
    {
        $fromDate = $request->get('from_date', now()->startOfMonth()->toDateString());
        $toDate = $request->get('to_date', now()->endOfMonth()->toDateString());
        $branchId = $request->get('branch_id');
        $vendorId = $request->get('vendor_id');
        $productId = $request->get('product_id');

        return match ($type) {
            'settlements' => $this->getSettlementReport($fromDate, $toDate, $branchId, $vendorId, $productId),
            'debit_notes' => $this->getDebitNoteReport($fromDate, $toDate, $branchId, $vendorId),
            'invoices' => $this->getInvoiceReport($fromDate, $toDate, $branchId, $vendorId),
            'branch_wise' => $this->getBranchWiseReport($fromDate, $toDate),
            'product_wise' => $this->getProductWiseReport($fromDate, $toDate),
            'gst' => $this->getGstReport($fromDate, $toDate, $branchId),
            'rebate' => $this->getRebateReport($fromDate, $toDate, $branchId, $vendorId),
            'outstanding' => $this->getOutstandingReport($branchId),
            'payments' => $this->getPaymentReport($fromDate, $toDate, $vendorId),
            default => [],
        };
    }

    protected function getSettlementReport($from, $to, $branchId, $vendorId, $productId)
    {
        $query = Settlement::with(['branch', 'vendor', 'product'])
            ->whereBetween('settlement_date', [$from, $to]);

        if ($branchId) $query->where('branch_id', $branchId);
        if ($vendorId) $query->where('vendor_id', $vendorId);
        if ($productId) $query->where('product_id', $productId);

        return $query->orderBy('settlement_date', 'desc')->get();
    }

    protected function getDebitNoteReport($from, $to, $branchId, $vendorId)
    {
        $query = DebitNote::with(['branch', 'vendor'])
            ->whereBetween('date', [$from, $to]);

        if ($branchId) $query->where('branch_id', $branchId);
        if ($vendorId) $query->where('vendor_id', $vendorId);

        return $query->orderBy('date', 'desc')->get();
    }

    protected function getInvoiceReport($from, $to, $branchId, $vendorId)
    {
        $query = Invoice::with(['branch', 'vendor', 'product'])
            ->whereBetween('invoice_date', [$from, $to]);

        if ($branchId) $query->where('branch_id', $branchId);
        if ($vendorId) $query->where('vendor_id', $vendorId);

        return $query->orderBy('invoice_date', 'desc')->get();
    }

    protected function getBranchWiseReport($from, $to)
    {
        return DB::table('settlements')
            ->join('branches', 'settlements.branch_id', '=', 'branches.id')
            ->whereBetween('settlements.settlement_date', [$from, $to])
            ->where('settlements.status', '!=', 'cancelled')
            ->select(
                'branches.branch_code',
                'branches.branch_name',
                DB::raw('COUNT(settlements.id) as total_settlements'),
                DB::raw('SUM(settlements.final_weight_mt) as total_weight_mt'),
                DB::raw('SUM(settlements.bran_value) as total_bran_value'),
                DB::raw('SUM(settlements.rebate_amount) as total_rebate'),
                DB::raw('SUM(settlements.net_payable) as total_net_payable')
            )
            ->groupBy('branches.id', 'branches.branch_code', 'branches.branch_name')
            ->get();
    }

    protected function getProductWiseReport($from, $to)
    {
        return DB::table('settlements')
            ->leftJoin('products', 'settlements.product_id', '=', 'products.id')
            ->whereBetween('settlements.settlement_date', [$from, $to])
            ->where('settlements.status', '!=', 'cancelled')
            ->select(
                DB::raw('COALESCE(products.product_name, "Rice Bran Grade II") as product_name'),
                DB::raw('COALESCE(products.product_code, "RB-02") as product_code'),
                DB::raw('COUNT(settlements.id) as total_settlements'),
                DB::raw('SUM(settlements.final_weight_mt) as total_weight_mt'),
                DB::raw('SUM(settlements.oil_quantity_kg) as total_oil_kg'),
                DB::raw('AVG(settlements.oil_content) as avg_oil_percent'),
                DB::raw('SUM(settlements.bran_value) as total_bran_value'),
                DB::raw('SUM(settlements.net_payable) as total_net_payable')
            )
            ->groupBy('products.id', 'products.product_name', 'products.product_code')
            ->get();
    }

    protected function getGstReport($from, $to, $branchId)
    {
        // Combined summary of GST collected/deducted on Settlements, Invoices, and Debit Notes
        $settlementsQuery = DB::table('settlements')
            ->whereBetween('settlement_date', [$from, $to])
            ->where('status', '!=', 'cancelled');
        if ($branchId) $settlementsQuery->where('branch_id', $branchId);

        $settlementGst = $settlementsQuery->select(
            DB::raw('"Settlement Bills" as document_type'),
            DB::raw('SUM(subtotal_amount) as taxable_value'),
            DB::raw('SUM(cgst_amount) as total_cgst'),
            DB::raw('SUM(sgst_amount) as total_sgst'),
            DB::raw('SUM(igst_amount) as total_igst'),
            DB::raw('SUM(cgst_amount + sgst_amount + igst_amount) as total_gst')
        )->first();

        $debitNotesQuery = DB::table('debit_notes')
            ->whereBetween('date', [$from, $to])
            ->where('status', '!=', 'cancelled');
        if ($branchId) $debitNotesQuery->where('branch_id', $branchId);

        $debitNoteGst = $debitNotesQuery->select(
            DB::raw('"Debit Notes (Rebates)" as document_type'),
            DB::raw('SUM(taxable_amount) as taxable_value'),
            DB::raw('SUM(cgst_amount) as total_cgst'),
            DB::raw('SUM(sgst_amount) as total_sgst'),
            DB::raw('SUM(igst_amount) as total_igst'),
            DB::raw('SUM(cgst_amount + sgst_amount + igst_amount) as total_gst')
        )->first();

        return [
            'settlements_gst' => $settlementGst,
            'debit_notes_gst' => $debitNoteGst,
        ];
    }

    protected function getRebateReport($from, $to, $branchId, $vendorId)
    {
        $query = Settlement::with(['branch', 'vendor'])
            ->where('rebate_amount', '>', 0)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('settlement_date', [$from, $to]);

        if ($branchId) $query->where('branch_id', $branchId);
        if ($vendorId) $query->where('vendor_id', $vendorId);

        return $query->orderBy('settlement_date', 'desc')->get();
    }

    protected function getOutstandingReport($branchId)
    {
        $query = Vendor::with('ledger')->where('status', 'active');
        $vendors = $query->get();

        $data = [];
        foreach ($vendors as $v) {
            $bal = $v->current_balance;
            $data[] = [
                'vendor_id' => $v->id,
                'vendor_code' => $v->vendor_code,
                'vendor_name' => $v->vendor_name,
                'phone' => $v->phone,
                'gstin' => $v->gstin,
                'balance' => $bal,
                'nature' => $bal >= 0 ? 'Payable (Credit)' : 'Receivable (Debit)',
            ];
        }

        return $data;
    }

    protected function getPaymentReport($from, $to, $vendorId)
    {
        $query = VendorLedger::with(['vendor', 'branch'])
            ->whereIn('document_type', ['payment', 'receipt', 'payment_adjustment', 'receipt_adjustment'])
            ->whereBetween('transaction_date', [$from, $to]);

        if ($vendorId) $query->where('vendor_id', $vendorId);

        return $query->orderBy('transaction_date', 'desc')->get();
    }

    protected function exportCsv(string $type, $data)
    {
        $filename = "report_{$type}_" . date('Ymd_His') . ".csv";
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($data, $type) {
            $file = fopen('php://output', 'w');
            
            if ($type === 'settlements') {
                fputcsv($file, ['Settlement No', 'Date', 'Branch', 'Vendor', 'Product', 'Lot No', 'Final Wt (MT)', 'Oil %', 'Oil Wt (KG)', 'Bran Value', 'Rebate', 'Net Payable', 'Status']);
                foreach ($data as $row) {
                    fputcsv($file, [
                        $row->settlement_number,
                        $row->settlement_date,
                        $row->branch->branch_name ?? '',
                        $row->vendor->vendor_name ?? '',
                        $row->product->product_name ?? '',
                        $row->lot_number,
                        $row->final_weight_mt,
                        $row->oil_content,
                        $row->oil_quantity_kg,
                        $row->bran_value,
                        $row->rebate_amount,
                        $row->net_payable,
                        $row->status,
                    ]);
                }
            } elseif ($type === 'outstanding') {
                fputcsv($file, ['Vendor Code', 'Vendor Name', 'Phone', 'GSTIN', 'Outstanding Balance', 'Nature']);
                foreach ($data as $row) {
                    fputcsv($file, [$row['vendor_code'], $row['vendor_name'], $row['phone'], $row['gstin'], $row['balance'], $row['nature']]);
                }
            } else {
                // Generic dump
                if (count($data) > 0) {
                    $keys = array_keys((array)(is_array($data[0]) ? $data[0] : (is_object($data[0]) ? (array)$data[0] : [])));
                    fputcsv($file, $keys);
                    foreach ($data as $row) {
                        fputcsv($file, (array)$row);
                    }
                }
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    protected function exportPdf(string $type, $data, Request $request)
    {
        $company = CompanySetting::first() ?? new CompanySetting([
            'company_name' => 'LAXMI TRADERS',
            'gstin' => '33BWLPK6363A3ZN',
        ]);

        $title = ucwords(str_replace('_', ' ', $type)) . ' Report';
        $html = "<html><head><style>body{font-family:sans-serif;font-size:10px;} table{width:100%;border-collapse:collapse;margin-top:10px;} th,td{border:1px solid #ccc;padding:4px;} th{background:#eee;}</style></head><body>";
        $html .= "<h2>{$company->company_name}</h2><h3>{$title}</h3><p>Generated on " . date('d-M-Y H:i') . "</p>";
        $html .= "<p>Period: " . $request->get('from_date') . " to " . $request->get('to_date') . "</p>";
        $html .= "<table>";

        if ($type === 'settlements') {
            $html .= "<thead><tr><th>No</th><th>Date</th><th>Vendor</th><th>Weight (MT)</th><th>Oil %</th><th>Bran Value</th><th>Net Payable</th></tr></thead><tbody>";
            foreach ($data as $r) {
                $html .= "<tr><td>{$r->settlement_number}</td><td>{$r->settlement_date}</td><td>{$r->vendor->vendor_name}</td><td>{$r->final_weight_mt}</td><td>{$r->oil_content}%</td><td>₹{$r->bran_value}</td><td>₹{$r->net_payable}</td></tr>";
            }
            $html .= "</tbody>";
        } elseif ($type === 'outstanding') {
            $html .= "<thead><tr><th>Code</th><th>Vendor Name</th><th>GSTIN</th><th>Balance (₹)</th><th>Nature</th></tr></thead><tbody>";
            foreach ($data as $r) {
                $html .= "<tr><td>{$r['vendor_code']}</td><td>{$r['vendor_name']}</td><td>{$r['gstin']}</td><td>₹{$r['balance']}</td><td>{$r['nature']}</td></tr>";
            }
            $html .= "</tbody>";
        } else {
            $html .= "<tr><td>Detailed records summary available</td></tr>";
        }

        $html .= "</table></body></html>";

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'landscape');
        return $pdf->stream("{$type}_report.pdf");
    }
}
