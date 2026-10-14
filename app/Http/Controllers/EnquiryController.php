<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Enquiry;
use App\Models\WebsiteVisit;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnquiryController extends Controller
{
    /**
     * Public endpoint: Store enquiry from Website (Home, Contact, Popup modal, etc.)
     */
    public function publicStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'phone' => 'required|string|max:30',
            'email' => 'nullable|email|max:150',
            'company_name' => 'nullable|string|max:150',
            'requirement' => 'nullable|string|max:150',
            'message' => 'nullable|string|max:3000',
            'source' => 'nullable|string|max:50',
            'company_slug' => 'nullable|string|max:100',
            'company_id' => 'nullable|integer',
        ]);

        $companyId = $validated['company_id'] ?? null;
        if (!$companyId && !empty($validated['company_slug'])) {
            $company = Company::where('slug', $validated['company_slug'])
                ->orWhere('code', $validated['company_slug'])
                ->first();
            if ($company) {
                $companyId = $company->id;
            }
        }

        // If company is still null, try matching requirement
        if (!$companyId && !empty($validated['requirement'])) {
            $req = strtolower($validated['requirement']);
            if (str_contains($req, 'laxmi')) {
                $companyId = Company::where('slug', 'laxmi-traders')->orWhere('code', 'LT')->value('id');
            } elseif (str_contains($req, 'sm') || str_contains($req, 's m')) {
                $companyId = Company::where('slug', 'sm-traders')->orWhere('code', 'SMT')->value('id');
            } elseif (str_contains($req, 'shriee') || str_contains($req, 'feed')) {
                $companyId = Company::where('slug', 'shriee-feeds')->orWhere('code', 'SF')->value('id');
            }
        }

        $enquiry = Enquiry::create([
            'company_id' => $companyId,
            'name' => $validated['name'],
            'company_name' => $validated['company_name'] ?? null,
            'phone' => $validated['phone'],
            'email' => $validated['email'] ?? null,
            'requirement' => $validated['requirement'] ?? 'General Enquiry',
            'message' => $validated['message'] ?? null,
            'source' => $validated['source'] ?? 'website',
            'status' => 'new',
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return response()->json([
            'success' => true,
            'message' => "Thank you for reaching out! We have received your enquiry. We will contact you within 1 business day.",
            'sla_notice' => "We will contact within 1 day",
            'data' => $enquiry,
        ], 201);
    }

    /**
     * Public endpoint: Track website page visit
     */
    public function publicTrackVisit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|string|max:100',
            'page_url' => 'required|string|max:500',
            'page_title' => 'nullable|string|max:255',
            'referrer' => 'nullable|string|max:500',
        ]);

        $ua = (string) $request->userAgent();
        $deviceType = 'desktop';
        if (preg_match('/(tablet|ipad|playbook)|(android(?!.*(mobi|opera mini)))/i', $ua)) {
            $deviceType = 'tablet';
        } elseif (preg_match('/(up.browser|up.link|mmp|symbian|smartphone|midp|wap|phone|android|iemobile)/i', $ua)) {
            $deviceType = 'mobile';
        }

        $browser = 'Other';
        if (preg_match('/edg/i', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/chrome|crios/i', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/firefox|fxios/i', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/safari/i', $ua)) {
            $browser = 'Safari';
        } elseif (preg_match('/msie|trident/i', $ua)) {
            $browser = 'Internet Explorer';
        }

        WebsiteVisit::create([
            'session_id' => $validated['session_id'],
            'page_url' => $validated['page_url'],
            'page_title' => $validated['page_title'] ?? null,
            'referrer' => $validated['referrer'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => substr($ua, 0, 500),
            'device_type' => $deviceType,
            'browser' => $browser,
            'created_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Panel endpoint: List enquiries with filters and status counters
     */
    public function index(Request $request): JsonResponse
    {
        $query = Enquiry::with('company:id,company_name,short_name,code');

        // Optional company filter
        $activeCompanyId = $this->activeCompanyId();
        if ($activeCompanyId) {
            $query->where(function ($q) use ($activeCompanyId) {
                $q->where('company_id', $activeCompanyId)->orWhereNull('company_id');
            });
        } elseif ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        // Status filter
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Search filter
        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('phone', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%")
                    ->orWhere('company_name', 'like', "%{$s}%")
                    ->orWhere('requirement', 'like', "%{$s}%")
                    ->orWhere('message', 'like', "%{$s}%");
            });
        }

        // Date range
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $enquiries = $query->orderByDesc('created_at')->get();

        // Calculate summary statistics
        $statsQuery = Enquiry::query();
        if ($activeCompanyId) {
            $statsQuery->where(function ($q) use ($activeCompanyId) {
                $q->where('company_id', $activeCompanyId)->orWhereNull('company_id');
            });
        }

        $allStats = (clone $statsQuery)->get();
        $today = Carbon::today();

        $stats = [
            'total' => $allStats->count(),
            'new' => $allStats->where('status', 'new')->count(),
            'contacted' => $allStats->where('status', 'contacted')->count(),
            'in_progress' => $allStats->where('status', 'in_progress')->count(),
            'converted' => $allStats->where('status', 'converted')->count(),
            'closed' => $allStats->where('status', 'closed')->count(),
            'today' => $allStats->filter(fn ($e) => $e->created_at >= $today)->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $enquiries,
            'stats' => $stats,
        ]);
    }

    /**
     * Panel endpoint: Show single enquiry
     */
    public function show(Enquiry $enquiry): JsonResponse
    {
        $enquiry->load('company:id,company_name,short_name,code');
        return response()->json([
            'success' => true,
            'data' => $enquiry,
            'sla_notice' => 'We will contact within 1 day',
        ]);
    }

    /**
     * Panel endpoint: Update status / notes of an enquiry
     */
    public function update(Request $request, Enquiry $enquiry): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'sometimes|string|in:new,contacted,in_progress,converted,closed',
            'notes' => 'nullable|string|max:3000',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if (isset($validated['status']) && $validated['status'] === 'contacted' && !$enquiry->contacted_at) {
            $enquiry->contacted_at = now();
        }

        $enquiry->update($validated);
        $enquiry->load('company:id,company_name,short_name,code');

        return response()->json([
            'success' => true,
            'message' => 'Enquiry updated successfully',
            'data' => $enquiry,
        ]);
    }

    /**
     * Panel endpoint: Delete enquiry
     */
    public function destroy(Enquiry $enquiry): JsonResponse
    {
        $enquiry->delete();
        return response()->json([
            'success' => true,
            'message' => 'Enquiry removed successfully',
        ]);
    }

    /**
     * Panel endpoint: Get traffic stats & visitor analytics
     */
    public function getTrafficStats(Request $request): JsonResponse
    {
        $today = Carbon::today();
        $totalVisits = WebsiteVisit::count();
        $uniqueVisitors = WebsiteVisit::distinct('session_id')->count('session_id');

        $visitsToday = WebsiteVisit::where('created_at', '>=', $today)->count();
        $uniqueToday = WebsiteVisit::where('created_at', '>=', $today)->distinct('session_id')->count('session_id');

        // Top Pages
        $topPages = WebsiteVisit::select('page_url', 'page_title', DB::raw('count(*) as views'))
            ->groupBy('page_url', 'page_title')
            ->orderByDesc('views')
            ->limit(8)
            ->get();

        // Devices
        $deviceBreakdown = WebsiteVisit::select('device_type', DB::raw('count(*) as count'))
            ->groupBy('device_type')
            ->get();

        // Daily trend over last 14 days
        $days = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i)->toDateString();
            $label = Carbon::today()->subDays($i)->format('d M');
            $days[$date] = [
                'date' => $date,
                'label' => $label,
                'views' => 0,
                'unique' => 0,
            ];
        }

        $dailyViews = WebsiteVisit::select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'))
            ->where('created_at', '>=', Carbon::today()->subDays(13)->startOfDay())
            ->groupBy('date')
            ->get();

        foreach ($dailyViews as $row) {
            if (isset($days[$row->date])) {
                $days[$row->date]['views'] = (int) $row->count;
            }
        }

        $dailyUnique = WebsiteVisit::select(DB::raw('DATE(created_at) as date'), DB::raw('count(DISTINCT session_id) as count'))
            ->where('created_at', '>=', Carbon::today()->subDays(13)->startOfDay())
            ->groupBy('date')
            ->get();

        foreach ($dailyUnique as $row) {
            if (isset($days[$row->date])) {
                $days[$row->date]['unique'] = (int) $row->count;
            }
        }

        // Recent Visits (up to 20)
        $recentVisits = WebsiteVisit::orderByDesc('created_at')->limit(20)->get();

        // Total enquiries and conversion rate
        $totalLeads = Enquiry::count();
        $conversionRate = $uniqueVisitors > 0 ? round(($totalLeads / $uniqueVisitors) * 100, 1) : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'total_visits' => $totalVisits,
                'unique_visitors' => $uniqueVisitors,
                'visits_today' => $visitsToday,
                'unique_today' => $uniqueToday,
                'total_leads' => $totalLeads,
                'conversion_rate' => $conversionRate,
                'top_pages' => $topPages,
                'device_breakdown' => $deviceBreakdown,
                'daily_trends' => array_values($days),
                'recent_visits' => $recentVisits,
            ],
        ]);
    }
}
