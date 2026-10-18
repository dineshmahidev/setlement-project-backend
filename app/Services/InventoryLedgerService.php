<?php

namespace App\Services;

use App\Models\Grade;
use App\Models\InwardEntry;
use App\Models\InventoryTransaction;
use App\Models\Lot;
use App\Models\OutwardEntry;
use App\Models\ProductionBatch;
use Illuminate\Support\Facades\DB;

/**
 * Central inventory ledger engine.
 *
 * Source of truth = inventory_transactions (signed_quantity, status=POSTED).
 * Lots carry lot-wise available qty + WAC value. Company is always explicit so a
 * shared physical godown keeps separate ownership per company.
 *
 * Rules enforced here (spec §15):
 *  - only CONFIRMED/POSTED rows affect available stock
 *  - drafts never post; cancels reverse (never erase) and keep audit trail
 *  - stock-changing ops run inside a DB transaction, guarded against oversell
 */
class InventoryLedgerService
{
    /** ---- Numbering ---- */
    public function nextNumber(string $prefix, string $table = 'inventory_transactions', string $col = 'transaction_number'): string
    {
        $year = date('Y');
        $docType = strtolower($prefix);

        return DB::transaction(function () use ($prefix, $table, $col, $year, $docType) {
            $seq = \App\Models\DocumentSequence::where('document_type', $docType)
                ->where('financial_year', $year)
                ->lockForUpdate()
                ->first();

            if (!$seq) {
                // Find existing max number in table
                $like = $prefix.'/'.$year.'/%';
                $last = DB::table($table)->where($col, 'LIKE', $like)->orderByDesc('id')->value($col);
                $initial = $last ? ((int) substr($last, strrpos($last, '/') + 1)) : 0;
                $seq = \App\Models\DocumentSequence::create([
                    'document_type' => $docType,
                    'prefix' => $prefix,
                    'current_number' => $initial,
                    'financial_year' => $year,
                ]);
            }

            $seq->increment('current_number');
            $num = $seq->current_number;
            $formatted = sprintf('%s/%s/%05d', $prefix, $year, $num);

            while (DB::table($table)->where($col, $formatted)->exists()) {
                $seq->increment('current_number');
                $num = $seq->current_number;
                $formatted = sprintf('%s/%s/%05d', $prefix, $year, $num);
            }

            return $formatted;
        });
    }

    /** ---- Grade auto-assign from measured oil % (never hardcoded) ---- */
    public function resolveGrade(?int $companyId, ?float $oilPct, ?string $materialType, ?int $explicitGradeId = null): ?int
    {
        if ($explicitGradeId) return $explicitGradeId;
        if ($oilPct === null) return null;
        $g = Grade::matchForOil($companyId, $oilPct, $materialType);
        return $g?->id;
    }

    /** ---- Available qty for a specific lot ---- */
    public function lotAvailable(int $lotId): float
    {
        return (float) Lot::whereKey($lotId)->value('available_qty');
    }

    /** ---- Company+godown+item available (sum of open lots) ---- */
    public function availableQty(int $companyId, int $branchId, int $itemId): float
    {
        return (float) Lot::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('item_id', $itemId)
            ->where('status', 'OPEN')
            ->sum('available_qty');
    }

    /** ================= LEDGER POST (low-level) ================= */
    protected function postMovement(array $data): InventoryTransaction
    {
        return InventoryTransaction::create($data);
    }

