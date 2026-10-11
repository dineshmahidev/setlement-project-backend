<?php

namespace App\Services;

use App\Models\Company;

class SettlementCalculationService
{
    /**
     * Calculate all settlement values according to agro industry formulas
     *
     * @param array $data
     * @return array
     */
    public function calculate(array $data): array
    {
        $roundingMode = $data['rounding_mode'] ?? null;
        if (!$roundingMode) {
            $roundingMode = Company::current()?->rounding_mode ?? 'nearest_rupee';
        }

        // 1. Final Weight in MT & KG
        $netWeightMt = (float)($data['net_weight_mt'] ?? 0);
        $juteBag = (float)($data['jute_bag'] ?? 0);
        $ppBag = (float)($data['pp_bag'] ?? 0);
        
        // If final_weight_mt is not explicitly provided, calculate: net_weight_mt - deductions or use net_weight_mt
        $finalWeightMt = isset($data['final_weight_mt']) && (float)$data['final_weight_mt'] > 0
            ? (float)$data['final_weight_mt']
            : $netWeightMt;

        // Final Weight (KG) = Final Weight (MT) × 1000
        $finalWeightKg = $finalWeightMt * 1000.0;

        // 2. Oil Content (%) & Rates
        $oilContent = (float)($data['oil_content'] ?? 0);
        $contractUnitRate = (float)($data['contract_unit_rate'] ?? 0);
        $finalUnitRate = isset($data['final_unit_rate']) && (float)$data['final_unit_rate'] > 0 
            ? (float)$data['final_unit_rate'] 
            : $contractUnitRate;

        // Rate basis: 'per_10kg', 'per_kg', 'per_mt', 'per_quintal'
        $rateBasis = $data['rate_basis'] ?? null;
        if (!$rateBasis) {
            $rateBasis = ($finalUnitRate >= 500) ? 'per_10kg' : 'per_kg';
        }

        // 3. EXACT OIL & BRAN VALUE CALCULATION
        // Oil Quantity (KG) = Final Weight (KG) × Oil Content / 100
        $oilQuantityKg = ($finalWeightKg * $oilContent) / 100.0;

        // Formula: Final Weight (MT) × Final Unit Rate × Oil Content
        $rawBranValue = $finalWeightMt * $finalUnitRate * $oilContent;
        $branValue = $this->applyRounding($rawBranValue, $roundingMode);

        // 4. Charges & Deductions
        $bagAmount = (float)($data['bag_amount'] ?? 0);
        $loadingCharges = (float)($data['loading_charges'] ?? 0);
        $unloadingCharges = (float)($data['unloading_charges'] ?? 0);
        $tdsRate = (float)($data['tds_rate'] ?? 0);
        if ($tdsRate > 0) {
            $tds = $this->applyRounding(($branValue * $tdsRate) / 100.0, $roundingMode);
        } else {
            $tds = (float)($data['tds'] ?? 0);
        }
        $advancePaid = (float)($data['advance_paid'] ?? 0);
        $lorryFreight = (float)($data['lorry_freight'] ?? 0);
        $otherCharges = (float)($data['other_charges'] ?? 0);
        $manualAdjustment = (float)($data['manual_adjustment'] ?? 0);

        // 5. Original Invoice vs Settlement (Rebate Calculation)
        $originalInvoiceValue = (float)($data['original_invoice_value'] ?? 0);
        $rawDifference = 0.0;
        $noteType = $data['note_type'] ?? null;
        $noteAmount = 0.0;

        if ($originalInvoiceValue > 0) {
            $rawDifference = ($originalInvoiceValue - $branValue) + $manualAdjustment;
            if (!$noteType) {
                $noteType = ($rawDifference >= 0) ? 'debit' : 'credit';
            }
            $noteAmount = $this->applyRounding(abs($rawDifference), $roundingMode);
        } elseif ($manualAdjustment != 0) {
            if (!$noteType) {
                $noteType = ($manualAdjustment >= 0) ? 'debit' : 'credit';
            }
            $noteAmount = $this->applyRounding(abs($manualAdjustment), $roundingMode);
        } else {
            $noteType = $noteType ?? 'debit';
            $noteAmount = 0.0;
        }

        $rebateAmount = $noteAmount;

        // 6. Taxable Base for GST (Bran Value + Bag Amount)
        $taxableBase = max(0, $branValue + $bagAmount);

        // 7. GST Calculation
        $isInterstate = (bool)($data['is_interstate'] ?? false);
        $cgstRate = (float)($data['cgst_rate'] ?? 2.500);
        $sgstRate = (float)($data['sgst_rate'] ?? 2.500);
        $igstRate = (float)($data['igst_rate'] ?? 5.000);

        $cgstAmount = 0.0;
        $sgstAmount = 0.0;
        $igstAmount = 0.0;

        if ($isInterstate) {
            $cgstRate = 0;
            $sgstRate = 0;
            $rawIgst = ($taxableBase * $igstRate) / 100.0;
            $igstAmount = $this->applyRounding($rawIgst, $roundingMode);
        } else {
            $igstRate = 0;
            $rawCgst = ($taxableBase * $cgstRate) / 100.0;
            $rawSgst = ($taxableBase * $sgstRate) / 100.0;
            $cgstAmount = $this->applyRounding($rawCgst, $roundingMode);
            $sgstAmount = $this->applyRounding($rawSgst, $roundingMode);
        }

        $totalGst = $cgstAmount + $sgstAmount + $igstAmount;

        // 8. Sub Total = Goods Taxable Base + GST Taxes
        $subtotalAmount = $this->applyRounding($taxableBase + $totalGst, $roundingMode);
        $totalAmount = $subtotalAmount;

        // 9. Net Payable = Sub Total + Loading Charges - Unloading Charges - TDS - Advance Paid - Lorry Freight + Other Charges
        $rawNetPayable = $subtotalAmount + $loadingCharges - $unloadingCharges - $tds - $advancePaid - $lorryFreight + $otherCharges;
        $netPayable = $this->applyRounding($rawNetPayable, $roundingMode);

        // 8. Debit / Credit Note Calculation for Rebate / Bonus
        $noteCgstAmount = 0.0;
        $noteSgstAmount = 0.0;
        $noteIgstAmount = 0.0;
        $noteGstTotal = 0.0;
        $noteTotal = 0.0;

        if ($noteAmount > 0) {
            $dnCgstRate = (float)($data['dn_cgst_rate'] ?? ($isInterstate ? 0 : 2.500));
            $dnSgstRate = (float)($data['dn_sgst_rate'] ?? ($isInterstate ? 0 : 2.500));
            $dnIgstRate = (float)($data['dn_igst_rate'] ?? ($isInterstate ? 5.000 : 0));

            if ($isInterstate) {
                $rawDnIgst = ($noteAmount * $dnIgstRate) / 100.0;
                $noteIgstAmount = $this->applyRounding($rawDnIgst, $roundingMode);
            } else {
                $rawDnCgst = ($noteAmount * $dnCgstRate) / 100.0;
                $rawDnSgst = ($noteAmount * $dnSgstRate) / 100.0;
                $noteCgstAmount = $this->applyRounding($rawDnCgst, $roundingMode);
                $noteSgstAmount = $this->applyRounding($rawDnSgst, $roundingMode);
            }

            $noteGstTotal = $noteCgstAmount + $noteSgstAmount + $noteIgstAmount;
            $noteTotal = $this->applyRounding($noteAmount + $noteGstTotal, $roundingMode);
        }

        return [
            'final_weight_mt' => round($finalWeightMt, 3),
            'final_weight_kg' => round($finalWeightKg, 3),
            'oil_quantity_kg' => round($oilQuantityKg, 3),
            'raw_bran_value' => $rawBranValue,
            'bran_value' => $branValue,
            'bag_amount' => $bagAmount,
            'loading_charges' => $loadingCharges,
            'unloading_charges' => $unloadingCharges,
            'tds_rate' => $tdsRate,
            'tds' => $tds,
            'advance_paid' => $advancePaid,
            'lorry_freight' => $lorryFreight,
            'other_charges' => $otherCharges,
            'original_invoice_value' => $originalInvoiceValue,
            'rebate_amount' => $noteAmount,
            'note_type' => $noteType,
            'note_amount' => $noteAmount,
            'manual_adjustment' => $manualAdjustment,
            'subtotal_amount' => $subtotalAmount,
            'is_interstate' => $isInterstate,
            'cgst_rate' => $cgstRate,
            'sgst_rate' => $sgstRate,
            'igst_rate' => $igstRate,
            'cgst_amount' => $cgstAmount,
            'sgst_amount' => $sgstAmount,
            'igst_amount' => $igstAmount,
            'total_gst' => $totalGst,
            'total_amount' => $totalAmount,
            'net_payable' => $netPayable,
            'debit_note' => [
                'transaction_type' => $noteType,
                'taxable_amount' => $noteAmount,
                'cgst_rate' => $isInterstate ? 0 : 2.500,
                'sgst_rate' => $isInterstate ? 0 : 2.500,
                'igst_rate' => $isInterstate ? 5.000 : 0,
                'cgst_amount' => $noteCgstAmount,
                'sgst_amount' => $noteSgstAmount,
                'igst_amount' => $noteIgstAmount,
                'gst_total' => $noteGstTotal,
                'total_amount' => $noteTotal,
            ],
        ];
    }

    /**
     * Apply rounding based on system setting
     */
    public function applyRounding(float $value, string $mode = 'nearest_rupee'): float
    {
        return match ($mode) {
            'nearest_rupee' => (float) round($value, 0),
            'floor' => (float) floor($value),
            'ceil' => (float) ceil($value),
            'two_decimal' => (float) round($value, 2),
            default => (float) round($value, 2),
        };
    }
}
