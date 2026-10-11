<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\DocumentSequence;
use App\Models\FinancialYear;
use App\Models\GstRate;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index()
    {
        $company = $this->activeCompany() ?? Company::query()->active()->orderBy('sort_order')->first();

        $gstRates = GstRate::all();
        $financialYears = FinancialYear::orderBy('start_date', 'desc')->get();

        $sequences = DocumentSequence::with('branch');
        $branchIds = $this->allowedBranchIds();
        if (is_array($branchIds)) {
            $sequences->whereIn('branch_id', $branchIds);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'company' => $company,
                'gst_rates' => $gstRates,
                'financial_years' => $financialYears,
                'document_sequences' => $sequences->get(),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $company = $this->activeCompany() ?? Company::query()->active()->orderBy('sort_order')->firstOrFail();

        if (! $request->user()->isSuperAdmin() && ! $request->user()->canPermission('settings.edit') && ! $request->user()->canPermission('settings.manage')) {
            abort(403, 'You are not allowed to update settings.');
        }

        $old = $company->toArray();

        $company->update($request->only([
            'company_name',
            'tagline',
            'sub_tagline',
            'trade_name',
            'short_name',
            'address',
            'city',
            'district',
            'state',
            'state_code',
            'pincode',
            'gstin',
            'cin',
            'pan',
            'phone',
            'email',
            'website',
            'bank_name',
            'account_number',
            'ifsc_code',
            'branch_bank',
            'rounding_mode',
            'gst_api_provider',
            'gst_api_key',
            'gst_api_secret',
            'gst_api_enabled',
            'settlement_prefix',
            'debit_note_prefix',
            'invoice_prefix',
            'terms_conditions',
            'authorized_signatory_title',
        ]));

        AuditLog::logAction('updated', 'Setting', $company->id, 'Company:' . $company->code, $old, $company->toArray(), 'Company settings updated');

        return response()->json([
            'success' => true,
            'message' => 'Settings updated successfully',
            'data' => $company,
        ]);
    }

    public function storeGstRate(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'cgst_rate' => 'required|numeric|min:0|max:100',
            'sgst_rate' => 'required|numeric|min:0|max:100',
            'igst_rate' => 'required|numeric|min:0|max:100',
            'is_default' => 'nullable|boolean',
        ]);

        if ($request->boolean('is_default')) {
            GstRate::where('id', '>', 0)->update(['is_default' => false]);
        }

        $gstRate = GstRate::create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'GST rate created successfully',
            'data' => $gstRate,
        ], 201);
    }
}