    /** ================= INWARD (multi-item load) ================= */
    /**
     * Confirm an inward entry: create/credit lots + post ledger rows, once.
     * Idempotent via status guard + unique reference.
     */
    public function confirmInward(InwardEntry $entry, int $userId): InwardEntry
    {
        return DB::transaction(function () use ($entry, $userId) {
            $entry = InwardEntry::whereKey($entry->id)->lockForUpdate()->first();
            if ($entry->status === 'CONFIRMED') return $entry;
            if ($entry->status === 'CANCELLED') throw new \RuntimeException('Cancelled inward cannot be confirmed.');

            $companyId = (int) $entry->company_id;
            $branchId = (int) $entry->branch_id;

            foreach ($entry->items as $it) {
                $qty = (float) $it->quantity;
                if ($qty <= 0) throw new \RuntimeException('Inward item quantity must be > 0.');

                $amount = (float) $it->amount;
                $unitCost = $qty > 0 ? round($amount / $qty, 4) : 0;
                $gradeId = $this->resolveGrade($companyId, $it->oil_pct !== null ? (float) $it->oil_pct : null, null, $it->grade_id);
                $lotNo = $it->lot_number ?: sprintf('LOT-%s-%d', $entry->entry_number, $it->id);

                $lot = Lot::where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->where('item_id', $it->item_id)
                    ->where('lot_number', $lotNo)
                    ->lockForUpdate()->first();

                if ($lot) {
                    // Weighted-average cost merge
                    $prevQty = (float) $lot->available_qty;
                    $prevVal = (float) $lot->stock_value;
                    $newQty = $prevQty + $qty;
                    $newVal = $prevVal + $amount;
                    $lot->update([
                        'received_qty' => $lot->received_qty + $qty,
                        'available_qty' => $newQty,
                        'unit_cost' => $newQty > 0 ? round($newVal / $newQty, 4) : 0,
                        'stock_value' => round($newVal, 2),
                        'oil_pct' => $it->oil_pct ?? $lot->oil_pct,
                        'grade_id' => $gradeId ?? $lot->grade_id,
                        'unit_id' => $it->unit_id ?: $lot->unit_id,
                    ]);
                } else {
                    $lot = Lot::create([
                        'company_id' => $companyId,
                        'branch_id' => $branchId,
                        'item_id' => $it->item_id,
                        'grade_id' => $gradeId,
                        'lot_number' => $lotNo,
                        'oil_pct' => $it->oil_pct,
                        'opening_qty' => 0,
                        'received_qty' => $qty,
                        'issued_qty' => 0,
                        'available_qty' => $qty,
                        'unit_id' => $it->unit_id ?: $it->item?->unit_id,
                        'unit_cost' => $unitCost,
                        'stock_value' => round($amount, 2),
                        'source' => 'INWARD',
                        'source_id' => $entry->id,
                        'status' => 'OPEN',
                    ]);
                }

                $this->postMovement([
                    'transaction_number' => $entry->entry_number.'-'.str_pad((string) $it->id, 3, '0', STR_PAD_LEFT),
                    'date' => $entry->date,
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'item_id' => $it->item_id,
                    'type' => 'STOCK_IN',
                    'quantity' => $qty,
                    'signed_quantity' => $qty,
                    'unit_id' => $it->unit_id,
                    'oil_pct' => $it->oil_pct,
                    'grade_id' => $gradeId,
                    'lot_number' => $lotNo,
                    'rate' => $it->rate,
                    'amount' => $amount,
                    'party_id' => $entry->party_id,
                    'party_type' => 'supplier',
                    'vendor_id' => $entry->party_id,
                    'vehicle_id' => null,
                    'driver_name' => $entry->driver_name,
                    'driver_mobile' => $entry->driver_mobile,
                    'reference_type' => 'INWARD',
                    'reference_id' => $entry->id,
                    'source_reference' => $entry->load_reference ?: $entry->entry_number,
                    'status' => 'POSTED',
                    'remarks' => $it->notes ?: 'Inward confirmed: '.$entry->entry_number,
                    'created_by' => $userId,
                ]);
            }

            $entry->update([
                'status' => 'CONFIRMED',
                'confirmed_by' => $userId,
                'confirmed_at' => now(),
            ]);

            return $entry->fresh(['items', 'party', 'branch']);
        });
    }

    public function cancelInward(InwardEntry $entry, int $userId, ?string $reason = null): InwardEntry
    {
        return DB::transaction(function () use ($entry, $userId, $reason) {
            $entry = InwardEntry::whereKey($entry->id)->lockForUpdate()->first();
            if ($entry->status === 'CANCELLED') return $entry;
            if ($entry->status !== 'CONFIRMED') {
                $entry->update(['status' => 'CANCELLED', 'cancelled_by' => $userId, 'cancelled_at' => now()]);
                return $entry;
            }

            // Reverse: create compensating negative movements; do NOT delete history.
            foreach ($entry->items as $it) {
                $qty = (float) $it->quantity;
                $lotNo = $it->lot_number ?: sprintf('LOT-%s-%d', $entry->entry_number, $it->id);
                $lot = Lot::where('company_id', $entry->company_id)
                    ->where('branch_id', $entry->branch_id)
                    ->where('item_id', $it->item_id)
                    ->where('lot_number', $lotNo)
                    ->lockForUpdate()->first();

                if ($lot) {
                    $newAvail = (float) $lot->available_qty - $qty;
                    $newVal = max(0, round((float) $lot->stock_value - (float) $it->amount, 2));
                    $lot->update([
                        'received_qty' => $lot->received_qty - $qty,
                        'available_qty' => $newAvail,
                        'stock_value' => $newVal,
                        'unit_cost' => $newAvail > 0 ? round($newVal / $newAvail, 4) : 0,
                        'status' => $newAvail <= 0 ? 'CLOSED' : 'OPEN',
                    ]);
                }

                $this->postMovement([
                    'transaction_number' => $entry->entry_number.'-R-'.str_pad((string) $it->id, 3, '0', STR_PAD_LEFT),
                    'date' => now()->toDateString(),
                    'company_id' => $entry->company_id,
                    'branch_id' => $entry->branch_id,
                    'item_id' => $it->item_id,
                    'party_id' => $entry->party_id,
                    'type' => 'STOCK_OUT',
                    'quantity' => $qty,
                    'signed_quantity' => -$qty,
                    'unit_id' => $it->unit_id,
                    'oil_pct' => $it->oil_pct,
                    'lot_number' => $lotNo,
                    'amount' => $it->amount,
                    'reference_type' => 'INWARD_CANCEL',
                    'reference_id' => $entry->id,
                    'status' => 'POSTED',
                    'remarks' => 'Reversal of inward '.$entry->entry_number.($reason ? ' — '.$reason : ''),
                    'created_by' => $userId,
                ]);
            }

            $entry->update(['status' => 'CANCELLED', 'cancelled_by' => $userId, 'cancelled_at' => now()]);
            return $entry->fresh(['items', 'party', 'branch']);
        });
    }

