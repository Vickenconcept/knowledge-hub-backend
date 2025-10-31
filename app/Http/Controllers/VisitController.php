<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VisitController extends Controller
{
    /**
     * List latest visits per device (ip + user_agent), paginated.
     * Super admin only.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user || ($user->role !== 'super_admin')) {
            return response()->json(['error' => 'Forbidden - Super admin only'], 403);
        }

        $perPage = min(max((int) $request->query('per_page', 50), 1), 200);
        $page = max((int) $request->query('page', 1), 1);
        $offset = ($page - 1) * $perPage;

        // Subquery to get the latest created_at per (ip_address, user_agent)
        $sub = DB::table('visits')
            ->select('ip_address', 'user_agent', DB::raw('MAX(created_at) as latest'))
            ->groupBy('ip_address', 'user_agent');

        $base = DB::table('visits as v')
            ->joinSub($sub, 'lv', function ($join) {
                $join->on('v.ip_address', '=', 'lv.ip_address')
                     ->on('v.user_agent', '=', 'lv.user_agent')
                     ->on('v.created_at', '=', 'lv.latest');
            })
            ->leftJoin('users as u', 'u.id', '=', 'v.user_id')
            ->orderByDesc('v.created_at');

        // Total unique devices count
        $totalDevices = DB::table('visits')
            ->select(DB::raw('COUNT(*) as c'))
            ->fromSub($sub, 't')
            ->value('c');

        $rows = $base
            ->skip($offset)
            ->take($perPage)
            ->get([
                'v.id', 'v.created_at', 'v.ip_address', 'v.user_agent', 'v.method', 'v.path', 'v.referrer', 'v.user_id', 'v.org_id',
                'u.email as user_email', 'u.name as user_name'
            ]);

        return response()->json([
            'data' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => (int) $totalDevices,
                'total_pages' => (int) ceil($totalDevices / $perPage)
            ]
        ]);
    }

    /**
     * Stats for charts: last 30 days unique devices by day, and monthly unique devices for last 6 months.
     * Super admin only.
     */
    public function stats(Request $request)
    {
        $user = $request->user();
        if (!$user || ($user->role !== 'super_admin')) {
            return response()->json(['error' => 'Forbidden - Super admin only'], 403);
        }

        // Last 30 days by day (unique ip+ua)
        $daily = DB::table('visits')
            ->select(DB::raw('DATE(created_at) as d'), DB::raw('COUNT(DISTINCT CONCAT(ip_address, "|", user_agent)) as c'))
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('d')
            ->get();

        // Last 6 months by month
        $monthly = DB::table('visits')
            ->select(DB::raw('DATE_FORMAT(created_at, "%Y-%m") as m'), DB::raw('COUNT(DISTINCT CONCAT(ip_address, "|", user_agent)) as c'))
            ->where('created_at', '>=', now()->subMonths(6)->startOfMonth())
            ->groupBy(DB::raw('DATE_FORMAT(created_at, "%Y-%m")'))
            ->orderBy('m')
            ->get();

        return response()->json([
            'daily' => $daily,
            'monthly' => $monthly,
        ]);
    }
}


