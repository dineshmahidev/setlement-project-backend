<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\InventoryTransaction;
use App\Models\Item;
use App\Models\SettlementBill;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Models\VehicleMovement;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function nextNumber(string $prefix): string
    {
        $year = date('Y');
        $like = $prefix.'/'.$year.'/%';
        $last = InventoryTransaction::where('transaction_number', 'LIKE', $like)
            ->orderByDesc('id')
            ->value('transaction_number');

        $seq = 1;
        if ($last) {
            $parts = explode('/', $last);
            $seq = ((int) end($parts)) + 1;
        }

        return sprintf('%s/%s/%05d', $prefix, $year, $seq);
    }

    /**
     * Post a stock-in from approved settlement bill with duplicate protection.
     * Spec §18: unique reference (source_type + source_id) at DB level.
     */
    public function postSettlementStockIn(SettlementBill $bill, int $userId): InventoryTransaction
    {
        if ($bill->inventory_posted) {
            return InventoryTransaction::where('reference_type', 'SETTLEMENT_BILL')
                ->where('reference_id', $bill->id)
                ->firstOrFail();
        }

        $exists = InventoryTransaction::where('reference_type', 'SETTLEMENT_BILL')
            ->where('reference_id', $bill->id)
            ->first();
        if ($exists) {
            return $exists;
        }

        $source = CompanySetting::getValue('inventory_quantity_source', 'FINAL_WEIGHT');
        $quantity = match ($source) {
            'NET_WEIGHT' => (float) $bill->net_weight,
            'MANUAL_QUANTITY' => (float) ($bill->manual_quantity ?? $bill->final_weight),
            default => (float) $bill->final_weight,
        };

        if ($quantity <= 0) {
            throw new \RuntimeException('Inventory quantity is zero; cannot post Stock In.');
        }

        $unitId = $bill->item?->unit_id;

        $tx = InventoryTransaction::create([
            'transaction_number' => $this->nextNumber('STKIN'),
            'date' => $bill->bill_date,
            'branch_id' => $bill->branch_id,
            'item_id' => $bill->item_id,
            'type' => 'STOCK_IN',
            'quantity' => $quantity,
            'signed_quantity' => $quantity,
            'unit_id' => $unitId,
            'vehicle_id' => $bill->vehicle_id,
            'driver_name' => $bill->vehicle?->driver_name,
            'driver_mobile' => $bill->vehicle?->driver_mobile,
            'vendor_id' => $bill->vendor_id,
            'lot_number' => $bill->lot_number,
            'reference_type' => 'SETTLEMENT_BILL',
            'reference_id' => $bill->id,
            'source_reference' => $bill->bill_number,
            'status' => 'POSTED',
            'remarks' => 'Auto-posted from Settlement Bill '.$bill->bill_number,
            'created_by' => $userId,
        ]);

        $bill->update([
            'inventory_posted' => true,
            'inventory_posted_at' => now(),
        ]);

        VehicleMovement::create([
            'date' => $bill->bill_date,
            'vehicle_id' => $bill->vehicle_id,
            'driver_name' => $bill->vehicle?->driver_name,
            'driver_mobile' => $bill->vehicle?->driver_mobile,
            'branch_id' => $bill->branch_id,
            'transaction_type' => 'SETTLEMENT_BILL',
            'vendor_id' => $bill->vendor_id,
            'item_id' => $bill->item_id,
            'quantity' => $quantity,
            'lot_number' => $bill->lot_number,
            'reference_type' => 'SETTLEMENT_BILL',
            'reference_id' => $bill->id,
            'reference_number' => $bill->bill_number,
            'created_by' => $userId,
        ]);

        return $tx;
    }

    public function stockIn(array $data, int $userId): InventoryTransaction
    {
        return DB::transaction(function () use ($data, $userId) {
            if (($data['reference_type'] ?? null) === 'SETTLEMENT_BILL' && ! empty($data['reference_id'])) {
                $bill = SettlementBill::findOrFail($data['reference_id']);
                return $this->postSettlementStockIn($bill, $userId);
            }

            $tx = InventoryTransaction::create([
                'transaction_number' => $data['transaction_number'] ?? $this->nextNumber('STKIN'),
                'date' => $data['date'],
                'branch_id' => $data['branch_id'],
                'item_id' => $data['item_id'],
                'type' => 'STOCK_IN',
                'quantity' => $data['quantity'],
                'signed_quantity' => abs((float) $data['quantity']),
                'unit_id' => $data['unit_id'] ?? null,
                'vehicle_id' => $data['vehicle_id'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'driver_mobile' => $data['driver_mobile'] ?? null,
                'vendor_id' => $data['vendor_id'] ?? null,
                'lot_number' => $data['lot_number'] ?? null,
                'reference_type' => $data['reference_type'] ?? 'MANUAL',
                'reference_id' => $data['reference_id'] ?? null,
                'source_reference' => $data['source_reference'] ?? null,
                'status' => 'POSTED',
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $userId,
            ]);

            if (! empty($data['vehicle_id'])) {
                VehicleMovement::create([
                    'date' => $data['date'],
                    'vehicle_id' => $data['vehicle_id'],
                    'driver_name' => $data['driver_name'] ?? null,
                    'driver_mobile' => $data['driver_mobile'] ?? null,
                    'branch_id' => $data['branch_id'],
                    'transaction_type' => 'STOCK_IN',
                    'vendor_id' => $data['vendor_id'] ?? null,
                    'item_id' => $data['item_id'],
                    'quantity' => $data['quantity'],
                    'lot_number' => $data['lot_number'] ?? null,
                    'reference_type' => 'MANUAL_STOCK_IN',
                    'reference_id' => $tx->id,
                    'reference_number' => $tx->transaction_number,
                    'created_by' => $userId,
                ]);
            }

            return $tx;
        });
    }

    public function availableStock(int $branchId, int $itemId): float
    {
        return (float) InventoryTransaction::where('branch_id', $branchId)
            ->where('item_id', $itemId)
            ->where('status', 'POSTED')
            ->sum('signed_quantity');
    }

    public function stockOut(array $data, int $userId): InventoryTransaction
    {
        return DB::transaction(function () use ($data, $userId) {
            $allowNegative = (bool) CompanySetting::getValue('allow_negative_stock', false);
            $available = $this->availableStock((int) $data['branch_id'], (int) $data['item_id']);
            $qty = abs((float) $data['quantity']);

            if (! $allowNegative && $qty > $available) {
                throw new \RuntimeException(sprintf(
                    'Insufficient stock: requested %s, available %s. Enable negative stock in settings to override.',
                    $qty,
                    $available
                ));
            }

            $tx = InventoryTransaction::create([
                'transaction_number' => $data['transaction_number'] ?? $this->nextNumber('STKOUT'),
                'date' => $data['date'],
                'branch_id' => $data['branch_id'],
                'item_id' => $data['item_id'],
                'type' => 'STOCK_OUT',
                'quantity' => $qty,
                'signed_quantity' => -$qty,
                'unit_id' => $data['unit_id'] ?? null,
                'vehicle_id' => $data['vehicle_id'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'driver_mobile' => $data['driver_mobile'] ?? null,
                'customer' => $data['customer'] ?? null,
                'destination' => $data['destination'] ?? null,
                'lot_number' => $data['lot_number'] ?? null,
                'reference_type' => $data['reference_type'] ?? 'MANUAL',
                'status' => 'POSTED',
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $userId,
            ]);

            if (! empty($data['vehicle_id'])) {
                VehicleMovement::create([
                    'date' => $data['date'],
                    'vehicle_id' => $data['vehicle_id'],
                    'driver_name' => $data['driver_name'] ?? null,
                    'driver_mobile' => $data['driver_mobile'] ?? null,
                    'branch_id' => $data['branch_id'],
                    'transaction_type' => 'STOCK_OUT',
                    'customer' => $data['customer'] ?? null,
                    'item_id' => $data['item_id'],
                    'quantity' => $qty,
                    'lot_number' => $data['lot_number'] ?? null,
                    'reference_type' => 'MANUAL_STOCK_OUT',
                    'reference_id' => $tx->id,
                    'reference_number' => $tx->transaction_number,
                    'created_by' => $userId,
                ]);
            }

            return $tx;
        });
    }

    public function transfer(array $data, int $userId): StockTransfer
    {
        return DB::transaction(function () use ($data, $userId) {
            $from = (int) $data['from_branch_id'];
            $to = (int) $data['to_branch_id'];
            if ($from === $to) {
                throw new \RuntimeException('Source and destination branches must differ.');
            }

            $qty = abs((float) $data['quantity']);
            $allowNegative = (bool) CompanySetting::getValue('allow_negative_stock', false);
            $available = $this->availableStock($from, (int) $data['item_id']);
            if (! $allowNegative && $qty > $available) {
                throw new \RuntimeException(sprintf('Insufficient stock at source: available %s.', $available));
            }

            $transfer = StockTransfer::create([
                'transfer_number' => $data['transfer_number'] ?? $this->nextTransferNumber(),
                'date' => $data['date'],
                'from_branch_id' => $from,
                'to_branch_id' => $to,
                'vehicle_id' => $data['vehicle_id'] ?? null,
                'item_id' => $data['item_id'],
                'quantity' => $qty,
                'unit_id' => $data['unit_id'] ?? null,
                'lot_number' => $data['lot_number'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'driver_mobile' => $data['driver_mobile'] ?? null,
                'status' => 'POSTED',
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $userId,
            ]);

            // Transfer Out at source
            InventoryTransaction::create([
                'transaction_number' => $transfer->transfer_number,
                'date' => $transfer->date,
                'branch_id' => $from,
                'item_id' => $transfer->item_id,
                'type' => 'TRANSFER_OUT',
                'quantity' => $qty,
                'signed_quantity' => -$qty,
                'unit_id' => $transfer->unit_id,
                'vehicle_id' => $transfer->vehicle_id,
                'driver_name' => $transfer->driver_name,
                'driver_mobile' => $transfer->driver_mobile,
                'lot_number' => $transfer->lot_number,
                'reference_type' => 'STOCK_TRANSFER',
                'reference_id' => $transfer->id,
                'source_reference' => $transfer->transfer_number,
                'status' => 'POSTED',
                'remarks' => 'Transfer out to branch #'.$to,
                'created_by' => $userId,
            ]);

            // Transfer In at destination — same transfer number.
            // Company-wide stock unchanged (out + in cancel out).
            InventoryTransaction::create([
                'transaction_number' => $transfer->transfer_number,
                'date' => $transfer->date,
                'branch_id' => $to,
                'item_id' => $transfer->item_id,
                'type' => 'TRANSFER_IN',
                'quantity' => $qty,
                'signed_quantity' => $qty,
                'unit_id' => $transfer->unit_id,
                'vehicle_id' => $transfer->vehicle_id,
                'driver_name' => $transfer->driver_name,
                'driver_mobile' => $transfer->driver_mobile,
                'lot_number' => $transfer->lot_number,
                'reference_type' => 'STOCK_TRANSFER',
                'reference_id' => $transfer->id,
                'source_reference' => $transfer->transfer_number,
                'status' => 'POSTED',
                'remarks' => 'Transfer in from branch #'.$from,
                'created_by' => $userId,
            ]);

            if ($transfer->vehicle_id) {
                VehicleMovement::create([
                    'date' => $transfer->date,
                    'vehicle_id' => $transfer->vehicle_id,
                    'driver_name' => $transfer->driver_name,
                    'driver_mobile' => $transfer->driver_mobile,
                    'branch_id' => $from,
                    'transaction_type' => 'STOCK_TRANSFER',
                    'item_id' => $transfer->item_id,
                    'quantity' => $qty,
                    'lot_number' => $transfer->lot_number,
                    'reference_type' => 'STOCK_TRANSFER',
                    'reference_id' => $transfer->id,
                    'reference_number' => $transfer->transfer_number,
                    'remarks' => 'Branch #'.$from.' → #'.$to,
                    'created_by' => $userId,
                ]);
            }

            return $transfer;
        });
    }

    public function nextTransferNumber(): string
    {
        $year = date('Y');
        $last = StockTransfer::where('transfer_number', 'LIKE', 'TRF/'.$year.'/%')
            ->orderByDesc('id')->value('transfer_number');
        $seq = $last ? ((int) substr($last, strrpos($last, '/') + 1)) + 1 : 1;
        return sprintf('TRF/%s/%05d', $year, $seq);
    }

    public function adjust(array $data, int $userId): StockAdjustment
    {
        return DB::transaction(function () use ($data, $userId) {
            $qty = abs((float) $data['quantity']);
            $direction = strtoupper($data['direction']);

            if ($direction === 'OUT') {
                $allowNegative = (bool) CompanySetting::getValue('allow_negative_stock', false);
                $available = $this->availableStock((int) $data['branch_id'], (int) $data['item_id']);
                if (! $allowNegative && $qty > $available) {
                    throw new \RuntimeException('Insufficient stock for adjustment out.');
                }
            }

            $adj = StockAdjustment::create([
                'adjustment_number' => $data['adjustment_number'] ?? $this->nextAdjustmentNumber(),
                'date' => $data['date'],
                'branch_id' => $data['branch_id'],
                'item_id' => $data['item_id'],
                'direction' => $direction,
                'quantity' => $qty,
                'unit_id' => $data['unit_id'] ?? null,
                'reason' => $data['reason'] ?? null,
                'status' => 'POSTED',
                'created_by' => $userId,
            ]);

            $signed = $direction === 'IN' ? $qty : -$qty;

            InventoryTransaction::create([
                'transaction_number' => $adj->adjustment_number,
                'date' => $adj->date,
                'branch_id' => $adj->branch_id,
                'item_id' => $adj->item_id,
                'type' => $direction === 'IN' ? 'ADJUSTMENT_IN' : 'ADJUSTMENT_OUT',
                'quantity' => $qty,
                'signed_quantity' => $signed,
                'unit_id' => $adj->unit_id,
                'reference_type' => 'STOCK_ADJUSTMENT',
                'reference_id' => $adj->id,
                'source_reference' => $adj->adjustment_number,
                'status' => 'POSTED',
                'remarks' => $adj->reason,
                'created_by' => $userId,
            ]);

            return $adj;
        });
    }

    public function nextAdjustmentNumber(): string
    {
        $year = date('Y');
        $last = StockAdjustment::where('adjustment_number', 'LIKE', 'ADJ/'.$year.'/%')
            ->orderByDesc('id')->value('adjustment_number');
        $seq = $last ? ((int) substr($last, strrpos($last, '/') + 1)) + 1 : 1;
        return sprintf('ADJ/%s/%05d', $year, $seq);
    }

    /**
     * Current stock per item/branch derived from transactions (spec §23).
     */
    public function currentStock(?int $branchId = null): array
    {
        $query = Item::with(['unit', 'category'])
            ->where('status', 'ACTIVE')
            ->get();

        return $query->map(function (Item $item) use ($branchId) {
            $tx = InventoryTransaction::where('item_id', $item->id)
                ->where('status', 'POSTED');
            if ($branchId) {
                $tx->where('branch_id', $branchId);
            }

            $in = (clone $tx)->where('type', 'STOCK_IN')->sum('quantity')
                + (clone $tx)->where('type', 'TRANSFER_IN')->sum('quantity')
                + (clone $tx)->where('type', 'ADJUSTMENT_IN')->sum('quantity');

            $out = (clone $tx)->where('type', 'STOCK_OUT')->sum('quantity')
                + (clone $tx)->where('type', 'TRANSFER_OUT')->sum('quantity')
                + (clone $tx)->where('type', 'ADJUSTMENT_OUT')->sum('quantity');

            $available = (float) $in - (float) $out;
            $min = (float) $item->min_stock_level;

            $status = 'Normal';
            if ($available <= 0) {
                $status = 'Out of Stock';
            } elseif ($min > 0 && $available <= $min) {
                $status = 'Low Stock';
            }

            return [
                'item_id' => $item->id,
                'item_code' => $item->code,
                'item_name' => $item->name,
                'unit' => $item->unit?->code,
                'category' => $item->category?->name,
                'opening' => 0,
                'total_in' => round((float) $in, 3),
                'total_out' => round((float) $out, 3),
                'available' => round($available, 3),
                'min_stock_level' => $min,
                'max_stock_level' => (float) $item->max_stock_level,
                'status' => $status,
            ];
        })->values()->all();
    }
}