    /**
     * Trash inward: reverse lot quantities silently (no ledger transactions posted).
     * Original STOCK_IN transactions stay in the table but are excluded from all
     * reports/dashboards via trashed-entry scopes since the entry is soft-deleted.
     */
    public function trashInward(InwardEntry $entry): InwardEntry
    {
        return DB::transaction(function () use ($entry) {
            $entry = InwardEntry::whereKey($entry->id)->lockForUpdate()->first();
            if ($entry->trashed()) return $entry;
            if ($entry->status !== 'CONFIRMED') {
                $entry->delete();
                return $entry;
            }

            foreach ($entry->items as $it) {
                $qty = (float) $it->quantity;
                $lotNo = $it->lot_number ?: sprintf('LOT-%s-%d', $entry->entry_number, $it->id);
                $lot = Lot::where('company_id', $entry->company_id)
                    ->where('branch_id', $entry->branch_id)
                    ->where('item_id', $it->item_id)
                    ->where('lot_number', $lotNo)
                    ->lockForUpdate()->first();

                if ($lot) {
                    $newAvail = (float) $lot->available_qty - $qty;
                    $newVal = max(0, round((float) $lot->stock_value - (float) $it->amount, 2));
                    $lot->update([
                        'received_qty' => $lot->received_qty - $qty,
                        'available_qty' => $newAvail,
                        'stock_value' => $newVal,
                        'unit_cost' => $newAvail > 0 ? round($newVal / $newAvail, 4) : 0,
                        'status' => $newAvail <= 0 ? 'CLOSED' : 'OPEN',
                    ]);
                }
            }

            $entry->delete();
            return $entry;
        });
    }

    /**
     * Trash outward: restore lot quantities silently (no ledger transactions posted).
     */
    public function trashOutward(OutwardEntry $entry): OutwardEntry
    {
        return DB::transaction(function () use ($entry) {
            $entry = OutwardEntry::whereKey($entry->id)->lockForUpdate()->first();
            if ($entry->trashed()) return $entry;
            if ($entry->status !== 'CONFIRMED') {
                $entry->delete();
                return $entry;
            }

            foreach ($entry->items as $it) {
                $qty = (float) $it->quantity;

                if ($it->lot_id) {
                    $lot = Lot::whereKey($it->lot_id)->lockForUpdate()->first();
                    if ($lot) {
                        $newAvail = (float) $lot->available_qty + $qty;
                        $cost = (float) $lot->unit_cost;
                        $lot->update([
                            'available_qty' => $newAvail,
                            'stock_value' => round($newAvail * $cost, 2),
                            'status' => $newAvail > 0 ? 'OPEN' : $lot->status,
                        ]);
                    }
                } else {
                    // FIFO credit-back for mixed dispatches
                    $remaining = $qty;
                    $lots = Lot::where('company_id', $entry->company_id)
                        ->where('branch_id', $entry->branch_id)
                        ->where('item_id', $it->item_id)
                        ->orderBy('id')->lockForUpdate()->get();
                    foreach ($lots as $lot) {
                        if ($remaining <= 0) break;
                        $newAvail = (float) $lot->available_qty + $remaining;
                        $cost = (float) $lot->unit_cost;
                        $lot->update([
                            'available_qty' => $newAvail,
                            'stock_value' => round($newAvail * $cost, 2),
                            'status' => $newAvail > 0 ? 'OPEN' : $lot->status,
                        ]);
                        $remaining = 0;
                    }
                }
            }

            $entry->delete();
            return $entry;
        });
    }

