<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CompanyController extends Controller
{
    public function index(): JsonResponse
    {
        $user = request()->user();
        $allowed = $user->allowedCompanyIds();

        $query = Company::query()->active()->orderBy('sort_order')->orderBy('company_name');

        if ($allowed !== null) {
            $query->whereIn('id', $allowed);
        }

        return response()->json([
            'data' => $query->get()->map(fn ($company) => $this->transform($company)),
        ]);
    }

    /** Admin: list all (including inactive) for management. */
    public function all(): JsonResponse
    {
        $this->guardAdmin();
        return response()->json([
            'data' => Company::query()->orderBy('sort_order')->orderBy('company_name')->get()->map(fn ($c) => $this->transform($c)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->guardAdmin();
        $data = $request->validate([
            'code' => 'required|string|max:30|unique:companies,code',
            'company_name' => 'required|string|max:150',
            'short_name' => 'nullable|string|max:60',
            'trade_name' => 'nullable|string|max:150',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'state_code' => 'nullable|string|max:5',
            'pincode' => 'nullable|string|max:12',
            'gstin' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:150',
            'signature_path' => 'nullable|string',
            'logo_path' => 'nullable|string',
            'logo_transparent_path' => 'nullable|string',
            'banner_path' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',
            'sort_order' => 'nullable|integer',
        ]);
        if (!empty($data['signature_path'])) {
            $data['signature_path'] = $this->processImageData($data['signature_path'], 'sig_new', 'signatures');
        }
        if (!empty($data['logo_path'])) {
            $data['logo_path'] = $this->processImageData($data['logo_path'], 'logo_new', 'logos');
        }
        if (!empty($data['logo_transparent_path'])) {
            $data['logo_transparent_path'] = $this->processImageData($data['logo_transparent_path'], 'logo_trans_new', 'logos');
        }
        if (!empty($data['banner_path'])) {
            $data['banner_path'] = $this->processImageData($data['banner_path'], 'banner_new', 'banners');
        }
        $data['slug'] = Str::slug($request->slug ?: $data['code']);
        $company = Company::create($data);

        return response()->json(['data' => $this->transform($company)], 201);
    }

    public function show(Company $company): JsonResponse
    {
        $allowed = request()->user()->allowedCompanyIds();

        if ($allowed !== null && ! in_array((int) $company->id, array_map('intval', $allowed), true)) {
            abort(403, 'You do not have access to this company.');
        }

        return response()->json(['data' => $this->transform($company)]);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $this->guardAdmin();
        $data = $request->validate([
            'company_name' => 'sometimes|string|max:150',
            'short_name' => 'nullable|string|max:60',
            'trade_name' => 'nullable|string|max:150',
            'tagline' => 'nullable|string|max:255',
            'sub_tagline' => 'nullable|string|max:255',
            'about_us' => 'nullable|string',
            'cms_hero_title' => 'nullable|string|max:255',
            'cms_hero_subtitle' => 'nullable|string|max:255',
            'cms_services' => 'nullable|string',
            'cms_testimonials' => 'nullable|array',
            'cms_testimonials.*.name' => 'nullable|string|max:120',
            'cms_testimonials.*.role' => 'nullable|string|max:120',
            'cms_testimonials.*.message' => 'nullable|string|max:1000',
            'cms_testimonials.*.rating' => 'nullable|integer|min:1|max:5',
            'cms_images' => 'nullable|array',
            'gallery_image_1' => 'nullable|string',
            'gallery_image_2' => 'nullable|string',
            'gallery_image_3' => 'nullable|string',
            'gallery_image_4' => 'nullable|string',
            'landing_slide_1' => 'nullable|string',
            'landing_slide_2' => 'nullable|string',
            'landing_slide_3' => 'nullable|string',
            'landing_feature_image' => 'nullable|string',
            'landing_service_image_1' => 'nullable|string',
            'landing_service_image_2' => 'nullable|string',
            'landing_service_image_3' => 'nullable|string',
            'landing_contact_image_1' => 'nullable|string',
            'landing_contact_image_2' => 'nullable|string',
            'landing_form_image' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'state_code' => 'nullable|string|max:5',
            'pincode' => 'nullable|string|max:12',
            'gstin' => 'nullable|string|max:20',
            'pan' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:30',
            'phone_2' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:150',
            'website' => 'nullable|string|max:255',
            'map_url' => 'nullable|string',
            'bank_name' => 'nullable|string|max:100',
            'branch_bank' => 'nullable|string|max:100',
            'account_number' => 'nullable|string|max:50',
            'ifsc_code' => 'nullable|string|max:20',
            'settlement_prefix' => 'nullable|string|max:20',
            'debit_note_prefix' => 'nullable|string|max:20',
            'invoice_prefix' => 'nullable|string|max:20',
            'terms_conditions' => 'nullable|string',
            'authorized_signatory_title' => 'nullable|string|max:100',
            'logo_path' => 'nullable|string',
            'logo_transparent_path' => 'nullable|string',
            'show_logo_website' => 'sometimes|boolean',
            'show_logo_pdf' => 'sometimes|boolean',
            'banner_path' => 'nullable|string',
            'signature_path' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',
            'sort_order' => 'nullable|integer',
        ]);

        if (!empty($data['signature_path'])) {
            $data['signature_path'] = $this->processImageData($data['signature_path'], 'sig_' . $company->id, 'signatures');
        }
        if (!empty($data['logo_path'])) {
            $data['logo_path'] = $this->processImageData($data['logo_path'], 'logo_' . $company->id, 'logos');
        }
        if (!empty($data['logo_transparent_path'])) {
            $data['logo_transparent_path'] = $this->processImageData($data['logo_transparent_path'], 'logo_trans_' . $company->id, 'logos');
        }
        if (!empty($data['banner_path'])) {
            $data['banner_path'] = $this->processImageData($data['banner_path'], 'banner_' . $company->id, 'banners');
        }
        for ($i = 1; $i <= 4; $i++) {
            $key = 'gallery_image_' . $i;
            if (!empty($data[$key])) {
                $data[$key] = $this->processImageData($data[$key], 'gallery_' . $company->id . '_' . $i, 'gallery');
            }
        }

        $landingImages = [
            'landing_slide_1' => 'slide_1',
            'landing_slide_2' => 'slide_2',
            'landing_slide_3' => 'slide_3',
            'landing_feature_image' => 'feature',
            'landing_service_image_1' => 'service_1',
            'landing_service_image_2' => 'service_2',
            'landing_service_image_3' => 'service_3',
            'landing_contact_image_1' => 'contact_1',
            'landing_contact_image_2' => 'contact_2',
            'landing_form_image' => 'form',
        ];
        foreach ($landingImages as $key => $suffix) {
            if (!empty($data[$key])) {
                $data[$key] = $this->processImageData($data[$key], 'landing_' . $company->id . '_' . $suffix, 'landing');
            }
        }

        if (!empty($data['cms_images']) && is_array($data['cms_images'])) {
            foreach ($data['cms_images'] as $key => $value) {
                if (is_string($value) && str_starts_with($value, 'data:image/')) {
                    $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $key);
                    $data['cms_images'][$key] = $this->processImageData(
                        $value,
                        'cms_' . $company->id . '_' . $safeKey,
                        'website'
                    );
                }
            }
        }

        $company->update($data);

        return response()->json(['data' => $this->transform($company)]);
    }

    /** Public CMS endpoint for website visitors */
    public function publicCms(?string $slug = null): JsonResponse
    {
        if ($slug) {
            $company = Company::query()->where('slug', $slug)->orWhere('code', $slug)->first();
            if (!$company) {
                return response()->json(['success' => false, 'message' => 'Company not found'], 404);
            }
            return response()->json(['success' => true, 'data' => $this->transform($company)]);
        }

        $companies = Company::query()->active()->orderBy('sort_order')->get()->map(fn ($c) => $this->transform($c));
        return response()->json(['success' => true, 'data' => $companies]);
    }

    /** Deactivate (soft) — never deletes; stock/history preserved. */
    public function destroy(Company $company): JsonResponse
    {
        $this->guardAdmin();
        $company->update(['status' => 'inactive']);
        return response()->json(['success' => true, 'message' => 'Company deactivated.']);
    }

    public function uploadSignature(Request $request, Company $company): JsonResponse
    {
        $this->guardAdmin();
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
            'message' => 'Digital signature updated successfully',
            'signature_path' => $path,
            'data' => $this->transform($company->fresh()),
        ]);
    }

    protected function guardAdmin(): void
    {
        $this->guardSuperAdmin();
    }

    private function transform(Company $company): array
    {
        return [
            'id' => $company->id,
            'code' => $company->code,
            'slug' => $company->slug,
            'company_name' => $company->company_name,
            'trade_name' => $company->trade_name,
            'short_name' => $company->short_name,
            'tagline' => $company->tagline,
            'sub_tagline' => $company->sub_tagline,
            'about_us' => $company->about_us,
            'cms_hero_title' => $company->cms_hero_title,
            'cms_hero_subtitle' => $company->cms_hero_subtitle,
            'cms_services' => $company->cms_services,
            'cms_testimonials' => $company->cms_testimonials ?: [],
            'gallery_image_1' => $company->gallery_image_1,
            'gallery_image_2' => $company->gallery_image_2,
            'gallery_image_3' => $company->gallery_image_3,
            'gallery_image_4' => $company->gallery_image_4,
            'landing_slide_1' => $company->landing_slide_1,
            'landing_slide_2' => $company->landing_slide_2,
            'landing_slide_3' => $company->landing_slide_3,
            'landing_feature_image' => $company->landing_feature_image,
            'landing_service_image_1' => $company->landing_service_image_1,
            'landing_service_image_2' => $company->landing_service_image_2,
            'landing_service_image_3' => $company->landing_service_image_3,
            'landing_contact_image_1' => $company->landing_contact_image_1,
            'landing_contact_image_2' => $company->landing_contact_image_2,
            'landing_form_image' => $company->landing_form_image,
            'cms_images' => (object) ($company->cms_images ?: []),
            'address' => $company->address,
            'city' => $company->city,
            'district' => $company->district,
            'state' => $company->state,
            'state_code' => $company->state_code,
            'pincode' => $company->pincode,
            'gstin' => $company->gstin,
            'pan' => $company->pan,
            'phone' => $company->phone,
            'phone_2' => $company->phone_2,
            'email' => $company->email,
            'website' => $company->website,
            'map_url' => $company->map_url,
            'bank_name' => $company->bank_name,
            'branch_bank' => $company->branch_bank,
            'account_number' => $company->account_number,
            'ifsc_code' => $company->ifsc_code,
            'settlement_prefix' => $company->settlement_prefix,
            'debit_note_prefix' => $company->debit_note_prefix,
            'invoice_prefix' => $company->invoice_prefix,
            'terms_conditions' => $company->terms_conditions,
            'authorized_signatory_title' => $company->authorized_signatory_title,
            'logo_path' => $company->logo_path,
            'logo_transparent_path' => $company->logo_transparent_path,
            'show_logo_website' => (bool) $company->show_logo_website,
            'show_logo_pdf' => (bool) $company->show_logo_pdf,
            'banner_path' => $company->banner_path,
            'signature_path' => $company->signature_path,
            'status' => $company->status,
            'sort_order' => $company->sort_order,
            'branches' => $company->branches->map(fn ($b) => [
                'id' => $b->id,
                'company_id' => $b->company_id,
                'branch_name' => $b->branch_name,
                'branch_code' => $b->branch_code,
                'city' => $b->city,
                'phone' => $b->phone,
                'phone_2' => $b->phone_2 ?? null,
            ])->values(),
            'branches_count' => $company->branches()->count(),
        ];
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
                $distRoot = base_path('../frontend/dist');
                if (is_dir($distRoot)) {
                    $distDir = $distRoot . '/' . $folder;
                    if (!is_dir($distDir)) {
                        @mkdir($distDir, 0755, true);
                    }
                    @copy($targetDir . '/' . $filename, $distDir . '/' . $filename);
                }
                return '/' . $folder . '/' . $filename;
            }
        }
        return $value;
    }
}
