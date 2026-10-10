<?php

namespace App\Http\Controllers;

use App\Models\ChargeType;
use Illuminate\Http\Request;

class ChargeTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = ChargeType::orderBy('sort_order')->orderBy('name');
        if ($request->boolean('active_only')) {
            $query->where('active', true);
        }
        return response()->json($query->get());
    }
}