    /** ================= OUTWARD (direct + mixed) ================= */
    public function confirmOutward(OutwardEntry $entry, int $userId): OutwardEntry
    {
        return DB::transaction(function () use ($entry, $userId) {
            $entry = OutwardEntry::whereKey($entry->id)->lockForUpdate()->first();
            if ($entry->status === 'CONFIRMED') return $entry;
            if ($entry->status === 'CANCELLED') throw new \RuntimeException('Cancelled outward cannot be confirmed.');

            $companyId = (int) $entry->company_id;
            $branchId = (int) $entry->branch_id;
            $allowNegative = (bool) \App\Models\CompanySetting::getValue('allow_negative_stock', false);

            foreach ($entry->items as $it) {
                $qty = (float) $it->quantity;
                if ($qty <= 0) throw new \RuntimeException('Outward item quantity must be > 0.');

                if ($it->lot_id) {
                    // Direct: consume a specific lot
                    $lot = Lot::whereKey($it->lot_id)->lockForUpdate()->first();
                    if (!$lot || (int) $lot->company_id !== $companyId || (int) $lot->branch_id !== $branchId) {
                        throw new \RuntimeException('Lot not available in this company/godown.');
                    }
                    $avail = (float) $lot->available_qty;
                    if (!$allowNegative && $qty > $avail) {
                        throw new \RuntimeException(sprintf('Insufficient stock in lot %s: requested %s, available %s.', $lot->lot_number, $qty, $avail));
                    }
                    $cost = (float) $lot->unit_cost;
                    $lot->update([
                        'issued_qty' => $lot->issued_qty + $qty,
                        'available_qty' => $avail - $qty,
                        'stock_value' => round(max(0, ($avail - $qty) * $cost), 2),
                        'status' => ($avail - $qty) <= 0 ? 'CLOSED' : 'OPEN',
                    ]);
                    $lotNo = $lot->lot_number;
                    $gradeId = $lot->grade_id;
                } else {
                    // Mixed / grade-based: consume FIFO across available lots of this item
                    $gradeId = $this->resolveGrade($companyId, $it->oil_pct !== null ? (float) $it->oil_pct : null, null, $it->grade_id);
                    $lotNo = $it->notes ? null : 'MIXED';
                    $remaining = $qty;
                    $lots = Lot::where('company_id', $companyId)
                        ->where('branch_id', $branchId)
                        ->where('item_id', $it->item_id)
                        ->where('status', 'OPEN')
                        ->where('available_qty', '>', 0)
                        ->orderBy('id')
                        ->lockForUpdate()->get();

                    $totalAvail = (float) $lots->sum('available_qty');
                    if (!$allowNegative && $qty > $totalAvail) {
                        throw new \RuntimeException(sprintf('Insufficient stock: requested %s, available %s.', $qty, $totalAvail));
                    }

                    foreach ($lots as $lot) {
                        if ($remaining <= 0) break;
                        $take = min($remaining, (float) $lot->available_qty);
                        $cost = (float) $lot->unit_cost;
                        $newAvail = (float) $lot->available_qty - $take;
                        $lot->update([
                            'issued_qty' => $lot->issued_qty + $take,
                            'available_qty' => $newAvail,
                            'stock_value' => round($newAvail * $cost, 2),
                            'status' => $newAvail <= 0 ? 'CLOSED' : 'OPEN',
                        ]);
                        $remaining -= $take;
                    }
                    $lotNo = $lotNo ?: 'MIXED/'.$entry->entry_number;
                }

                $amount = (float) $it->amount;
                $this->postMovement([
                    'transaction_number' => $entry->entry_number.'-'.str_pad((string) $it->id, 3, '0', STR_PAD_LEFT),
                    'date' => $entry->date,
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'item_id' => $it->item_id,
                    'type' => 'STOCK_OUT',
                    'quantity' => $qty,
                    'signed_quantity' => -$qty,
                    'unit_id' => $it->unit_id ?: ($it->lot?->unit_id ?: $it->item?->unit_id),
                    'oil_pct' => $it->oil_pct,
                    'grade_id' => $gradeId,
                    'lot_number' => $lotNo,
                    'rate' => $it->rate,
                    'amount' => $amount,
                    'party_id' => $entry->party_id,
                    'party_type' => 'customer',
                    'customer' => $entry->party?->vendor_name ?? $entry->destination,
                    'destination' => $entry->destination,
                    'driver_name' => $entry->driver_name,
                    'driver_mobile' => $entry->driver_mobile,
                    'reference_type' => 'OUTWARD',
                    'reference_id' => $entry->id,
                    'source_reference' => $entry->entry_number,
                    'status' => 'POSTED',
                    'remarks' => $it->notes ?: 'Outward confirmed: '.$entry->entry_number,
                    'created_by' => $userId,
                ]);
            }

            $entry->update(['status' => 'CONFIRMED', 'confirmed_by' => $userId, 'confirmed_at' => now()]);
            return $entry->fresh(['items', 'party', 'branch']);
        });
    }

