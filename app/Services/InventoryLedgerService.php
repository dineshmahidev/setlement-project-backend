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

    /**
     * transaction_number is UNIQUE (incl. soft-deleted rows), so reposted rows
     * (edit/unpost cycles) must not reuse a number that still exists anywhere.
     */
    protected function uniqueTxNumber(string $base): string
    {
        $number = $base;
        $suffix = 2;
        while (InventoryTransaction::withTrashed()->where('transaction_number', $number)->exists()) {
            $number = $base.'-'.$suffix++;
        }
        return $number;
    }

    /**
     * Rename + soft-delete one ledger row. The rename frees the unique
     * transaction number while raw history stays in trash.
     */
    protected function voidRow(InventoryTransaction $row): void
    {
        $suffix = '-VOID-'.$row->id;
        $row->transaction_number = substr($row->transaction_number, 0, 50 - strlen($suffix)).$suffix;
        $row->save();
        $row->delete();
    }

    /**
     * Rename + soft-delete an entry's ledger rows (frees unique numbers).
     */
    protected function voidLedgerRows(string $referenceType, int $referenceId): void
    {
        InventoryTransaction::where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->orderBy('id')
            ->get()
            ->each(fn (InventoryTransaction $row) => $this->voidRow($row));
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
                        'status' => $newQty > 0 ? 'OPEN' : $lot->status,
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

                // Persist the resolved lot number on the item so later edits
                // reuse the same lot instead of spawning a new auto-numbered one.
                if (! $it->lot_number) {
                    $it->update(['lot_number' => $lotNo]);
                }

                $this->postMovement([
                    'transaction_number' => $this->uniqueTxNumber($entry->entry_number.'-'.str_pad((string) $it->id, 3, '0', STR_PAD_LEFT)),
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
                    'transaction_number' => $this->uniqueTxNumber($entry->entry_number.'-R-'.str_pad((string) $it->id, 3, '0', STR_PAD_LEFT)),
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
     * Apply an edited item list to a CONFIRMED inward entry IN PLACE.
     *
     * Per line: reverse the old lot effect, apply the new one, and UPDATE the
     * existing ledger row (id + transaction_number kept → ledger order stays
     * stable, no duplicate/reversal rows). Removed lines are voided, added
     * lines get fresh rows. The entry stays CONFIRMED throughout.
     *
     * $lines: [['item_id', 'lot_number', 'quantity', 'unit_id', 'oil_pct', 'grade_id', 'rate', 'amount', 'notes'], ...]
     */
    public function updatePostedInward(InwardEntry $entry, array $lines, int $userId): InwardEntry
    {
        return DB::transaction(function () use ($entry, $lines, $userId) {
            $entry = InwardEntry::whereKey($entry->id)->lockForUpdate()->first();
            if ($entry->status !== 'CONFIRMED') {
                throw new \RuntimeException('Only confirmed inward entries can be updated in place.');
            }

            $companyId = (int) $entry->company_id;
            $branchId = (int) $entry->branch_id;
            $effectiveLot = fn ($item) => $item->lot_number ?: sprintf('LOT-%s-%d', $entry->entry_number, $item->id);

            // Queue old items + their live ledger rows per product
            $oldByItem = [];
            foreach ($entry->items as $it) $oldByItem[$it->item_id][] = $it;
            $rowsByItem = [];
            foreach (InventoryTransaction::where('reference_type', 'INWARD')->where('reference_id', $entry->id)->orderBy('id')->get() as $r) {
                $rowsByItem[$r->item_id][] = $r;
            }

            foreach ($lines as $nl) {
                $itemId = (int) $nl['item_id'];
                $old = ! empty($oldByItem[$itemId]) ? array_shift($oldByItem[$itemId]) : null;
                $row = ! empty($rowsByItem[$itemId]) ? array_shift($rowsByItem[$itemId]) : null; // shift = row matched, stays in place
                $newQty = (float) $nl['quantity'];
                $newAmount = (float) ($nl['amount'] ?? 0);
                $newLotNo = ($nl['lot_number'] ?? null) ?: ($old ? $effectiveLot($old) : null);
                $gradeId = $this->resolveGrade($companyId, isset($nl['oil_pct']) && $nl['oil_pct'] !== null ? (float) $nl['oil_pct'] : null, null, $nl['grade_id'] ?? null);

                if ($old) {
                    $oldLotNo = $effectiveLot($old);
                    if ($oldLotNo === $newLotNo) {
                        // Same lot: apply only the qty/amount delta. Reversing the full
                        // old qty first would falsely trip the negative-stock guard when
                        // the lot has already been partially consumed by outward entries.
                        $this->applyInwardToLot($entry, $itemId, $newLotNo, $newQty - (float) $old->quantity, $newAmount - (float) $old->amount);
                    } else {
                        $this->applyInwardToLot($entry, $itemId, $oldLotNo, -(float) $old->quantity, -(float) $old->amount);
                        $this->applyInwardToLot($entry, $itemId, $newLotNo, $newQty, $newAmount);
                    }
                    $old->update([
                        'quantity' => $newQty,
                        'unit_id' => $nl['unit_id'] ?? $old->unit_id,
                        'oil_pct' => $nl['oil_pct'] ?? null,
                        'grade_id' => $gradeId,
                        'lot_number' => $newLotNo,
                        'rate' => $nl['rate'] ?? null,
                        'amount' => $newAmount,
                        'notes' => $nl['notes'] ?? null,
                    ]);
                }
                if ($row) {
                    // Update the EXISTING ledger row in place (id + number preserved → order stable)
                    $row->update([
                        'quantity' => $newQty,
                        'signed_quantity' => $newQty,
                        'rate' => $nl['rate'] ?? null,
                        'amount' => $newAmount,
                        'unit_id' => $nl['unit_id'] ?? $row->unit_id,
                        'oil_pct' => $nl['oil_pct'] ?? null,
                        'grade_id' => $gradeId,
                        'lot_number' => $newLotNo,
                        'date' => $entry->date,
                        'remarks' => ($nl['notes'] ?? null) ?: 'Inward confirmed: '.$entry->entry_number,
                    ]);
                } else {
                    $newItem = $old ?: $entry->items()->create([
                        'item_id' => $itemId,
                        'quantity' => $newQty,
                        'unit_id' => $nl['unit_id'] ?? null,
                        'rate' => $nl['rate'] ?? null,
                        'amount' => $newAmount,
                        'oil_pct' => $nl['oil_pct'] ?? null,
                        'grade_id' => $gradeId,
                        'lot_number' => $newLotNo,
                        'notes' => $nl['notes'] ?? null,
                    ]);
                    $newLotNo = $newLotNo ?: sprintf('LOT-%s-%d', $entry->entry_number, $newItem->id);
                    if ($newItem->lot_number !== $newLotNo) $newItem->update(['lot_number' => $newLotNo]);
                    $this->applyInwardToLot($entry, $itemId, $newLotNo, $newQty, $newAmount);
                    $this->postMovement([
                        'transaction_number' => $this->uniqueTxNumber($entry->entry_number.'-'.str_pad((string) $newItem->id, 3, '0', STR_PAD_LEFT)),
                        'date' => $entry->date,
                        'company_id' => $companyId,
                        'branch_id' => $branchId,
                        'item_id' => $itemId,
                        'type' => 'STOCK_IN',
                        'quantity' => $newQty,
                        'signed_quantity' => $newQty,
                        'unit_id' => $nl['unit_id'] ?? null,
                        'oil_pct' => $nl['oil_pct'] ?? null,
                        'grade_id' => $gradeId,
                        'lot_number' => $newLotNo,
                        'rate' => $nl['rate'] ?? null,
                        'amount' => $newAmount,
                        'party_id' => $entry->party_id,
                        'party_type' => 'supplier',
                        'vendor_id' => $entry->party_id,
                        'driver_name' => $entry->driver_name,
                        'driver_mobile' => $entry->driver_mobile,
                        'reference_type' => 'INWARD',
                        'reference_id' => $entry->id,
                        'source_reference' => $entry->load_reference ?: $entry->entry_number,
                        'status' => 'POSTED',
                        'remarks' => ($nl['notes'] ?? null) ?: 'Inward confirmed: '.$entry->entry_number,
                        'created_by' => $userId,
                    ]);
                }
            }

            // Removed lines: reverse lot effect, void row, delete item
            foreach ($oldByItem as $queue) {
                foreach ($queue as $old) {
                    $this->applyInwardToLot($entry, $old->item_id, $effectiveLot($old), -(float) $old->quantity, -(float) $old->amount);
                    $orphan = ! empty($rowsByItem[$old->item_id]) ? array_shift($rowsByItem[$old->item_id]) : null;
                    if ($orphan) $this->voidRow($orphan);
                    $old->delete();
                }
            }
            // Any leftover rows (orphans / legacy cancel rows) go to trash
            foreach ($rowsByItem as $queue) {
                foreach ($queue as $r) $this->voidRow($r);
            }
            $this->voidLedgerRows('INWARD_CANCEL', $entry->id);

            return $entry->fresh(['items', 'party', 'branch']);
        });
    }

    /**
     * Credit/debit an inward lot by qty + amount (negative = reverse).
     * Guards against stock going negative when shrinking an entry.
     */
    protected function applyInwardToLot(InwardEntry $entry, int $itemId, string $lotNo, float $qty, float $amount): void
    {
        if ($qty == 0.0 && $amount == 0.0) return;

        $lot = Lot::where('company_id', $entry->company_id)
            ->where('branch_id', $entry->branch_id)
            ->where('item_id', $itemId)
            ->where('lot_number', $lotNo)
            ->lockForUpdate()->first();

        if (! $lot) {
            if ($qty <= 0) return; // reversing a lot that never existed: nothing to do
            Lot::create([
                'company_id' => $entry->company_id,
                'branch_id' => $entry->branch_id,
                'item_id' => $itemId,
                'lot_number' => $lotNo,
                'opening_qty' => 0,
                'received_qty' => $qty,
                'issued_qty' => 0,
                'available_qty' => $qty,
                'unit_cost' => $qty > 0 ? round($amount / $qty, 4) : 0,
                'stock_value' => round($amount, 2),
                'source' => 'INWARD',
                'source_id' => $entry->id,
                'status' => 'OPEN',
            ]);
            return;
        }

        $newAvail = (float) $lot->available_qty + $qty;
        $allowNegative = (bool) \App\Models\CompanySetting::getValue('allow_negative_stock', false);
        if ($newAvail < 0 && ! $allowNegative) {
            throw new \RuntimeException(sprintf(
                'Cannot reduce inward: lot %s would go negative (available %s, change %s).',
                $lot->lot_number, $lot->available_qty, $qty
            ));
        }
        $newVal = max(0, round((float) $lot->stock_value + $amount, 2));
        $lot->update([
            'received_qty' => $lot->received_qty + $qty,
            'available_qty' => $newAvail,
            'stock_value' => $newVal,
            'unit_cost' => $newAvail > 0 ? round($newVal / $newAvail, 4) : 0,
            'status' => $newAvail <= 0 ? 'CLOSED' : 'OPEN',
        ]);
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
                    'transaction_number' => $this->uniqueTxNumber($entry->entry_number.'-'.str_pad((string) $it->id, 3, '0', STR_PAD_LEFT)),
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
                    'transaction_number' => $this->uniqueTxNumber($entry->entry_number.'-R-'.str_pad((string) $it->id, 3, '0', STR_PAD_LEFT)),
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

    /**
     * Apply an edited item list to a CONFIRMED outward entry IN PLACE.
     *
     * Per line: credit back the old consumption, consume the new one, and UPDATE
     * the existing ledger row (id + transaction_number kept → ledger order stays
     * stable, no duplicate/reversal rows). Removed lines are voided, added lines
     * get fresh rows. The entry stays CONFIRMED throughout.
     *
     * $lines: [['item_id', 'lot_id', 'quantity', 'unit_id', 'oil_pct', 'grade_id', 'rate', 'amount'], ...]
     */
    public function updatePostedOutward(OutwardEntry $entry, array $lines, int $userId): OutwardEntry
    {
        return DB::transaction(function () use ($entry, $lines, $userId) {
            $entry = OutwardEntry::whereKey($entry->id)->lockForUpdate()->first();
            if ($entry->status !== 'CONFIRMED') {
                throw new \RuntimeException('Only confirmed dispatch entries can be updated in place.');
            }

            $oldByItem = [];
            foreach ($entry->items as $it) $oldByItem[$it->item_id][] = $it;
            $rowsByItem = [];
            foreach (InventoryTransaction::where('reference_type', 'OUTWARD')->where('reference_id', $entry->id)->orderBy('id')->get() as $r) {
                $rowsByItem[$r->item_id][] = $r;
            }

            foreach ($lines as $nl) {
                $itemId = (int) $nl['item_id'];
                $old = ! empty($oldByItem[$itemId]) ? array_shift($oldByItem[$itemId]) : null;
                $row = ! empty($rowsByItem[$itemId]) ? array_shift($rowsByItem[$itemId]) : null; // shift = row matched, stays in place
                $newQty = (float) $nl['quantity'];
                $newAmount = (float) ($nl['amount'] ?? 0);
                $newLotId = ! empty($nl['lot_id']) ? (int) $nl['lot_id'] : null;
                $oilPct = isset($nl['oil_pct']) && $nl['oil_pct'] !== null ? (float) $nl['oil_pct'] : null;
                $explicitGrade = isset($nl['grade_id']) && $nl['grade_id'] !== null ? (int) $nl['grade_id'] : null;

                if ($old) {
                    $this->creditOutwardConsumption($entry, $itemId, $old->lot_id ? (int) $old->lot_id : null, (float) $old->quantity);
                }
                $res = $this->consumeOutwardStock($entry, $itemId, $newLotId, $newQty, $oilPct, $explicitGrade);

                if ($old) {
                    $old->update([
                        'quantity' => $newQty,
                        'lot_id' => $newLotId,
                        'rate' => $nl['rate'] ?? null,
                        'amount' => $newAmount,
                        'unit_id' => $nl['unit_id'] ?? $old->unit_id,
                        'oil_pct' => $oilPct,
                        'grade_id' => $res['grade_id'],
                    ]);
                }
                if ($row) {
                    $row->update([
                        'quantity' => $newQty,
                        'signed_quantity' => -$newQty,
                        'rate' => $nl['rate'] ?? null,
                        'amount' => $newAmount,
                        'unit_id' => $nl['unit_id'] ?? $row->unit_id,
                        'oil_pct' => $oilPct,
                        'grade_id' => $res['grade_id'],
                        'lot_number' => $res['lot_number'],
                        'date' => $entry->date,
                        'destination' => $entry->destination,
                        'remarks' => 'Outward confirmed: '.$entry->entry_number,
                    ]);
                } else {
                    $newItem = $old ?: $entry->items()->create([
                        'item_id' => $itemId,
                        'lot_id' => $newLotId,
                        'quantity' => $newQty,
                        'unit_id' => $nl['unit_id'] ?? null,
                        'oil_pct' => $oilPct,
                        'grade_id' => $res['grade_id'],
                        'rate' => $nl['rate'] ?? null,
                        'amount' => $newAmount,
                    ]);
                    $this->postMovement([
                        'transaction_number' => $this->uniqueTxNumber($entry->entry_number.'-'.str_pad((string) $newItem->id, 3, '0', STR_PAD_LEFT)),
                        'date' => $entry->date,
                        'company_id' => $entry->company_id,
                        'branch_id' => $entry->branch_id,
                        'item_id' => $itemId,
                        'type' => 'STOCK_OUT',
                        'quantity' => $newQty,
                        'signed_quantity' => -$newQty,
                        'unit_id' => $nl['unit_id'] ?? null,
                        'oil_pct' => $oilPct,
                        'grade_id' => $res['grade_id'],
                        'lot_number' => $res['lot_number'],
                        'rate' => $nl['rate'] ?? null,
                        'amount' => $newAmount,
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
                        'remarks' => 'Outward confirmed: '.$entry->entry_number,
                        'created_by' => $userId,
                    ]);
                }
            }

            // Removed lines: credit back, void row, delete item
            foreach ($oldByItem as $queue) {
                foreach ($queue as $old) {
                    $this->creditOutwardConsumption($entry, $old->item_id, $old->lot_id ? (int) $old->lot_id : null, (float) $old->quantity);
                    $orphan = ! empty($rowsByItem[$old->item_id]) ? array_shift($rowsByItem[$old->item_id]) : null;
                    if ($orphan) $this->voidRow($orphan);
                    $old->delete();
                }
            }
            // Any leftover rows (orphans / legacy cancel rows) go to trash
            foreach ($rowsByItem as $queue) {
                foreach ($queue as $r) $this->voidRow($r);
            }
            $this->voidLedgerRows('OUTWARD_CANCEL', $entry->id);

            return $entry->fresh(['items', 'party', 'branch']);
        });
    }

    /** Credit stock back for one outward line (direct lot or FIFO for mixed). */
    protected function creditOutwardConsumption(OutwardEntry $entry, int $itemId, ?int $lotId, float $qty): void
    {
        if ($qty <= 0) return;

        if ($lotId) {
            $lot = Lot::whereKey($lotId)->lockForUpdate()->first();
            if ($lot) {
                $newAvail = (float) $lot->available_qty + $qty;
                $lot->update([
                    'issued_qty' => max(0, (float) $lot->issued_qty - $qty),
                    'available_qty' => $newAvail,
                    'stock_value' => round($newAvail * (float) $lot->unit_cost, 2),
                    'status' => $newAvail > 0 ? 'OPEN' : $lot->status,
                ]);
            }
            return;
        }

        $remaining = $qty;
        $lots = Lot::where('company_id', $entry->company_id)
            ->where('branch_id', $entry->branch_id)
            ->where('item_id', $itemId)
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($lots as $lot) {
            if ($remaining <= 0) break;
            $newAvail = (float) $lot->available_qty + $remaining;
            $lot->update([
                'issued_qty' => max(0, (float) $lot->issued_qty - $remaining),
                'available_qty' => $newAvail,
                'stock_value' => round($newAvail * (float) $lot->unit_cost, 2),
                'status' => $newAvail > 0 ? 'OPEN' : $lot->status,
            ]);
            $remaining = 0;
        }
    }

    /** Consume stock for one outward line; returns lot_number + grade_id for the ledger row. */
    protected function consumeOutwardStock(OutwardEntry $entry, int $itemId, ?int $lotId, float $qty, ?float $oilPct, ?int $gradeId): array
    {
        $companyId = (int) $entry->company_id;
        $branchId = (int) $entry->branch_id;
        $allowNegative = (bool) \App\Models\CompanySetting::getValue('allow_negative_stock', false);

        if ($qty <= 0) {
            return ['lot_number' => $lotId ? (string) Lot::whereKey($lotId)->value('lot_number') : 'MIXED', 'grade_id' => $gradeId];
        }

        if ($lotId) {
            $lot = Lot::whereKey($lotId)->lockForUpdate()->first();
            if (! $lot || (int) $lot->company_id !== $companyId || (int) $lot->branch_id !== $branchId) {
                throw new \RuntimeException('Lot not available in this company/godown.');
            }
            $avail = (float) $lot->available_qty;
            if (! $allowNegative && $qty > $avail) {
                throw new \RuntimeException(sprintf('Insufficient stock in lot %s: requested %s, available %s.', $lot->lot_number, $qty, $avail));
            }
            $cost = (float) $lot->unit_cost;
            $lot->update([
                'issued_qty' => $lot->issued_qty + $qty,
                'available_qty' => $avail - $qty,
                'stock_value' => round(max(0, ($avail - $qty) * $cost), 2),
                'status' => ($avail - $qty) <= 0 ? 'CLOSED' : 'OPEN',
            ]);
            return ['lot_number' => $lot->lot_number, 'grade_id' => $gradeId ?? $lot->grade_id];
        }

        // Mixed / grade-based: consume FIFO across available lots
        $resolvedGrade = $this->resolveGrade($companyId, $oilPct, null, $gradeId);
        $remaining = $qty;
        $lots = Lot::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('item_id', $itemId)
            ->where('status', 'OPEN')
            ->where('available_qty', '>', 0)
            ->orderBy('id')->lockForUpdate()->get();
        $totalAvail = (float) $lots->sum('available_qty');
        if (! $allowNegative && $qty > $totalAvail) {
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

        return ['lot_number' => 'MIXED', 'grade_id' => $resolvedGrade];
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

            $outTxNumber = sprintf('TRF-OUT-%s-%s', $lot->lot_number, $now->format('YmdHis'));
            $this->postMovement([
                'transaction_number' => $outTxNumber,
                'date' => $payload['date'] ?? $now->toDateString(),
                'company_id' => $companyId, 'branch_id' => $sourceBranchId, 'item_id' => $lot->item_id,
                'type' => 'TRANSFER_OUT', 'quantity' => $qty, 'signed_quantity' => -$qty,
                'rate' => $unitCost, 'amount' => $value, 'oil_pct' => $lot->oil_pct, 'grade_id' => $lot->grade_id,
                'lot_number' => $lot->lot_number, 'unit_id' => $lot->unit_id,
                'reference_type' => 'TRANSFER', 'source_reference' => $payload['reference'] ?? null,
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

            $inTxNumber = sprintf('TRF-IN-%s-%s', $lot->lot_number, $now->format('YmdHis'));
            $this->postMovement([
                'transaction_number' => $inTxNumber,
                'date' => $payload['date'] ?? $now->toDateString(),
                'company_id' => $companyId, 'branch_id' => $destBranchId, 'item_id' => $lot->item_id,
                'type' => 'TRANSFER_IN', 'quantity' => $qty, 'signed_quantity' => $qty,
                'rate' => $unitCost, 'amount' => $value, 'oil_pct' => $lot->oil_pct, 'grade_id' => $lot->grade_id,
                'lot_number' => $lot->lot_number, 'unit_id' => $lot->unit_id,
                'reference_type' => 'TRANSFER', 'source_reference' => $payload['reference'] ?? null,
                'customer' => 'From Godown #'.$sourceBranchId, 'status' => 'POSTED', 'created_by' => $userId,
            ]);

            // History record so lot-based transfers show in the Stock Transfers table + PDF
            $vehicleId = null;
            $vNum = trim($payload['vehicle_number'] ?? $payload['vehicle'] ?? '');
            if ($vNum !== '') {
                $veh = \App\Models\Vehicle::where('vehicle_number', $vNum)->first();
                if (! $veh) {
                    $veh = \App\Models\Vehicle::create([
                        'vehicle_number' => $vNum,
                        'status' => 'ACTIVE',
                        'company_id' => $companyId,
                    ]);
                }
                $vehicleId = $veh->id;
            }
            $transferNumber = $this->nextNumber('TRF', 'stock_transfers', 'transfer_number');
            $transfer = \App\Models\StockTransfer::create([
                'transfer_number' => $transferNumber,
                'date' => $payload['date'] ?? $now->toDateString(),
                'from_branch_id' => $sourceBranchId,
                'to_branch_id' => $destBranchId,
                'item_id' => $lot->item_id,
                'quantity' => $qty,
                'unit_id' => $lot->unit_id,
                'lot_number' => $lot->lot_number,
                'vehicle_id' => $vehicleId,
                'status' => 'POSTED',
                'remarks' => $payload['reference'] ?? null,
                'created_by' => $userId,
            ]);

            InventoryTransaction::whereIn('transaction_number', [$outTxNumber, $inTxNumber])
                ->update(['reference_type' => 'TRANSFER', 'reference_id' => $transfer->id, 'source_reference' => $transfer->transfer_number]);

            return [
                'transfer' => $transfer,
                'source_lot' => $lot->fresh(),
                'dest_lot' => $destLot->fresh(),
                'quantity' => $qty,
                'value' => $value,
            ];
        });
    }

    public function updateTransfer(\App\Models\StockTransfer $transfer, array $payload, int $userId): \App\Models\StockTransfer
    {
        return DB::transaction(function () use ($transfer, $payload, $userId) {
            $transfer = \App\Models\StockTransfer::whereKey($transfer->id)->lockForUpdate()->first();
            if ($transfer->trashed()) {
                throw new \RuntimeException('Cannot edit a deleted stock transfer.');
            }

            $oldFromBranch = (int) $transfer->from_branch_id;
            $oldToBranch = (int) $transfer->to_branch_id;
            $oldLotNumber = $transfer->lot_number;
            $oldItemId = (int) $transfer->item_id;
            $oldQty = (float) $transfer->quantity;

            $newFromBranch = (int) ($payload['source_branch_id'] ?? $payload['from_branch_id'] ?? $oldFromBranch);
            $newToBranch = (int) ($payload['dest_branch_id'] ?? $payload['to_branch_id'] ?? $oldToBranch);
            $newQty = round((float) $payload['quantity'], 3);
            $newLotId = isset($payload['lot_id']) ? (int) $payload['lot_id'] : null;

            if ($newFromBranch === $newToBranch) {
                throw new \RuntimeException('Source and destination godowns must differ.');
            }
            if ($newQty <= 0) {
                throw new \RuntimeException('Transfer quantity must be greater than zero.');
            }

            // 1. Check destination lot from previous transfer
            $destLot = Lot::where('branch_id', $oldToBranch)
                ->where('item_id', $oldItemId)
                ->where('lot_number', $oldLotNumber)
                ->lockForUpdate()
                ->first();

            if ($destLot) {
                $diff = $oldQty - $newQty;
                if ($newFromBranch !== $oldFromBranch || $newToBranch !== $oldToBranch || ($newLotId && !empty($payload['lot_number']) && $payload['lot_number'] !== $oldLotNumber)) {
                    if ((float) $destLot->available_qty < $oldQty) {
                        throw new \RuntimeException(sprintf(
                            'Stock in destination lot %s has already been partially used/dispatched (available: %s, transfer: %s). Cannot change transfer godown or lot.',
                            $destLot->lot_number, $destLot->available_qty, $oldQty
                        ));
                    }
                } elseif ($diff > 0 && (float) $destLot->available_qty < $diff) {
                    throw new \RuntimeException(sprintf(
                        'Cannot reduce transfer by %s: destination godown only has %s available in lot %s.',
                        $diff, $destLot->available_qty, $destLot->lot_number
                    ));
                }
            }

            // 2. Revert previous transfer on destination lot
            if ($destLot) {
                $destLotAvail = max(0, (float) $destLot->available_qty - $oldQty);
                $destLotRec = max(0, (float) $destLot->received_qty - $oldQty);
                $unitCost = (float) $destLot->unit_cost;
                $destLot->update([
                    'available_qty' => $destLotAvail,
                    'received_qty' => $destLotRec,
                    'stock_value' => round(max(0, $destLotAvail * $unitCost), 2),
                    'status' => ($destLotAvail <= 0 && (float) $destLot->issued_qty <= 0) ? 'CLOSED' : $destLot->status,
                ]);
            }

            // 3. Revert previous transfer on source lot (restore stock)
            $oldSrcLot = Lot::where('branch_id', $oldFromBranch)
                ->where('item_id', $oldItemId)
                ->where('lot_number', $oldLotNumber)
                ->lockForUpdate()
                ->first();

            if ($oldSrcLot) {
                $oldSrcAvail = (float) $oldSrcLot->available_qty + $oldQty;
                $oldSrcIssued = max(0, (float) $oldSrcLot->issued_qty - $oldQty);
                $oldSrcCost = (float) $oldSrcLot->unit_cost;
                $oldSrcLot->update([
                    'available_qty' => $oldSrcAvail,
                    'issued_qty' => $oldSrcIssued,
                    'stock_value' => round(max(0, $oldSrcAvail * $oldSrcCost), 2),
                    'status' => 'OPEN',
                ]);
            }

            // 4. Delete old ledger transactions
            InventoryTransaction::where(function ($q) use ($transfer) {
                $q->where(function ($qq) use ($transfer) {
                    $qq->where('reference_type', 'TRANSFER')
                       ->where('reference_id', $transfer->id);
                })->orWhere(function ($qq) use ($transfer) {
                    $qq->where('source_reference', $transfer->transfer_number);
                });
            })->delete();

            // 5. Resolve new source lot and apply new transfer
            $targetSrcLot = null;
            if ($newLotId) {
                $targetSrcLot = Lot::where('id', $newLotId)->lockForUpdate()->first();
            } else {
                $targetLotNo = $payload['lot_number'] ?? $oldLotNumber;
                $targetItemId = !empty($payload['item_id']) ? (int) $payload['item_id'] : $oldItemId;
                $targetSrcLot = Lot::where('branch_id', $newFromBranch)
                    ->where('item_id', $targetItemId)
                    ->where('lot_number', $targetLotNo)
                    ->lockForUpdate()
                    ->first();
            }

            if (! $targetSrcLot) {
                throw new \RuntimeException('Selected source lot not found.');
            }
            if ((float) $targetSrcLot->available_qty < $newQty) {
                throw new \RuntimeException(sprintf(
                    'Insufficient stock in source lot %s: requested %s, available %s.',
                    $targetSrcLot->lot_number, $newQty, $targetSrcLot->available_qty
                ));
            }

            $now = now();
            $companyId = (int) $targetSrcLot->company_id;
            $unitCost = (float) ($targetSrcLot->unit_cost ?? ($targetSrcLot->received_qty > 0 ? $targetSrcLot->stock_value / $targetSrcLot->received_qty : 0));
            $value = round($unitCost * $newQty, 2);

            // Deduct from target source lot
            $targetSrcLot->decrement('available_qty', $newQty);
            $targetSrcLot->increment('issued_qty', $newQty);
            if ((float) $targetSrcLot->available_qty <= 0) {
                $targetSrcLot->update(['status' => 'CLOSED', 'available_qty' => 0]);
            }

            // Post TRANSFER_OUT
            $this->postMovement([
                'transaction_number' => sprintf('TRF-OUT-%s-%s', $targetSrcLot->lot_number, $now->format('YmdHis')),
                'date' => $payload['date'] ?? $now->toDateString(),
                'company_id' => $companyId,
                'branch_id' => $newFromBranch,
                'item_id' => $targetSrcLot->item_id,
                'type' => 'TRANSFER_OUT',
                'quantity' => $newQty,
                'signed_quantity' => -$newQty,
                'rate' => $unitCost,
                'amount' => $value,
                'oil_pct' => $targetSrcLot->oil_pct,
                'grade_id' => $targetSrcLot->grade_id,
                'lot_number' => $targetSrcLot->lot_number,
                'unit_id' => $targetSrcLot->unit_id,
                'reference_type' => 'TRANSFER',
                'reference_id' => $transfer->id,
                'source_reference' => $transfer->transfer_number,
                'destination' => 'Godown #'.$newToBranch,
                'remarks' => $payload['reference'] ?? $payload['remarks'] ?? null,
                'status' => 'POSTED',
                'created_by' => $userId,
            ]);

            // Add into destination lot
            $newDestLot = Lot::where('company_id', $companyId)
                ->where('branch_id', $newToBranch)
                ->where('item_id', $targetSrcLot->item_id)
                ->where('lot_number', $targetSrcLot->lot_number)
                ->lockForUpdate()
                ->first();

            if ($newDestLot && $newDestLot->status === 'OPEN') {
                $newDestLot->increment('received_qty', $newQty);
                $newDestLot->increment('available_qty', $newQty);
                $newDestLot->increment('stock_value', $value);
            } else {
                $newDestLot = Lot::create([
                    'company_id' => $companyId,
                    'branch_id' => $newToBranch,
                    'item_id' => $targetSrcLot->item_id,
                    'grade_id' => $targetSrcLot->grade_id,
                    'lot_number' => $targetSrcLot->lot_number,
                    'oil_pct' => $targetSrcLot->oil_pct,
                    'opening_qty' => 0,
                    'received_qty' => $newQty,
                    'issued_qty' => 0,
                    'available_qty' => $newQty,
                    'reserved_qty' => 0,
                    'unit_id' => $targetSrcLot->unit_id,
                    'unit_cost' => $unitCost,
                    'stock_value' => $value,
                    'source' => 'transfer',
                    'status' => 'OPEN',
                ]);
            }

            // Post TRANSFER_IN
            $this->postMovement([
                'transaction_number' => sprintf('TRF-IN-%s-%s', $targetSrcLot->lot_number, $now->format('YmdHis')),
                'date' => $payload['date'] ?? $now->toDateString(),
                'company_id' => $companyId,
                'branch_id' => $newToBranch,
                'item_id' => $targetSrcLot->item_id,
                'type' => 'TRANSFER_IN',
                'quantity' => $newQty,
                'signed_quantity' => $newQty,
                'rate' => $unitCost,
                'amount' => $value,
                'oil_pct' => $targetSrcLot->oil_pct,
                'grade_id' => $targetSrcLot->grade_id,
                'lot_number' => $targetSrcLot->lot_number,
                'unit_id' => $targetSrcLot->unit_id,
                'reference_type' => 'TRANSFER',
                'reference_id' => $transfer->id,
                'source_reference' => $transfer->transfer_number,
                'customer' => 'From Godown #'.$newFromBranch,
                'remarks' => $payload['reference'] ?? $payload['remarks'] ?? null,
                'status' => 'POSTED',
                'created_by' => $userId,
            ]);

            // Handle vehicle
            $vehicleId = $transfer->vehicle_id;
            $vNum = trim($payload['vehicle_number'] ?? $payload['vehicle'] ?? '');
            if ($vNum !== '') {
                $veh = \App\Models\Vehicle::where('vehicle_number', $vNum)->first();
                if (! $veh) {
                    $veh = \App\Models\Vehicle::create([
                        'vehicle_number' => $vNum,
                        'status' => 'ACTIVE',
                        'company_id' => $companyId,
                    ]);
                }
                $vehicleId = $veh->id;
            }

            // 6. Update StockTransfer header
            $transfer->update([
                'date' => $payload['date'] ?? $transfer->date,
                'from_branch_id' => $newFromBranch,
                'to_branch_id' => $newToBranch,
                'item_id' => $targetSrcLot->item_id,
                'quantity' => $newQty,
                'unit_id' => $targetSrcLot->unit_id,
                'lot_number' => $targetSrcLot->lot_number,
                'vehicle_id' => $vehicleId,
                'remarks' => $payload['reference'] ?? $payload['remarks'] ?? $transfer->remarks,
            ]);

            return $transfer->fresh(['fromBranch', 'toBranch', 'item.unit', 'unit', 'vehicle', 'creator']);
        });
    }

    public function trashTransfer(\App\Models\StockTransfer $transfer): \App\Models\StockTransfer
    {
        return DB::transaction(function () use ($transfer) {
            $transfer = \App\Models\StockTransfer::whereKey($transfer->id)->lockForUpdate()->first();
            if ($transfer->trashed()) return $transfer;

            $destBranchId = (int) $transfer->to_branch_id;
            $srcBranchId = (int) $transfer->from_branch_id;
            $lotNumber = $transfer->lot_number;
            $itemId = (int) $transfer->item_id;
            $qty = (float) $transfer->quantity;

            // 1. Check destination lot
            $destLot = Lot::where('branch_id', $destBranchId)
                ->where('item_id', $itemId)
                ->where('lot_number', $lotNumber)
                ->lockForUpdate()
                ->first();

            if ($destLot && (float) $destLot->issued_qty > 0 && (float) $destLot->available_qty < $qty) {
                throw new \RuntimeException(sprintf(
                    'Cannot delete transfer: destination lot %s has already been partially dispatched/used (available: %s, needed: %s).',
                    $lotNumber, $destLot->available_qty, $qty
                ));
            }

            // Revert destination lot stock
            if ($destLot) {
                $destLotAvail = max(0, (float) $destLot->available_qty - $qty);
                $destLotRec = max(0, (float) $destLot->received_qty - $qty);
                $cost = (float) $destLot->unit_cost;
                $destLot->update([
                    'available_qty' => $destLotAvail,
                    'received_qty' => $destLotRec,
                    'stock_value' => round(max(0, $destLotAvail * $cost), 2),
                    'status' => ($destLotAvail <= 0 && (float) $destLot->issued_qty <= 0) ? 'CLOSED' : $destLot->status,
                ]);
            }

            // Restore source lot stock
            $srcLot = Lot::where('branch_id', $srcBranchId)
                ->where('item_id', $itemId)
                ->where('lot_number', $lotNumber)
                ->lockForUpdate()
                ->first();

            if ($srcLot) {
                $srcLotAvail = (float) $srcLot->available_qty + $qty;
                $srcLotIssued = max(0, (float) $srcLot->issued_qty - $qty);
                $srcCost = (float) $srcLot->unit_cost;
                $srcLot->update([
                    'available_qty' => $srcLotAvail,
                    'issued_qty' => $srcLotIssued,
                    'stock_value' => round(max(0, $srcLotAvail * $srcCost), 2),
                    'status' => 'OPEN',
                ]);
            }

            // Delete or soft-delete ledger transactions
            InventoryTransaction::where(function ($q) use ($transfer) {
                $q->where(function ($qq) use ($transfer) {
                    $qq->where('reference_type', 'TRANSFER')
                       ->where('reference_id', $transfer->id);
                })->orWhere(function ($qq) use ($transfer) {
                    $qq->where('source_reference', $transfer->transfer_number);
                });
            })->delete();

            $transfer->delete();
            return $transfer;
        });
    }

    public function restoreTransfer(\App\Models\StockTransfer $transfer, int $userId): \App\Models\StockTransfer
    {
        return DB::transaction(function () use ($transfer, $userId) {
            $destBranchId = (int) $transfer->to_branch_id;
            $srcBranchId = (int) $transfer->from_branch_id;
            $lotNumber = $transfer->lot_number;
            $itemId = (int) $transfer->item_id;
            $qty = (float) $transfer->quantity;

            // Deduct from source lot
            $srcLot = Lot::where('branch_id', $srcBranchId)
                ->where('item_id', $itemId)
                ->where('lot_number', $lotNumber)
                ->lockForUpdate()
                ->first();

            if (!$srcLot || (float) $srcLot->available_qty < $qty) {
                throw new \RuntimeException(sprintf('Insufficient stock to restore transfer: source lot %s only has %s available.', $lotNumber, $srcLot ? $srcLot->available_qty : 0));
            }

            $srcLot->decrement('available_qty', $qty);
            $srcLot->increment('issued_qty', $qty);
            if ((float) $srcLot->available_qty <= 0) {
                $srcLot->update(['status' => 'CLOSED', 'available_qty' => 0]);
            }

            $unitCost = (float) $srcLot->unit_cost;
            $value = round($unitCost * $qty, 2);
            $now = now();
            $companyId = (int) $srcLot->company_id;

            // Post TRANSFER_OUT
            $this->postMovement([
                'transaction_number' => sprintf('TRF-OUT-%s-%s', $lotNumber, $now->format('YmdHis')),
                'date' => $transfer->date ?? $now->toDateString(),
                'company_id' => $companyId,
                'branch_id' => $srcBranchId,
                'item_id' => $itemId,
                'type' => 'TRANSFER_OUT',
                'quantity' => $qty,
                'signed_quantity' => -$qty,
                'rate' => $unitCost,
                'amount' => $value,
                'lot_number' => $lotNumber,
                'unit_id' => $transfer->unit_id,
                'reference_type' => 'TRANSFER',
                'reference_id' => $transfer->id,
                'source_reference' => $transfer->transfer_number,
                'destination' => 'Godown #'.$destBranchId,
                'status' => 'POSTED',
                'created_by' => $userId,
            ]);

            // Add to destination lot
            $destLot = Lot::where('company_id', $companyId)
                ->where('branch_id', $destBranchId)
                ->where('item_id', $itemId)
                ->where('lot_number', $lotNumber)
                ->lockForUpdate()
                ->first();

            if ($destLot && $destLot->status === 'OPEN') {
                $destLot->increment('received_qty', $qty);
                $destLot->increment('available_qty', $qty);
                $destLot->increment('stock_value', $value);
            } else {
                $destLot = Lot::create([
                    'company_id' => $companyId,
                    'branch_id' => $destBranchId,
                    'item_id' => $itemId,
                    'grade_id' => $srcLot->grade_id,
                    'lot_number' => $lotNumber,
                    'oil_pct' => $srcLot->oil_pct,
                    'opening_qty' => 0,
                    'received_qty' => $qty,
                    'issued_qty' => 0,
                    'available_qty' => $qty,
                    'reserved_qty' => 0,
                    'unit_id' => $transfer->unit_id,
                    'unit_cost' => $unitCost,
                    'stock_value' => $value,
                    'source' => 'transfer',
                    'status' => 'OPEN',
                ]);
            }

            // Post TRANSFER_IN
            $this->postMovement([
                'transaction_number' => sprintf('TRF-IN-%s-%s', $lotNumber, $now->format('YmdHis')),
                'date' => $transfer->date ?? $now->toDateString(),
                'company_id' => $companyId,
                'branch_id' => $destBranchId,
                'item_id' => $itemId,
                'type' => 'TRANSFER_IN',
                'quantity' => $qty,
                'signed_quantity' => $qty,
                'rate' => $unitCost,
                'amount' => $value,
                'lot_number' => $lotNumber,
                'unit_id' => $transfer->unit_id,
                'reference_type' => 'TRANSFER',
                'reference_id' => $transfer->id,
                'source_reference' => $transfer->transfer_number,
                'customer' => 'From Godown #'.$srcBranchId,
                'status' => 'POSTED',
                'created_by' => $userId,
            ]);

            $transfer->restore();
            return $transfer;
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
