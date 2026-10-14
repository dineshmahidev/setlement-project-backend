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

        $data = $request->only([
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
            'logo_path',
            'signature_path',
        ]);

        if (!empty($data['signature_path'])) {
            $data['signature_path'] = $this->processImageData($data['signature_path'], 'sig_' . $company->id, 'signatures');
        }
        if (!empty($data['logo_path'])) {
            $data['logo_path'] = $this->processImageData($data['logo_path'], 'logo_' . $company->id, 'logos');
        }

        $company->update($data);

        AuditLog::logAction('updated', 'Setting', $company->id, 'Company:' . $company->code, $old, $company->toArray(), 'Company settings updated');

        return response()->json([
            'success' => true,
            'message' => 'Settings updated successfully',
            'data' => $company->fresh(),
        ]);
    }

    public function uploadSignature(Request $request)
    {
        $company = $this->activeCompany() ?? Company::query()->active()->orderBy('sort_order')->firstOrFail();
        if (! $request->user()->isSuperAdmin() && ! $request->user()->canPermission('settings.edit') && ! $request->user()->canPermission('settings.manage')) {
            abort(403, 'You are not allowed to update settings.');
        }

        $request->validate([
            'signature' => 'required|image|mimes:png,jpg,jpeg,webp,svg|max:4096',
        ]);

        $file = $request->file('signature');
        $filename = 'sig_' . $company->id . '_' . time() . '.' . $file->getClientOriginalExtension();
        $targetDir = public_path('signatures');
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        $file->move($targetDir, $filename);
        $path = '/signatures/' . $filename;

        $frontendDir = base_path('../frontend/public/signatures');
        if (is_dir($frontendDir)) {
            @copy($targetDir . '/' . $filename, $frontendDir . '/' . $filename);
        }

        $company->update(['signature_path' => $path]);

        return response()->json([
            'success' => true,
            'message' => 'Digital signature uploaded successfully',
            'signature_path' => $path,
            'data' => $company->fresh(),
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

    private function processImageData(?string $value, string $prefix, string $folder = 'signatures'): ?string
    {
        if (empty($value)) return $value;
        if (!str_starts_with($value, 'data:image/')) return $value;

        if (preg_match('/^data:image\/([a-zA-Z0-9\+\-\.]+);base64,/', $value, $matches)) {
            $mime = strtolower($matches[1]);
            $ext = 'png';
            if (str_contains($mime, 'jpeg') || str_contains($mime, 'jpg')) $ext = 'jpg';
            elseif (str_contains($mime, 'svg')) $ext = 'svg';
            elseif (str_contains($mime, 'webp')) $ext = 'webp';
            elseif (str_contains($mime, 'png')) $ext = 'png';
            $data = substr($value, strpos($value, ',') + 1);
            $decoded = base64_decode($data);
            if ($decoded !== false) {
                $filename = $prefix . '_' . time() . '.' . $ext;
                $targetDir = public_path($folder);
                if (!is_dir($targetDir)) {
                    @mkdir($targetDir, 0755, true);
                }
                file_put_contents($targetDir . '/' . $filename, $decoded);

                $frontendDir = base_path('../frontend/public/' . $folder);
                if (is_dir($frontendDir)) {
                    @copy($targetDir . '/' . $filename, $frontendDir . '/' . $filename);
                }
                return '/' . $folder . '/' . $filename;
            }
        }
        return $value;
    }
}