    public function cancelOutward(OutwardEntry $entry, int $userId, ?string $reason = null): OutwardEntry
    {
        return DB::transaction(function () use ($entry, $userId, $reason) {
            $entry = OutwardEntry::whereKey($entry->id)->lockForUpdate()->first();
            if ($entry->status === 'CANCELLED') return $entry;
            if ($entry->status !== 'CONFIRMED') {
                $entry->update(['status' => 'CANCELLED', 'cancelled_by' => $userId, 'cancelled_at' => now()]);
                return $entry;
            }

            foreach ($entry->items as $it) {
                $qty = (float) $it->quantity;
                // Return to FIFO across lots (credit back)
                $remaining = $qty;
                $lots = Lot::where('company_id', $entry->company_id)
                    ->where('branch_id', $entry->branch_id)
                    ->where('item_id', $it->item_id)
                    ->orderBy('id')->lockForUpdate()->get();
                foreach ($lots as $lot) {
                    if ($remaining <= 0) break;
                    $lot->update([
                        'available_qty' => $lot->available_qty + $remaining,
                        'status' => 'OPEN',
                    ]);
                    $remaining = 0;
                }

                $this->postMovement([
                    'transaction_number' => $entry->entry_number.'-R-'.str_pad((string) $it->id, 3, '0', STR_PAD_LEFT),
                    'date' => now()->toDateString(),
                    'company_id' => $entry->company_id,
                    'branch_id' => $entry->branch_id,
                    'item_id' => $it->item_id,
                    'party_id' => $entry->party_id,
                    'type' => 'STOCK_IN',
                    'quantity' => $qty,
                    'signed_quantity' => $qty,
                    'unit_id' => $it->unit_id,
                    'lot_number' => $it->lot_id ? optional(Lot::find($it->lot_id))->lot_number : 'MIXED',
                    'amount' => $it->amount,
                    'reference_type' => 'OUTWARD_CANCEL',
                    'reference_id' => $entry->id,
                    'status' => 'POSTED',
                    'remarks' => 'Reversal of outward '.$entry->entry_number.($reason ? ' — '.$reason : ''),
                    'created_by' => $userId,
                ]);
            }

            $entry->update(['status' => 'CANCELLED', 'cancelled_by' => $userId, 'cancelled_at' => now()]);
            return $entry->fresh(['items', 'party', 'branch']);
        });
    }

    /** ================= MIXING / PRODUCTION ================= */
    public function confirmProduction(ProductionBatch $batch, int $userId): ProductionBatch
    {
        return DB::transaction(function () use ($batch, $userId) {
            $batch = ProductionBatch::whereKey($batch->id)->lockForUpdate()->first();
            if ($batch->status === 'CONFIRMED') return $batch;
            if ($batch->status === 'CANCELLED') throw new \RuntimeException('Cancelled batch cannot be confirmed.');

            $companyId = (int) $batch->company_id;
            $inputTotal = 0.0;

            // Consume source lots
            foreach ($batch->inputs as $in) {
                $qty = (float) $in->quantity;
                $lot = Lot::whereKey($in->lot_id)->lockForUpdate()->first();
                if (!$lot || (int) $lot->company_id !== $companyId) {
                    throw new \RuntimeException('Input lot not available for this company.');
                }
                $avail = (float) $lot->available_qty;
                $allowNegative = (bool) \App\Models\CompanySetting::getValue('allow_negative_stock', false);
                if (!$allowNegative && $qty > $avail) {
                    throw new \RuntimeException(sprintf('Insufficient lot %s: need %s, have %s.', $lot->lot_number, $qty, $avail));
                }
                $cost = (float) $lot->unit_cost;
                $lot->update([
                    'issued_qty' => $lot->issued_qty + $qty,
                    'available_qty' => $avail - $qty,
                    'stock_value' => round(max(0, ($avail - $qty) * $cost), 2),
                    'status' => ($avail - $qty) <= 0 ? 'CLOSED' : 'OPEN',
                ]);
                $inputTotal += $qty;

                $this->postMovement([
                    'transaction_number' => $batch->batch_number.'-IN-'.str_pad((string) $in->id, 3, '0', STR_PAD_LEFT),
                    'date' => $batch->date,
                    'company_id' => $companyId,
                    'branch_id' => $lot->branch_id,
                    'item_id' => $in->item_id,
                    'type' => 'PRODUCTION_IN',
                    'quantity' => $qty,
                    'signed_quantity' => -$qty,
                    'unit_id' => $lot->unit_id,
                    'oil_pct' => $in->oil_pct,
                    'lot_number' => $lot->lot_number,
                    'reference_type' => 'PRODUCTION',
                    'reference_id' => $batch->id,
                    'status' => 'POSTED',
                    'remarks' => 'Production input (batch '.$batch->batch_number.')',
                    'created_by' => $userId,
                ]);
            }

            // Create output lot
            $outputTotal = 0.0;
            $destBranch = (int) $batch->dest_branch_id;
            foreach ($batch->outputs as $out) {
                $qty = (float) $out->quantity;
                $outputTotal += $qty;
                $lotNo = $batch->output_lot_number ?: sprintf('PRD-%s', $batch->batch_number);
                // value inputs (cost transferred, no new purchase cost)
                $inputCost = (float) $batch->inputs->sum(fn ($i) => (float) Lot::find($i->lot_id)?->stock_value);
                $unitCost = $outputTotal > 0 ? round($inputCost / max($outputTotal, 1), 4) : 0;

                $lot = Lot::create([
                    'company_id' => $companyId,
                    'branch_id' => $destBranch,
                    'item_id' => $out->item_id,
                    'grade_id' => $out->grade_id ?? $batch->output_grade_id,
                    'lot_number' => $lotNo,
                    'oil_pct' => $out->oil_pct ?? $batch->output_oil_pct,
                    'opening_qty' => 0,
                    'received_qty' => $qty,
                    'issued_qty' => 0,
                    'available_qty' => $qty,
                    'unit_id' => null,
                    'unit_cost' => $unitCost,
                    'stock_value' => round($qty * $unitCost, 2),
                    'source' => 'PRODUCTION',
                    'source_id' => $batch->id,
                    'status' => 'OPEN',
                ]);
                $out->update(['lot_id' => $lot->id]);

                $this->postMovement([
                    'transaction_number' => $batch->batch_number.'-OUT-'.str_pad((string) $out->id, 3, '0', STR_PAD_LEFT),
                    'date' => $batch->date,
                    'company_id' => $companyId,
                    'branch_id' => $destBranch,
                    'item_id' => $out->item_id,
                    'type' => 'PRODUCTION_OUT',
                    'quantity' => $qty,
                    'signed_quantity' => $qty,
                    'oil_pct' => $out->oil_pct,
                    'grade_id' => $out->grade_id,
                    'lot_number' => $lotNo,
                    'reference_type' => 'PRODUCTION',
                    'reference_id' => $batch->id,
                    'status' => 'POSTED',
                    'remarks' => 'Production output (batch '.$batch->batch_number.')',
                    'created_by' => $userId,
                ]);
            }

            $batch->update([
                'input_total' => round($inputTotal, 3),
                'output_total' => round($outputTotal, 3),
                'wastage' => round(max(0, $inputTotal - $outputTotal), 3),
                'status' => 'CONFIRMED',
                'confirmed_by' => $userId,
                'confirmed_at' => now(),
            ]);

            return $batch->fresh(['inputs', 'outputs', 'sourceBranch', 'destBranch']);
        });
    }

