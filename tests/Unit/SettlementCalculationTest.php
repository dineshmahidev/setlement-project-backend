<?php

namespace Tests\Unit;

use App\Services\SettlementCalculationService;
use PHPUnit\Framework\TestCase;

class SettlementCalculationTest extends TestCase
{
    protected SettlementCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SettlementCalculationService();
    }

    public function test_exact_oil_calculation_example_1()
    {
        // Spec Example 1
        $data = [
            'final_weight_mt' => 15.095,
            'oil_content' => 13.10,
            'final_unit_rate' => 203.00,
            'rounding_mode' => 'nearest_rupee',
        ];

        $calc = $this->service->calculate($data);

        // 15.095 MT = 15095 KG
        $this->assertEquals(15095.0, $calc['final_weight_kg']);
        
        // 15095 * 13.10 / 100 = 1977.445 KG
        $this->assertEquals(1977.445, $calc['oil_quantity_kg']);
        
        // 1977.445 * 203 = 401421.335 -> 401421 rounded
        $this->assertEquals(401421.0, $calc['bran_value']);
    }

    public function test_exact_oil_calculation_example_2()
    {
        // Spec Example 2
        $data = [
            'final_weight_mt' => 22.800,
            'oil_content' => 25.52,
            'final_unit_rate' => 183.00,
            'rounding_mode' => 'two_decimal',
        ];

        $calc = $this->service->calculate($data);

        // 22.800 MT = 22800 KG
        $this->assertEquals(22800.0, $calc['final_weight_kg']);
        
        // 22800 * 25.52 / 100 = 5818.56 KG
        $this->assertEquals(5818.560, $calc['oil_quantity_kg']);
        
        // 5818.56 * 183 = 1064796.48
        $this->assertEquals(1064796.48, $calc['bran_value']);
    }

    public function test_debit_note_and_rebate_calculation()
    {
        // Debit Note test
        $data = [
            'final_weight_mt' => 15.095,
            'oil_content' => 13.10,
            'final_unit_rate' => 203.00,
            'original_invoice_value' => 412503.00,
            'is_interstate' => false,
            'dn_cgst_rate' => 2.500,
            'dn_sgst_rate' => 2.500,
            'rounding_mode' => 'nearest_rupee',
        ];

        $calc = $this->service->calculate($data);

        // Rebate: 412,503 - 401,421 = 11,082
        $this->assertEquals(11082.0, $calc['rebate_amount']);

        $dn = $calc['debit_note'];
        $this->assertEquals(11082.0, $dn['taxable_amount']);
        
        // CGST 2.5% = 277.05 -> 277
        $this->assertEquals(277.0, $dn['cgst_amount']);
        
        // SGST 2.5% = 277.05 -> 277
        $this->assertEquals(277.0, $dn['sgst_amount']);
        
        // Debit note total = 11082 + 277 + 277 = 11636
        $this->assertEquals(11636.0, $dn['total_amount']);
    }
}
