<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    /**
     * The three real companies of the group.
     */
    public const COMPANIES = [
        [
            'code' => 'laxmi',
            'slug' => 'laxmi',
            'company_name' => 'LAXMI TRADERS',
            'tagline' => 'AGRO PRODUCTS & GRAINS',
            'sub_tagline' => 'Quality Grains | Trusted Trade | Sustainable Tomorrow',
            'short_name' => 'Laxmi',
            'address' => 'Ariyamangalam',
            'city' => 'Tiruchirappalli',
            'district' => 'Tiruchirappalli',
            'state' => 'Tamil Nadu',
            'state_code' => '33',
            'pincode' => '620010',
            'gstin' => '33BWLKP6363A3ZN',
            'phone' => '0431-2456789',
            'email' => 'laxmitraders@example.com',
            'settlement_prefix' => 'LT',
            'sort_order' => 1,
            'branches' => [
                ['BR-LT01', 'LAXMI TRADERS (Ariyamangalam)'],
                ['BR-LT02', 'LAXMI TRADERS (Trichy Bazaar)'],
            ],
        ],
        [
            'code' => 'sm',
            'slug' => 'sm',
            'company_name' => 'S M TRADERS',
            'tagline' => 'RICE TRADING & SUPPLY',
            'sub_tagline' => 'Trusted Traders | Honest Weighing | Fair Pricing',
            'short_name' => 'S M',
            'address' => 'Ariyamangalam',
            'city' => 'Tiruchirappalli',
            'district' => 'Tiruchirappalli',
            'state' => 'Tamil Nadu',
            'state_code' => '33',
            'pincode' => '620010',
            'gstin' => '33DTNPM3407G1ZQ',
            'phone' => '0431-2456790',
            'email' => 'smtraders@example.com',
            'settlement_prefix' => 'SM',
            'sort_order' => 2,
            'branches' => [
                ['BR-SM01', 'S M TRADERS (Ariyamangalam)'],
            ],
        ],
        [
            'code' => 'shriee',
            'slug' => 'shriee',
            'company_name' => 'SHRIEE FEEDS',
            'tagline' => 'ANIMAL FEEDS & NUTRITION',
            'sub_tagline' => 'Quality Feed | Better Yield | Healthier Stock',
            'short_name' => 'Shriee',
            'address' => 'Manapparai',
            'city' => 'Manapparai',
            'district' => 'Tiruchirappalli',
            'state' => 'Tamil Nadu',
            'state_code' => '33',
            'pincode' => '621306',
            'gstin' => '33BOZPS3759G1Z5',
            'phone' => '0431-2456791',
            'email' => 'shrieefeeds@example.com',
            'settlement_prefix' => 'SF',
            'sort_order' => 3,
            'branches' => [
                ['BR-SF01', 'SHRIEE FEEDS (Manapparai)'],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::COMPANIES as $data) {
            $branches = $data['branches'];
            unset($data['branches']);

            $company = Company::updateOrCreate(
                ['code' => $data['code']],
                $data + ['status' => 'active']
            );

            foreach ($branches as [$code, $name]) {
                Branch::updateOrCreate(
                    ['branch_code' => $code],
                    [
                        'company_id' => $company->id,
                        'branch_name' => $name,
                        'address' => $company->address,
                        'city' => $company->city,
                        'district' => $company->district,
                        'state' => $company->state,
                        'pincode' => $company->pincode,
                        'gstin' => $company->gstin,
                        'phone' => $company->phone,
                        'email' => $company->email,
                        'status' => 'active',
                    ]
                );
            }
        }
    }
}
