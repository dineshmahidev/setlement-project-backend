<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\SettlementBill;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;

class SettlementBillService
{
    public function __construct(
        private SettlementCalculationService $calculator,
        private InventoryService $inventory,
        private AuditService $audit,
    ) {}

    public function create(array $data, int $userId): SettlementBill
    {
        return DB::transaction(function () use ($data, $userId) {
            $qc = $data['qc'] ?? [];
            $qcValues = collect($qc)->pluck('value', 'key')->all();

            $calc = $this->calculator->calculate($data, $qcValues);

            $snap = $data['vendor_snapshot'] ?? null;
            if (is_array($snap)) {
                $snap = json_encode($snap);
            }

            $bill = SettlementBill::create(array_merge($data, [
                'bill_number' => $data['bill_number'] ?? $this->nextBillNumber(),
                'status' => 'DRAFT',
                'vendor_snapshot' => $snap,
                'billing_quantity' => $calc['billing_quantity'],
                'final_unit_rate' => $calc['final_unit_rate'],
                'rate_calculated' => $calc['rate_calculated'],
                'rate_rule' => $data['rate_rule'] ?? null,
                'total_value' => $calc['total_value'],
                'additive_charges' => $calc['additive_charges'],
                'deductive_charges' => $calc['deductive_charges'],
                'charges_total' => $calc['charges_total'],
                'taxable_amount' => $calc['taxable_amount'],
                'gst_type' => $calc['gst_type'],
                'gst_rate' => $calc['gst_rate'],
                'cgst_amount' => $calc['cgst_amount'],
                'sgst_amount' => $calc['sgst_amount'],
                'igst_amount' => $calc['igst_amount'],
                'net_payable' => $calc['net_payable'],
                'created_by' => $userId,
                'inventory_posted' => false,
            ]));

            $this->saveChildren($bill, $qc, $calc);

            $this->audit->log('CREATE', 'SettlementBill', $bill->id, null, $bill->only(['bill_number', 'net_payable', 'status']));

            return $bill->load(['qc', 'charges', 'taxes']);
        });
    }

    public function update(SettlementBill $bill, array $data, int $userId): SettlementBill
    {
        return DB::transaction(function () use ($bill, $data, $userId) {
            if ($bill->status === 'APPROVED') {
                throw new \RuntimeException('Approved bills cannot be edited. Cancel first if correction needed.');
            }

            $old = $bill->only(['net_payable', 'status', 'final_unit_rate', 'total_value']);

            $qc = $data['qc'] ?? [];
            $qcValues = collect($qc)->pluck('value', 'key')->all();
            $calc = $this->calculator->calculate($data, $qcValues);

            if (array_key_exists('vendor_snapshot', $data) && is_array($data['vendor_snapshot'])) {
                $data['vendor_snapshot'] = json_encode($data['vendor_snapshot']);
            }

            $bill->update(array_merge($data, [
                'billing_quantity' => $calc['billing_quantity'],
                'final_unit_rate' => $calc['final_unit_rate'],
                'rate_calculated' => $calc['rate_calculated'],
                'total_value' => $calc['total_value'],
                'additive_charges' => $calc['additive_charges'],
                'deductive_charges' => $calc['deductive_charges'],
                'charges_total' => $calc['charges_total'],
                'taxable_amount' => $calc['taxable_amount'],
                'gst_type' => $calc['gst_type'],
                'gst_rate' => $calc['gst_rate'],
                'cgst_amount' => $calc['cgst_amount'],
                'sgst_amount' => $calc['sgst_amount'],
                'igst_amount' => $calc['igst_amount'],
                'net_payable' => $calc['net_payable'],
            ]));

            if (array_key_exists('qc', $data) || array_key_exists('charges', $data)) {
                $this->saveChildren($bill, $qc, $calc);
            }

            $this->audit->log('UPDATE', 'SettlementBill', $bill->id, $old, $bill->only(['net_payable', 'status', 'final_unit_rate', 'total_value']));

            return $bill->fresh(['qc', 'charges', 'taxes']);
        });
    }