    /** ================= LOT-AWARE STOCK TRANSFER (godown to godown) =================
     *  Deducts from a specific source lot and creates/credits a lot in the destination
     *  godown, preserving lot identity and posting TRANSFER_OUT / TRANSFER_IN movements. */
    public function transfer(array $payload, int $userId): array
    {
        return DB::transaction(function () use ($payload, $userId) {
            $companyId = (int) $payload['company_id'];
            $sourceBranchId = (int) $payload['source_branch_id'];
            $destBranchId = (int) $payload['dest_branch_id'];
            $lotId = (int) $payload['lot_id'];
            $qty = round((float) $payload['quantity'], 3);

            if ($sourceBranchId === $destBranchId) {
                throw new \Exception('Source and destination godowns must differ.');
            }
            if ($qty <= 0) {
                throw new \Exception('Transfer quantity must be greater than zero.');
            }

            $lot = Lot::where('company_id', $companyId)->where('id', $lotId)->lockForUpdate()->first();
            if (! $lot) {
                throw new \Exception('Lot not found.');
            }
            if ((float) $lot->available_qty < $qty) {
                throw new \Exception("Insufficient stock: requested {$qty}, available {$lot->available_qty}.");
            }

            $now = now();
            // Out of source
            $lot->decrement('available_qty', $qty);
            $lot->increment('issued_qty', $qty);
            if ((float) $lot->available_qty <= 0) {
                $lot->update(['status' => 'CLOSED', 'available_qty' => 0]);
            }
            $unitCost = (float) ($lot->unit_cost ?? ($lot->received_qty > 0 ? $lot->stock_value / $lot->received_qty : 0));
            $value = round($unitCost * $qty, 2);

            $this->postMovement([
                'transaction_number' => sprintf('TRF-OUT-%s-%s', $lot->lot_number, $now->format('YmdHis')),
                'date' => $payload['date'] ?? $now->toDateString(),
                'company_id' => $companyId, 'branch_id' => $sourceBranchId, 'item_id' => $lot->item_id,
                'type' => 'TRANSFER_OUT', 'quantity' => $qty, 'signed_quantity' => -$qty,
                'rate' => $unitCost, 'amount' => $value, 'oil_pct' => $lot->oil_pct, 'grade_id' => $lot->grade_id,
                'lot_number' => $lot->lot_number, 'unit_id' => $lot->unit_id,
                'reference_type' => 'transfer', 'source_reference' => $payload['reference'] ?? null,
                'destination' => 'Godown #'.$destBranchId, 'status' => 'POSTED', 'created_by' => $userId,
            ]);

            // Into destination (same lot number, new branch row)
            $destLot = Lot::where('company_id', $companyId)->where('branch_id', $destBranchId)
                ->where('item_id', $lot->item_id)->where('lot_number', $lot->lot_number)->lockForUpdate()->first();
            if ($destLot && $destLot->status === 'OPEN') {
                $destLot->increment('received_qty', $qty);
                $destLot->increment('available_qty', $qty);
                $destLot->increment('stock_value', $value);
            } else {
                $destLot = Lot::create([
                    'company_id' => $companyId, 'branch_id' => $destBranchId, 'item_id' => $lot->item_id,
                    'grade_id' => $lot->grade_id, 'lot_number' => $lot->lot_number, 'oil_pct' => $lot->oil_pct,
                    'opening_qty' => 0, 'received_qty' => $qty, 'issued_qty' => 0, 'available_qty' => $qty,
                    'reserved_qty' => 0, 'unit_id' => $lot->unit_id, 'unit_cost' => $unitCost, 'stock_value' => $value,
                    'source' => 'transfer', 'status' => 'OPEN',
                ]);
            }

            $this->postMovement([
                'transaction_number' => sprintf('TRF-IN-%s-%s', $lot->lot_number, $now->format('YmdHis')),
                'date' => $payload['date'] ?? $now->toDateString(),
                'company_id' => $companyId, 'branch_id' => $destBranchId, 'item_id' => $lot->item_id,
                'type' => 'TRANSFER_IN', 'quantity' => $qty, 'signed_quantity' => $qty,
                'rate' => $unitCost, 'amount' => $value, 'oil_pct' => $lot->oil_pct, 'grade_id' => $lot->grade_id,
                'lot_number' => $lot->lot_number, 'unit_id' => $lot->unit_id,
                'reference_type' => 'transfer', 'source_reference' => $payload['reference'] ?? null,
                'customer' => 'From Godown #'.$sourceBranchId, 'status' => 'POSTED', 'created_by' => $userId,
            ]);

            return [
                'source_lot' => $lot->fresh(),
                'dest_lot' => $destLot->fresh(),
                'quantity' => $qty,
                'value' => $value,
            ];
        });
    }

