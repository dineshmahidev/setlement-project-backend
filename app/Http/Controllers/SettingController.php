<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\DocumentSequence;
use App\Models\FinancialYear;
use App\Models\GstRate;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index()
    {
        $company = CompanySetting::firstOrCreate([], [
            'company_name' => 'LAXMI TRADERS',
            'trade_name' => 'LAXMI TRADERS',
            'address' => 'No 54 – PALLAVARAYAN PALAYAM, VEERANAMPALAYAM, P.O., KANGEYAM',
            'city' => 'KANGEYAM',
            'district' => 'Tirupur',
            'state' => 'Tamil Nadu',
            'pincode' => '620010',
            'gstin' => '33BWLPK6363A3ZN',
            'cin' => '',
            'pan' => 'BWLPK6363A',
            'phone' => '+91 98765 43210',
            'email' => 'accounts@laxmitraders.com',
            'website' => 'www.laxmitraders.com',
            'bank_name' => 'State Bank of India',
            'account_number' => '12345678901234',
            'ifsc_code' => 'SBIN0001234',
            'branch_bank' => 'Kangeyam Branch',
            'rounding_mode' => 'nearest_rupee',
            'settlement_prefix' => 'SB',
            'debit_note_prefix' => 'DN',
            'credit_note_prefix' => 'CN',
            'invoice_prefix' => 'INV',
            'authorized_signatory_title' => 'For LAXMI TRADERS',
        ]);

        $gstRates = GstRate::all();
        $financialYears = FinancialYear::orderBy('start_date', 'desc')->get();
        $sequences = DocumentSequence::with('branch')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'company' => $company,
                'gst_rates' => $gstRates,
                'financial_years' => $financialYears,
                'document_sequences' => $sequences,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $company = CompanySetting::firstOrCreate([]);
        $old = $company->toArray();

        $company->update($request->only([
            'company_name',
            'trade_name',
            'address',
            'city',
            'district',
            'state',
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

        AuditLog::logAction('updated', 'Setting', $company->id, 'CompanySettings', $old, $company->toArray(), 'Settings updated');

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