    public function approve(SettlementBill $bill, int $userId): SettlementBill
    {
        return DB::transaction(function () use ($bill, $userId) {
            if ($bill->status === 'APPROVED') {
                return $bill; // idempotent
            }
            if (! in_array($bill->status, ['SUBMITTED', 'DRAFT'], true)) {
                throw new \RuntimeException('Only Draft or Submitted bills can be approved. Current: '.$bill->status);
            }

            $bill->update([
                'status' => 'APPROVED',
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            // Spec §18: approval → one stock-in, atomic, no duplicates
            // postSettlementStockIn also creates the linked VehicleMovement when vehicle_id is set
            if ($bill->post_to_inventory && $bill->item_id) {
                $this->inventory->postSettlementStockIn($bill, $userId);
            } elseif ($bill->vehicle_id) {
                \App\Models\VehicleMovement::create([
                    'date' => $bill->bill_date,
                    'vehicle_id' => $bill->vehicle_id,
                    'driver_name' => $bill->vehicle?->driver_name,
                    'driver_mobile' => $bill->vehicle?->driver_mobile,
                    'branch_id' => $bill->branch_id,
                    'transaction_type' => 'SETTLEMENT_BILL',
                    'vendor_id' => $bill->vendor_id,
                    'item_id' => $bill->item_id,
                    'quantity' => $bill->billing_quantity,
                    'lot_number' => $bill->lot_number,
                    'reference_type' => 'SETTLEMENT_BILL',
                    'reference_id' => $bill->id,
                    'reference_number' => $bill->bill_number,
                    'created_by' => $userId,
                ]);
            }

            $this->audit->log('APPROVE', 'SettlementBill', $bill->id, ['status' => 'SUBMITTED'], ['status' => 'APPROVED']);

            return $bill->fresh();
        });
    }

    public function cancel(SettlementBill $bill, int $userId, ?string $reason = null): SettlementBill
    {
        return DB::transaction(function () use ($bill, $userId, $reason) {
            if ($bill->status === 'APPROVED' && $bill->inventory_posted) {
                // Reverse linked stock-in (records remain auditable, not hard-deleted)
                \App\Models\InventoryTransaction::where('reference_type', 'SETTLEMENT_BILL')
                    ->where('reference_id', $bill->id)
                    ->where('status', 'POSTED')
                    ->get()
                    ->each(function ($tx) {
                        $tx->update([
                            'status' => 'CANCELLED',
                            'signed_quantity' => 0,
                            'remarks' => trim(($tx->remarks ?: '').' | CANCELLED with bill'),
                        ]);
                    });
                $bill->update(['inventory_posted' => false, 'inventory_posted_at' => null]);
            }

            $old = ['status' => $bill->status];
            $bill->update(['status' => 'CANCELLED', 'remarks' => trim(($bill->remarks ?: '').' | Cancelled: '.($reason ?: '-'))]);
            $this->audit->log('CANCEL', 'SettlementBill', $bill->id, $old, ['status' => 'CANCELLED', 'reason' => $reason]);

            return $bill->fresh();
        });
    }

    private function saveChildren(SettlementBill $bill, array $qc, array $calc): void
    {
        if ($qc) {
            $bill->qc()->delete();
            foreach (array_values($qc) as $i => $row) {
                $bill->qc()->create([
                    'name' => $row['name'] ?? ($row['key'] ?? ''),
                    'key' => $row['key'] ?? strtolower(str_replace(' ', '_', $row['name'] ?? '')),
                    'value' => $row['value'] ?? null,
                    'unit' => $row['unit'] ?? null,
                    'remarks' => $row['remarks'] ?? null,
                    'active' => (bool) ($row['active'] ?? true),
                    'sort_order' => $row['sort_order'] ?? $i,
                ]);
            }
        }

        if (array_key_exists('charges', $calc)) {
            $bill->charges()->delete();
            foreach ($calc['charges'] as $i => $c) {
                $bill->charges()->create($c + ['settlement_bill_id' => $bill->id, 'sort_order' => $c['sort_order'] ?? $i]);
            }
        }

        $bill->taxes()->delete();
        foreach ($calc['taxes'] as $t) {
            $bill->taxes()->create($t);
        }
    }

    public function nextBillNumber(): string
    {
        $prefix = CompanySetting::getValue('invoice_prefix', 'SB');
        $year = date('y').'-'.(date('m') > '03' ? date('y').'-'.((int) date('y') + 1) : ((int) date('y') - 1).'-'.date('y'));
        // Format: PREFIX/YY-YY/NNNN — simple sequential per year
        $fy = $this->financialYear();
        $like = $prefix.'/'.$fy.'/%';
        $last = SettlementBill::where('bill_number', 'LIKE', $like)->orderByDesc('id')->value('bill_number');
        $seq = $last ? ((int) substr($last, strrpos($last, '/') + 1)) + 1 : 1;
        return sprintf('%s/%s/%04d', $prefix, $fy, $seq);
    }

    private function financialYear(): string
    {
        $y = (int) date('Y');
        return date('m') >= '04' ? $y.'-'.($y + 1) : ($y - 1).'-'.$y;
    }
}