    /** ================= STOCK SUMMARY (company / godown / item / oil / grade) ================= */
    public function stockSummary(array $filters = []): array
    {
        $q = Lot::query()
            ->with(['item:id,code,name,material_type,unit_id', 'item.unit:id,code,name', 'grade:id,code,name', 'branch:id,branch_name,branch_code'])
            ->where('status', 'OPEN')
            ->where('available_qty', '>', 0);

        if (!empty($filters['company_id'])) $q->where('company_id', $filters['company_id']);
        if (!empty($filters['branch_id'])) $q->where('branch_id', $filters['branch_id']);
        if (!empty($filters['item_id'])) $q->where('item_id', $filters['item_id']);
        if (!empty($filters['grade_id'])) $q->where('grade_id', $filters['grade_id']);
        if (!empty($filters['material_type'])) $q->whereHas('item', fn ($qq) => $qq->where('material_type', $filters['material_type']));
        if (!empty($filters['oil_min'])) $q->where('oil_pct', '>=', $filters['oil_min']);
        if (!empty($filters['oil_max'])) $q->where('oil_pct', '<=', $filters['oil_max']);

        $inactiveIds = \App\Models\Vendor::whereRaw('LOWER(status) = ?', ['inactive'])->pluck('id')->filter()->all();
        if (!empty($inactiveIds)) {
            $q->whereNot(function ($qq) use ($inactiveIds) {
                $qq->where('source', 'INWARD')
                   ->whereIn('source_id', \App\Models\InwardEntry::whereIn('party_id', $inactiveIds)->select('id'));
            });
        }

        $lots = $q->orderBy('branch_id')->orderBy('item_id')->orderBy('lot_number')->get();

        // Aggregate per item+godown+oil-band
        $byItem = $lots->groupBy(fn ($l) => $l->branch_id.'_'.$l->item_id)->map(function ($g) {
            $first = $g->first();
            $qty = (float) $g->sum('available_qty');
            $val = (float) $g->sum('stock_value');
            return [
                'branch_id' => $first->branch_id,
                'godown' => $first->branch?->branch_name,
                'item_id' => $first->item_id,
                'item_code' => $first->item?->code,
                'item_name' => $first->item?->name,
                'material_type' => $first->item?->material_type,
                'unit' => $first->item?->unit?->code,
                'available_qty' => round($qty, 3),
                'stock_value' => round($val, 2),
                'avg_oil_pct' => $qty > 0 ? round((float) $g->sum(fn ($l) => (float) $l->oil_pct * (float) $l->available_qty) / $qty, 2) : null,
                'lots_count' => $g->count(),
            ];
        })->values();

        return [
            'lots' => $lots->values(),
            'by_item' => $byItem,
            'totals' => [
                'lots' => $lots->count(),
                'total_qty' => round((float) $lots->sum('available_qty'), 3),
                'total_value' => round((float) $lots->sum('stock_value'), 2),
            ],
        ];
    }

