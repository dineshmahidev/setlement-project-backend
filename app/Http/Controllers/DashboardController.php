<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Settlement;
use App\Models\Vendor;
use App\Models\VendorLedger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $today = Carbon::today()->toDateString();
        $startDate = $request->get('from_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->get('to_date', Carbon::now()->endOfMonth()->toDateString());

        $user = $request->user();
        $companyId = $this->activeCompanyId();
        $companyBranchIds = $companyId ? Branch::where('company_id', $companyId)->pluck('id')->all() : null;
        $allowedBranchIds = $user ? $user->allowedBranchIds($companyId) : null;
        $explicitBranch = $request->get('branch_id');

        // Resolve the visible branch set. Fail closed: an empty array means
        // "nothing visible in this company" (filters to zero rows). Only null
        // means "unrestricted" (super admin with no company context).
        if ($explicitBranch) {
            $branchId = is_array($allowedBranchIds)
                ? array_values(array_intersect(array_map('intval', (array) $explicitBranch), array_map('intval', $allowedBranchIds)))
                : $explicitBranch;
        } elseif ($companyBranchIds !== null) {
            // Company context: restrict to this company's branches (intersected with the user's allowed set).
            $branchId = is_array($allowedBranchIds)
                ? array_values(array_intersect($companyBranchIds, array_map('intval', $allowedBranchIds)))
                : $companyBranchIds;
        } else {
            // No company context (super admin global view): fall back to the user's allowed branches.
            $branchId = $allowedBranchIds;
        }

        $applyBranch = function ($query, $column = 'branch_id') use ($branchId) {
            if (is_array($branchId)) {
                // Empty array => whereIn([]) => 0 = 1 => no rows (fail closed).
                return $query->whereIn($column, $branchId);
            }
            if ($branchId !== null && $branchId !== '') {
                return $query->where($column, $branchId);
            }
            return $query; // unrestricted only when no company context
        };

        // Defence in depth: settlements/debit notes always follow their branch's company.
        $applyCompanyBranch = function ($query) use ($companyId) {
            if ($companyId !== null) {
                $query->whereHas('branch', fn ($q) => $q->where('company_id', $companyId));
            }
            return $query;
        };

        $applyCompany = function ($query, $column = 'company_id') {
            return $this->scopeCompany($query, $column);
        };

        // Card statistics
        $totalVendors = $applyCompany(Vendor::where('status', 'active'))->count();

        $totalBranchesQuery = $applyCompany(Branch::where('status', 'active'));
        $applyBranch($totalBranchesQuery, 'id');
        $totalBranches = $totalBranchesQuery->count();

        $todaySettlementQuery = Settlement::whereDate('settlement_date', $today)
            ->where('status', '!=', 'cancelled')
            ->whereHas('vendor', fn ($q) => $q->where('status', 'active'));
        $applyCompanyBranch($todaySettlementQuery);
        $applyBranch($todaySettlementQuery, 'branch_id');

        $todaySettlementsCount = (clone $todaySettlementQuery)->count();
        $todaySettlementValue = (clone $todaySettlementQuery)->sum('net_payable');

        // Total settlements in date range
        $periodSettlementQuery = Settlement::where('status', '!=', 'cancelled')
            ->whereBetween('settlement_date', [$startDate, $endDate])
            ->whereHas('vendor', fn ($q) => $q->where('status', 'active'));
        $applyCompanyBranch($periodSettlementQuery);
        $applyBranch($periodSettlementQuery, 'branch_id');
        $totalSettlementsCount = (clone $periodSettlementQuery)->count();
        $totalSettlementsValue = (clone $periodSettlementQuery)->sum('net_payable');

        // Total debit notes in date range
        $debitQuery = DebitNote::where('status', '!=', 'cancelled')
            ->whereHas('vendor', fn ($q) => $q->where('status', 'active'))
            ->where(function ($q) {
                $q->where('transaction_type', 'debit')
                  ->orWhereNull('transaction_type');
            })
            ->whereBetween('date', [$startDate, $endDate]);
        $applyCompanyBranch($debitQuery);
        $applyBranch($debitQuery, 'branch_id');
        $totalDebitCount = (clone $debitQuery)->count();
        $totalDebitValue = (clone $debitQuery)->sum('total_amount');

        // Total credit notes in date range
        $creditQuery = DebitNote::where('status', '!=', 'cancelled')
            ->whereHas('vendor', fn ($q) => $q->where('status', 'active'))
            ->where('transaction_type', 'credit')
            ->whereBetween('date', [$startDate, $endDate]);
        $applyCompanyBranch($creditQuery);
        $applyBranch($creditQuery, 'branch_id');
        $totalCreditCount = (clone $creditQuery)->count();
        $totalCreditValue = (clone $creditQuery)->sum('total_amount');

        $debitNoteQuery = DebitNote::where('status', 'draft');
        $applyCompanyBranch($debitNoteQuery);
        $applyBranch($debitNoteQuery, 'branch_id');
        $pendingDebitNotesCount = $debitNoteQuery->count();

        // Calculate Total Outstanding / Balance across vendors
        $latestBalancesQuery = DB::table('vendor_ledger as vl1')
            ->select('vl1.vendor_id', 'vl1.balance')
            ->whereRaw('vl1.id = (SELECT MAX(vl2.id) FROM vendor_ledger vl2 WHERE vl2.vendor_id = vl1.vendor_id)');
        $applyBranch($latestBalancesQuery, 'vl1.branch_id');
        $latestBalances = $latestBalancesQuery->get();

        $totalPayable = 0;
        $totalReceivable = 0;
        foreach ($latestBalances as $lb) {
            $bal = (float)$lb->balance;
            if ($bal > 0) {
                $totalPayable += $bal;
            } else {
                $totalReceivable += abs($bal);
            }
        }

        // Branch/Godown-wise Settlement
        $branchWiseQuery = DB::table('settlements')
            ->join('branches', 'settlements.branch_id', '=', 'branches.id')
            ->where('settlements.status', '!=', 'cancelled')
            ->whereBetween('settlements.settlement_date', [$startDate, $endDate]);
        if ($companyId !== null) {
            $branchWiseQuery->where('branches.company_id', $companyId);
        }
        $applyBranch($branchWiseQuery, 'settlements.branch_id');
        $branchWise = $branchWiseQuery->select('branches.branch_name', DB::raw('SUM(settlements.net_payable) as total_value'), DB::raw('COUNT(settlements.id) as count'))
            ->groupBy('branches.id', 'branches.branch_name')
            ->get();

        // Vendor-wise Settlement
        $vendorWiseQuery = DB::table('settlements')
            ->join('vendors', 'settlements.vendor_id', '=', 'vendors.id')
            ->where('vendors.status', 'active')
            ->where('settlements.status', '!=', 'cancelled')
            ->whereBetween('settlements.settlement_date', [$startDate, $endDate]);
        $applyBranch($vendorWiseQuery, 'settlements.branch_id');
        $vendorWise = $vendorWiseQuery->select('vendors.vendor_name', DB::raw('SUM(settlements.net_payable) as total_value'), DB::raw('COUNT(settlements.id) as count'))
            ->groupBy('vendors.id', 'vendors.vendor_name')
            ->orderByDesc('total_value')
            ->limit(10)
            ->get();

        // Monthly Settlement Trend
        $monthlyTrendQuery = DB::table('settlements')
            ->join('vendors', 'settlements.vendor_id', '=', 'vendors.id')
            ->where('vendors.status', 'active')
            ->where('settlements.status', '!=', 'cancelled')
            ->where('settlements.settlement_date', '>=', Carbon::now()->subMonths(5)->startOfMonth()->toDateString());
        $applyBranch($monthlyTrendQuery, 'settlements.branch_id');
        $monthlyTrend = $monthlyTrendQuery->select(
                DB::raw("DATE_FORMAT(settlements.settlement_date, '%Y-%m') as month"),
                DB::raw('SUM(settlements.net_payable) as total_value'),
                DB::raw('SUM(settlements.bran_value) as bran_value'),
                DB::raw('SUM(settlements.rebate_amount) as rebate_value'),
                DB::raw('COUNT(settlements.id) as count')
            )
            ->groupBy('month')
            ->orderBy('month', 'asc')
            ->get();

        // Product-wise Settlement
        $productWiseQuery = DB::table('settlements')
            ->join('vendors', 'settlements.vendor_id', '=', 'vendors.id')
            ->where('vendors.status', 'active')
            ->leftJoin('products', 'settlements.product_id', '=', 'products.id')
            ->where('settlements.status', '!=', 'cancelled')
            ->whereBetween('settlements.settlement_date', [$startDate, $endDate]);
        $applyBranch($productWiseQuery, 'settlements.branch_id');
        $productWise = $productWiseQuery->select(
                DB::raw('COALESCE(products.product_name, "Rice Bran Grade II") as product_name'),
                DB::raw('SUM(settlements.net_payable) as total_value'),
                DB::raw('SUM(settlements.final_weight_mt) as total_weight_mt'),
                DB::raw('COUNT(settlements.id) as count')
            )
            ->groupBy('products.id', 'products.product_name')
            ->get();

        // Recent Settlements
        $recentSettlementsQuery = Settlement::whereHas('vendor', fn ($q) => $q->where('status', 'active'))
            ->with(['branch', 'vendor', 'product', 'debitNotes:id,settlement_id,transaction_type,status,total_amount'])
            ->orderBy('settlement_date', 'desc')
            ->orderBy('id', 'desc');
        $applyCompanyBranch($recentSettlementsQuery);
        $applyBranch($recentSettlementsQuery, 'branch_id');
        $recentSettlements = $recentSettlementsQuery->limit(8)->get();

        return response()->json([
            'success' => true,
            'data' => [
                'cards' => [
                    'total_vendors' => $totalVendors,
                    'total_branches' => $totalBranches,
                    'today_settlements' => $todaySettlementsCount,
                    'today_settlement_value' => $todaySettlementValue,
                    'total_settlements' => $totalSettlementsCount,
                    'total_settlement_value' => $totalSettlementsValue,
                    'total_debit_count' => $totalDebitCount,
                    'total_debit_value' => $totalDebitValue,
                    'total_credit_count' => $totalCreditCount,
                    'total_credit_value' => $totalCreditValue,
                    'pending_debit_notes' => $pendingDebitNotesCount,
                    'total_payable' => $totalPayable,
                    'total_receivable' => $totalReceivable,
                ],
                'charts' => [
                    'branch_wise' => $branchWise,
                    'vendor_wise' => $vendorWise,
                    'monthly_trend' => $monthlyTrend,
                    'product_wise' => $productWise,
                ],
                'recent_settlements' => $recentSettlements,
            ],
        ]);
    }
}
