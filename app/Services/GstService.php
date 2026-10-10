<?php

namespace App\Services;

use App\Models\CompanySetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GstService
{
    /**
     * Lookup GST details from external API or parse state code & PAN
     *
     * @param string $gstin
     * @return array
     */
    public function lookup(string $gstin): array
    {
        $gstin = strtoupper(trim($gstin));
        
        // Basic pattern validation: 15 alphanumeric characters
        // Format: 2 digits (state code) + 10 chars (PAN) + 1 char (entity num) + 'Z' + 1 checksum char
        $isValidFormat = (bool) preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $gstin);
        
        $stateCode = substr($gstin, 0, 2);
        $pan = substr($gstin, 2, 10);
        $stateName = $this->getStateByCode($stateCode);

        $result = [
            'gstin' => $gstin,
            'is_valid_format' => $isValidFormat,
            'pan' => strlen($gstin) >= 12 ? $pan : null,
            'state_code' => $stateCode,
            'state' => $stateName,
            'legal_name' => null,
            'trade_name' => null,
            'address' => null,
            'city' => null,
            'district' => null,
            'pincode' => null,
            'status' => 'Active',
            'api_source' => 'local_parser',
        ];

        // Check if external API credentials are configured in CompanySetting or .env
        $companySetting = CompanySetting::first();
        $apiKey = env('GST_API_KEY') ?: $companySetting?->gst_api_key;
        $apiSecret = env('GST_API_SECRET') ?: $companySetting?->gst_api_secret;
        $apiProvider = env('GST_API_PROVIDER') ?: $companySetting?->gst_api_provider ?: 'generic';
        $apiEnabled = env('GST_API_ENABLED', $companySetting?->gst_api_enabled ?? false);

        if ($apiEnabled && $apiKey && $isValidFormat) {
            try {
                // Configurable endpoint
                $endpoint = env('GST_API_URL', 'https://sheet.gstincheck.co.in/check/' . $apiKey . '/' . $gstin);
                
                $response = Http::timeout(5)->get($endpoint);
                if ($response->successful()) {
                    $data = $response->json();
                    if (!empty($data['data']) || !empty($data['lgnm'])) {
                        $info = $data['data'] ?? $data;
                        $result['legal_name'] = $info['lgnm'] ?? $info['legal_name'] ?? null;
                        $result['trade_name'] = $info['tradeNam'] ?? $info['trade_name'] ?? $result['legal_name'];
                        $result['status'] = $info['sts'] ?? $info['status'] ?? 'Active';
                        $result['state'] = $info['pradr']['addr']['stcd'] ?? $result['state'];
                        $result['city'] = $info['pradr']['addr']['dst'] ?? $info['pradr']['addr']['city'] ?? null;
                        $result['pincode'] = $info['pradr']['addr']['pncd'] ?? null;
                        $result['address'] = $info['pradr']['addr']['bno'] ?? '' . ' ' . ($info['pradr']['addr']['st'] ?? '') . ' ' . ($info['pradr']['addr']['loc'] ?? '');
                        $result['api_source'] = 'live_api';
                    }
                }
            } catch (\Exception $e) {
                Log::warning('GSTIN Lookup API call failed: ' . $e->getMessage());
                // Never block on failure, return parsed data
            }
        }

        return $result;
    }

    /**
     * Map Indian 2-digit GST state codes
     */
    public function getStateByCode(string $code): string
    {
        $states = [
            '01' => 'Jammu and Kashmir',
            '02' => 'Himachal Pradesh',
            '03' => 'Punjab',
            '04' => 'Chandigarh',
            '05' => 'Uttarakhand',
            '06' => 'Haryana',
            '07' => 'Delhi',
            '08' => 'Rajasthan',
            '09' => 'Uttar Pradesh',
            '10' => 'Bihar',
            '11' => 'Sikkim',
            '12' => 'Arunachal Pradesh',
            '13' => 'Nagaland',
            '14' => 'Manipur',
            '15' => 'Mizoram',
            '16' => 'Tripura',
            '17' => 'Meghalaya',
            '18' => 'Assam',
            '19' => 'West Bengal',
            '20' => 'Jharkhand',
            '21' => 'Odisha',
            '22' => 'Chhattisgarh',
            '23' => 'Madhya Pradesh',
            '24' => 'Gujarat',
            '26' => 'Dadra and Nagar Haveli and Daman and Diu',
            '27' => 'Maharashtra',
            '29' => 'Karnataka',
            '30' => 'Goa',
            '31' => 'Lakshadweep',
            '32' => 'Kerala',
            '33' => 'Tamil Nadu',
            '34' => 'Puducherry',
            '35' => 'Andaman and Nicobar Islands',
            '36' => 'Telangana',
            '37' => 'Andhra Pradesh',
            '38' => 'Ladakh',
        ];

        return $states[$code] ?? 'Tamil Nadu';
    }
}