    /** Dashboard KPIs (real DB, company-scoped). Trashed inward/outward entries are excluded. */
    public function dashboardKpis(int $companyId, ?int $branchId = null, ?string $from = null, ?string $to = null): array
    {
        $trashedInwardIds = InwardEntry::onlyTrashed()->select('id');
        $trashedOutwardIds = OutwardEntry::onlyTrashed()->select('id');

        $tx = InventoryTransaction::where('status', 'POSTED')
            ->where('company_id', $companyId)
            ->where(fn($q) => $q->whereNull('reference_type')->orWhereNotIn('reference_type', ['SETTLEMENT_BILL', 'Settlement Bill', 'SETTLEMENT']))
            ->whereNot(function ($q) use ($trashedInwardIds) {
                $q->whereIn('reference_type', ['INWARD', 'INWARD_CANCEL'])
                  ->whereIn('reference_id', $trashedInwardIds);
            })
            ->whereNot(function ($q) use ($trashedOutwardIds) {
                $q->whereIn('reference_type', ['OUTWARD', 'OUTWARD_CANCEL'])
                  ->whereIn('reference_id', $trashedOutwardIds);
            });
        if ($branchId) $tx->where('branch_id', $branchId);
        if ($from) $tx->whereDate('date', '>=', $from);
        if ($to) $tx->whereDate('date', '<=', $to);

        $lots = Lot::where('company_id', $companyId)->where('status', 'OPEN');
        if ($branchId) $lots->where('branch_id', $branchId);

        $inactiveIds = \App\Models\Vendor::whereRaw('LOWER(status) = ?', ['inactive'])->pluck('id')->filter()->all();
        if (!empty($inactiveIds)) {
            $tx->where(function ($q) use ($inactiveIds) {
                $q->whereNull('vendor_id')->orWhereNotIn('vendor_id', $inactiveIds);
            })->where(function ($q) use ($inactiveIds) {
                $q->whereNull('party_id')->orWhereNotIn('party_id', $inactiveIds);
            })->whereNot(function ($q) use ($inactiveIds) {
                $q->whereIn('reference_type', ['INWARD', 'INWARD_CANCEL'])
                  ->whereIn('reference_id', \App\Models\InwardEntry::whereIn('party_id', $inactiveIds)->select('id'));
            })->whereNot(function ($q) use ($inactiveIds) {
                $q->whereIn('reference_type', ['OUTWARD', 'OUTWARD_CANCEL'])
                  ->whereIn('reference_id', \App\Models\OutwardEntry::whereIn('party_id', $inactiveIds)->select('id'));
            });

            $lots->whereNot(function ($qq) use ($inactiveIds) {
                $qq->where('source', 'INWARD')
                   ->whereIn('source_id', \App\Models\InwardEntry::whereIn('party_id', $inactiveIds)->select('id'));
            });
        }

        $in = (clone $tx)->whereIn('type', ['STOCK_IN', 'TRANSFER_IN', 'ADJUSTMENT_IN', 'PRODUCTION_OUT'])->sum('quantity');
        $out = (clone $tx)->whereIn('type', ['STOCK_OUT', 'TRANSFER_OUT', 'ADJUSTMENT_OUT', 'PRODUCTION_IN'])->sum('quantity');

        $activeLots = (clone $lots)->where('available_qty', '>', 0)->count();
        $stockQty = (clone $lots)->where('available_qty', '>', 0)->sum('available_qty');
        $stockValue = (clone $lots)->where('available_qty', '>', 0)->sum('stock_value');

        $inVal = (clone $tx)->whereIn('type', ['STOCK_IN'])->sum('amount');
        $outVal = (clone $tx)->whereIn('type', ['STOCK_OUT'])->sum('amount');

        return [
            'total_in_qty' => round((float) $in, 3),
            'total_out_qty' => round((float) $out, 3),
            'current_stock_qty' => round((float) $stockQty, 3),
            'stock_value' => round((float) $stockValue, 2),
            'total_inward_value' => round((float) $inVal, 2),
            'total_outward_value' => round((float) $outVal, 2),
            'active_lots' => $activeLots,
        ];
    }
}
