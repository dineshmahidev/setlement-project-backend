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
        $branchId = $request->get('branch_id') ?? $request->header('X-Branch-Id');

        // If no explicit branch_id and user is not super admin, filter by user's assigned godowns/branches
        if (!$branchId && $user) {
            $allowed = $user->allowedBranchIds();
            if ($allowed !== null) {
                $branchId = $allowed;
            }
        }

        $applyBranch = function ($query, $column = 'branch_id') use ($branchId) {
            if (!$branchId) return $query;
            if (is_array($branchId)) {
                return $query->whereIn($column, $branchId);
            }
            return $query->where($column, $branchId);
        };

        // Card statistics
        $totalVendors = Vendor::where('status', 'active')->count();

        $totalBranchesQuery = Branch::where('status', 'active');
        if ($branchId) {
            $applyBranch($totalBranchesQuery, 'id');
        }
        $totalBranches = $totalBranchesQuery->count();

        $todaySettlementQuery = Settlement::whereDate('settlement_date', $today)
            ->where('status', '!=', 'cancelled');
        $applyBranch($todaySettlementQuery, 'branch_id');

        $todaySettlementsCount = (clone $todaySettlementQuery)->count();
        $todaySettlementValue = (clone $todaySettlementQuery)->sum('net_payable');

        $debitNoteQuery = DebitNote::where('status', 'draft');
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
        $applyBranch($branchWiseQuery, 'settlements.branch_id');
        $branchWise = $branchWiseQuery->select('branches.branch_name', DB::raw('SUM(settlements.net_payable) as total_value'), DB::raw('COUNT(settlements.id) as count'))
            ->groupBy('branches.id', 'branches.branch_name')
            ->get();

        // Vendor-wise Settlement
        $vendorWiseQuery = DB::table('settlements')
            ->join('vendors', 'settlements.vendor_id', '=', 'vendors.id')
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
            ->where('status', '!=', 'cancelled')
            ->where('settlement_date', '>=', Carbon::now()->subMonths(5)->startOfMonth()->toDateString());
        $applyBranch($monthlyTrendQuery, 'branch_id');
        $monthlyTrend = $monthlyTrendQuery->select(
                DB::raw("DATE_FORMAT(settlement_date, '%Y-%m') as month"),
                DB::raw('SUM(net_payable) as total_value'),
                DB::raw('SUM(bran_value) as bran_value'),
                DB::raw('SUM(rebate_amount) as rebate_value'),
                DB::raw('COUNT(id) as count')
            )
            ->groupBy('month')
            ->orderBy('month', 'asc')
            ->get();

        // Product-wise Settlement
        $productWiseQuery = DB::table('settlements')
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
        $recentSettlementsQuery = Settlement::with(['branch', 'vendor', 'product'])
            ->orderBy('settlement_date', 'desc')
            ->orderBy('id', 'desc');
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
