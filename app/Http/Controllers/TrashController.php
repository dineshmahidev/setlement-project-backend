<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\InwardEntry;
use App\Models\Item;
use App\Models\OutwardEntry;
use App\Models\ProductionBatch;
use App\Models\Settlement;
use App\Models\StockTransfer;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrashController extends Controller
{
    private array $models = [
        'inward' => InwardEntry::class,
        'outward' => OutwardEntry::class,
        'production' => ProductionBatch::class,
        'stock_transfer' => StockTransfer::class,
        'settlement' => Settlement::class,
        'debit_note' => DebitNote::class,
        'invoice' => Invoice::class,
        'vendor' => Vendor::class,
        'item' => Item::class,
        'branch' => Branch::class,
        'lot' => \App\Models\Lot::class,
    ];

    /**
     * Get trashed items across all or filtered types
     */
    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type', 'all');
        // Prefer the middleware-resolved active company; User has no company_id column.
        $companyId = $request->query('company_id') ?: $this->activeCompanyId();

        $counts = [];
        $items = [];

        foreach ($this->models as $key => $modelClass) {
            $query = $this->scopeTrashQuery($modelClass::onlyTrashed(), $key, $companyId);

            $count = $query->count();
            $counts[$key] = $count;

            if ($type === 'all' || $type === $key) {
                $trashedRecords = (clone $query)->orderByDesc('deleted_at')->limit(100)->get();
                foreach ($trashedRecords as $rec) {
                    $items[] = $this->transformTrashedItem($key, $rec);
                }
            }
        }

        $counts['all'] = array_sum($counts);

        // Sort combined items by deleted_at desc
        usort($items, function ($a, $b) {
            return strcmp($b['deleted_at'] ?? '', $a['deleted_at'] ?? '');
        });

        return response()->json([
            'success' => true,
            'counts' => $counts,
            'items' => $items,
        ]);
    }

    /**
     * Restrict a trash query to the active company (by company_id column or via branch).
     */
    private function scopeTrashQuery($query, string $type, $companyId)
    {
        if (! $companyId) {
            return $query;
        }

        if (in_array($type, ['inward', 'outward', 'production', 'vendor', 'item', 'branch', 'lot'], true)) {
            $query->where('company_id', $companyId);
        } elseif (in_array($type, ['settlement', 'debit_note', 'invoice'], true)) {
            $query->whereHas('branch', fn ($q) => $q->where('company_id', $companyId));
        } elseif ($type === 'stock_transfer') {
            $query->where(function ($q) use ($companyId) {
                $q->whereHas('fromBranch', fn ($b) => $b->where('company_id', $companyId))
                  ->orWhereHas('toBranch', fn ($b) => $b->where('company_id', $companyId));
            });
        }

        return $query;
    }

    /**
     * 404 when a trashed record belongs to another company.
     */
    private function guardTrashCompany(string $type, $item): void
    {
        $companyId = $this->activeCompanyId();
        if ($companyId === null) {
            return; // super admin with no company context
        }

        if ($type === 'stock_transfer') {
            $fromComp = \App\Models\Branch::withTrashed()->where('id', $item->from_branch_id)->value('company_id');
            $toComp = \App\Models\Branch::withTrashed()->where('id', $item->to_branch_id)->value('company_id');
            if ((int) $fromComp !== $companyId && (int) $toComp !== $companyId) {
                abort(404, 'Not found.');
            }
            return;
        }

        $attrs = $item->getAttributes();

        if (array_key_exists('company_id', $attrs)) {
            if ($attrs['company_id'] === null || (int) $attrs['company_id'] !== $companyId) {
                abort(404, 'Not found.');
            }
            return;
        }

        if (array_key_exists('branch_id', $attrs) && $attrs['branch_id'] !== null) {
            $branchCompanyId = \App\Models\Branch::withTrashed()->where('id', $attrs['branch_id'])->value('company_id');
            if ($branchCompanyId === null || (int) $branchCompanyId !== $companyId) {
                abort(404, 'Not found.');
            }
        }
    }

    /**
     * Restore a soft-deleted item
     */
    public function restore(string $type, int $id): JsonResponse
    {
        if (!isset($this->models[$type])) {
            return response()->json(['success' => false, 'message' => 'Invalid trash type.'], 400);
        }

        $modelClass = $this->models[$type];
        $item = $modelClass::onlyTrashed()->find($id);

        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Item not found in trash.'], 404);
        }

        $this->guardTrashCompany($type, $item);

        if ($type === 'lot') {
            DB::transaction(function () use ($item) {
                $item->restore();
                \App\Models\InventoryTransaction::onlyTrashed()
                    ->where('lot_number', $item->lot_number)
                    ->where('branch_id', $item->branch_id)
                    ->where('item_id', $item->item_id)
                    // VOID rows are superseded copies from entry edits — never bring them back
                    ->where('transaction_number', 'NOT LIKE', '%-VOID-%')
                    ->restore();
            });
        } elseif ($type === 'production') {
            DB::transaction(function () use ($item) {
                $item->restore();
                if ($item->status === 'CONFIRMED') {
                    $item->update(['status' => 'DRAFT']);
                    $userId = (int) (auth()->id() ?? 1);
                    app(\App\Services\InventoryLedgerService::class)->confirmProduction($item, $userId);
                }
            });
        } elseif ($type === 'stock_transfer') {
            $userId = (int) (auth()->id() ?? 1);
            app(\App\Services\InventoryLedgerService::class)->restoreTransfer($item, $userId);
        } elseif ($type === 'settlement') {
            DB::transaction(function () use ($item) {
                $item->restore();

                // Restore any linked debit notes and re-post ledger
                $linkedNotes = DebitNote::onlyTrashed()->where('settlement_id', $item->id)->get();
                foreach ($linkedNotes as $dn) {
                    $dn->restore();
                    $noteType = $dn->transaction_type ?: 'debit';
                    $ledgerDescription = (($noteType === 'credit') ? 'Credit Note against Bill #' : 'Rebate / Debit Note against Bill #')
                        . ($item->bill_number ?? $item->settlement_number);
                    \App\Models\VendorLedger::syncForNote($dn, $ledgerDescription);
                }

                if ($item->vendor_id) {
                    \App\Models\VendorLedger::recalculateBalances($item->vendor_id);
                }
            });
        } elseif ($type === 'inward') {
            DB::transaction(function () use ($item) {
                $item->restore();
                // Restore any linked lots that were created exclusively for this inward
                \App\Models\Lot::onlyTrashed()->where('source', 'INWARD')->where('source_id', $item->id)->restore();
                // Restore its inventory transactions
                \App\Models\InventoryTransaction::onlyTrashed()
                    ->where('reference_type', 'INWARD')
                    ->where('reference_id', $item->id)
                    ->where('transaction_number', 'NOT LIKE', '%-VOID-%')
                    ->restore();
                // Re-credit lot balances
                foreach ($item->items as $it) {
                    $lotNo = $it->lot_number ?: sprintf('LOT-%s-%d', $item->entry_number, $it->id);
                    $lot = \App\Models\Lot::where('company_id', $item->company_id)
                        ->where('branch_id', $item->branch_id)
                        ->where('item_id', $it->item_id)
                        ->where('lot_number', $lotNo)
                        ->first();
                    if ($lot) {
                        $qty = (float) $it->quantity;
                        $amount = (float) $it->amount;
                        $newAvail = (float) $lot->available_qty + $qty;
                        $newVal = max(0, round((float) $lot->stock_value + $amount, 2));
                        $lot->update([
                            'received_qty' => $lot->received_qty + $qty,
                            'available_qty' => $newAvail,
                            'stock_value' => $newVal,
                            'unit_cost' => $newAvail > 0 ? round($newVal / $newAvail, 4) : 0,
                            'status' => $newAvail <= 0 ? 'CLOSED' : 'OPEN',
                        ]);
                    }
                }
            });
        } elseif ($type === 'outward') {
            DB::transaction(function () use ($item) {
                $item->restore();
                // Restore its inventory transactions
                \App\Models\InventoryTransaction::onlyTrashed()
                    ->where('reference_type', 'OUTWARD')
                    ->where('reference_id', $item->id)
                    ->where('transaction_number', 'NOT LIKE', '%-VOID-%')
                    ->restore();

                // If outward was CONFIRMED, re-consume lot balances
                if ($item->status === 'CONFIRMED') {
                    $allowNegative = (bool) \App\Models\CompanySetting::getValue('allow_negative_stock', false);
                    foreach ($item->items as $it) {
                        $qty = (float) $it->quantity;
                        if ($qty <= 0) continue;

                        if ($it->lot_id) {
                            $lot = \App\Models\Lot::whereKey($it->lot_id)->lockForUpdate()->first();
                            if ($lot) {
                                $avail = (float) $lot->available_qty;
                                if (!$allowNegative && $qty > $avail) {
                                    throw new \RuntimeException(sprintf('Cannot restore outward: insufficient stock in lot %s (requested %s, available %s).', $lot->lot_number, $qty, $avail));
                                }
                                $newAvail = max(0, $avail - $qty);
                                $lot->update([
                                    'issued_qty' => (float) $lot->issued_qty + $qty,
                                    'available_qty' => $newAvail,
                                    'stock_value' => round(max(0, $newAvail * (float) $lot->unit_cost), 2),
                                    'status' => $newAvail <= 0 ? 'CLOSED' : 'OPEN',
                                ]);
                            }
                        } else {
                            // FIFO across available lots
                            $remaining = $qty;
                            $lots = \App\Models\Lot::where('company_id', $item->company_id)
                                ->where('branch_id', $item->branch_id)
                                ->where('item_id', $it->item_id)
                                ->where('status', 'OPEN')
                                ->where('available_qty', '>', 0)
                                ->orderBy('id')
                                ->lockForUpdate()->get();

                            $totalAvail = (float) $lots->sum('available_qty');
                            if (!$allowNegative && $qty > $totalAvail) {
                                throw new \RuntimeException(sprintf('Cannot restore outward: insufficient stock (requested %s, available %s).', $qty, $totalAvail));
                            }

                            foreach ($lots as $lot) {
                                if ($remaining <= 0) break;
                                $take = min($remaining, (float) $lot->available_qty);
                                $cost = (float) $lot->unit_cost;
                                $newAvail = (float) $lot->available_qty - $take;
                                $lot->update([
                                    'issued_qty' => (float) $lot->issued_qty + $take,
                                    'available_qty' => $newAvail,
                                    'stock_value' => round($newAvail * $cost, 2),
                                    'status' => $newAvail <= 0 ? 'CLOSED' : 'OPEN',
                                ]);
                                $remaining -= $take;
                            }
                        }
                    }
                }
            });
        } elseif ($type === 'debit_note') {
            DB::transaction(function () use ($item) {
                $item->restore();
                $noteType = $item->transaction_type ?: 'debit';
                $ledgerDescription = (($noteType === 'credit') ? 'Credit Note #' : 'Debit Note #') . $item->debit_note_number;
                \App\Models\VendorLedger::syncForNote($item, $ledgerDescription);

                if ($item->vendor_id) {
                    \App\Models\VendorLedger::recalculateBalances($item->vendor_id);
                }
            });
        } else {
            $item->restore();
        }

        return response()->json([
            'success' => true,
            'message' => ucfirst(str_replace('_', ' ', $type)) . ' restored successfully.',
        ]);
    }

    /**
     * Permanently delete an item from trash
     */
    public function forceDelete(string $type, int $id): JsonResponse
    {
        if (!isset($this->models[$type])) {
            return response()->json(['success' => false, 'message' => 'Invalid trash type.'], 400);
        }

        $modelClass = $this->models[$type];
        $item = $modelClass::onlyTrashed()->find($id);

        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Item not found in trash.'], 404);
        }

        $this->guardTrashCompany($type, $item);

        DB::transaction(function () use ($type, $item) {
            // Delete dependent child rows if any
            if ($type === 'inward') {
                if (method_exists($item, 'items')) $item->items()->forceDelete();
                \App\Models\Lot::withTrashed()->where('source', 'INWARD')->where('source_id', $item->id)->where('issued_qty', 0)->forceDelete();
                \App\Models\InventoryTransaction::withTrashed()->where('reference_type', 'INWARD')->where('reference_id', $item->id)->forceDelete();
            } elseif ($type === 'outward') {
                $item->items()->delete();
                \App\Models\InventoryTransaction::withTrashed()->where('reference_type', 'OUTWARD')->where('reference_id', $item->id)->forceDelete();
            } elseif ($type === 'production') {
                if (method_exists($item, 'inputs')) $item->inputs()->delete();
                if (method_exists($item, 'outputs')) $item->outputs()->delete();
            } elseif ($type === 'debit_note' && method_exists($item, 'items')) {
                $item->items()->delete();
            } elseif ($type === 'settlement') {
                if (method_exists($item, 'items')) $item->items()->delete();
                if (method_exists($item, 'qualityTests')) $item->qualityTests()->delete();
                if (method_exists($item, 'charges')) $item->charges()->delete();
            } elseif ($type === 'lot') {
                \App\Models\InventoryTransaction::onlyTrashed()
                    ->where('lot_number', $item->lot_number)
                    ->where('branch_id', $item->branch_id)
                    ->where('item_id', $item->item_id)
                    ->forceDelete();
            } elseif ($type === 'stock_transfer') {
                \App\Models\InventoryTransaction::where(function ($q) use ($item) {
                    $q->where(function ($qq) use ($item) {
                        $qq->where('reference_type', 'TRANSFER')
                           ->where('reference_id', $item->id);
                    })->orWhere('source_reference', $item->transfer_number);
                })->forceDelete();
            }

            $item->forceDelete();
        });

        return response()->json([
            'success' => true,
            'message' => ucfirst(str_replace('_', ' ', $type)) . ' permanently deleted.',
        ]);
    }

    /**
     * Empty trash completely or by type
     */
    public function emptyTrash(Request $request): JsonResponse
    {
        $type = $request->input('type', 'all');
        $typesToEmpty = ($type === 'all' || empty($type)) ? array_keys($this->models) : [$type];
        $companyId = $request->input('company_id') ?: $this->activeCompanyId();

        $deletedCount = 0;

        DB::transaction(function () use ($typesToEmpty, &$deletedCount, $companyId) {
            foreach ($typesToEmpty as $t) {
                if (!isset($this->models[$t])) continue;
                $modelClass = $this->models[$t];
                $records = $this->scopeTrashQuery($modelClass::onlyTrashed(), $t, $companyId)->get();
                foreach ($records as $item) {
                    if ($t === 'inward') {
                        if (method_exists($item, 'items')) $item->items()->forceDelete();
                        \App\Models\Lot::withTrashed()->where('source', 'INWARD')->where('source_id', $item->id)->where('issued_qty', 0)->forceDelete();
                        \App\Models\InventoryTransaction::withTrashed()->where('reference_type', 'INWARD')->where('reference_id', $item->id)->forceDelete();
                    } elseif ($t === 'outward') {
                        $item->items()->delete();
                        \App\Models\InventoryTransaction::withTrashed()->where('reference_type', 'OUTWARD')->where('reference_id', $item->id)->forceDelete();
                    } elseif ($t === 'production') {
                        if (method_exists($item, 'inputs')) $item->inputs()->delete();
                        if (method_exists($item, 'outputs')) $item->outputs()->delete();
                    } elseif ($t === 'debit_note' && method_exists($item, 'items')) {
                        $item->items()->delete();
                    } elseif ($t === 'settlement') {
                        if (method_exists($item, 'items')) $item->items()->delete();
                        if (method_exists($item, 'qualityTests')) $item->qualityTests()->delete();
                        if (method_exists($item, 'charges')) $item->charges()->delete();
                    } elseif ($t === 'lot') {
                        \App\Models\InventoryTransaction::onlyTrashed()
                            ->where('lot_number', $item->lot_number)
                            ->where('branch_id', $item->branch_id)
                            ->where('item_id', $item->item_id)
                            ->forceDelete();
                    } elseif ($t === 'stock_transfer') {
                        \App\Models\InventoryTransaction::where(function ($q) use ($item) {
                            $q->where(function ($qq) use ($item) {
                                $qq->where('reference_type', 'TRANSFER')
                                   ->where('reference_id', $item->id);
                            })->orWhere('source_reference', $item->transfer_number);
                        })->forceDelete();
                    }
                    $item->forceDelete();
                    $deletedCount++;
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => "Trash emptied successfully ({$deletedCount} items permanently deleted).",
            'deleted_count' => $deletedCount,
        ]);
    }

    private function transformTrashedItem(string $type, $rec): array
    {
        $title = '';
        $subtitle = '';
        $amount = null;

        switch ($type) {
            case 'inward':
                $rec->loadMissing(['party:id,vendor_name,business_name', 'branch:id,branch_name']);
                $title = $rec->entry_number ?? ('Inward #' . $rec->id);
                $party = $rec->party?->vendor_name ?: $rec->party?->business_name ?: '-';
                $subtitle = "Party: {$party} | Godown: " . ($rec->branch?->branch_name ?? '-');
                $amount = $rec->total_amount;
                break;

            case 'outward':
                $rec->loadMissing(['party:id,vendor_name,business_name', 'branch:id,branch_name']);
                $title = $rec->entry_number ?? ('Outward #' . $rec->id);
                $party = $rec->party?->vendor_name ?: $rec->party?->business_name ?: '-';
                $subtitle = "Party: {$party} | Dest: " . ($rec->destination ?: ($rec->branch?->branch_name ?? '-'));
                $amount = $rec->total_amount;
                break;

            case 'production':
                $rec->loadMissing(['outputItem:id,name']);
                $title = $rec->batch_number ?? ('Batch #' . $rec->id);
                $subtitle = "Output: " . ($rec->outputItem?->name ?? 'Mixed Product') . " (Qty: {$rec->output_total})";
                break;

            case 'settlement':
                $rec->loadMissing(['vendor:id,vendor_name,business_name']);
                $title = $rec->settlement_number ?? ('Settlement #' . $rec->id);
                $party = $rec->vendor?->vendor_name ?: $rec->vendor?->business_name ?: '-';
                $subtitle = "Vendor: {$party} | Bill: " . ($rec->bill_number ?: '-');
                $amount = $rec->final_payable_amount ?? $rec->bran_value;
                break;

            case 'debit_note':
                $rec->loadMissing(['vendor:id,vendor_name,business_name']);
                $title = $rec->debit_note_number ?? ('DN #' . $rec->id);
                $party = $rec->vendor?->vendor_name ?: $rec->vendor?->business_name ?: '-';
                $subtitle = "Vendor: {$party} | Type: " . strtoupper($rec->transaction_type ?? 'Debit');
                $amount = $rec->total_amount;
                break;

            case 'invoice':
                $rec->loadMissing(['vendor:id,vendor_name,business_name']);
                $title = $rec->invoice_number ?? ('INV #' . $rec->id);
                $party = $rec->vendor?->vendor_name ?: $rec->vendor?->business_name ?: '-';
                $subtitle = "Party: {$party}";
                $amount = $rec->total_amount;
                break;

            case 'vendor':
                $title = $rec->vendor_name ?: $rec->business_name ?: ('Vendor #' . $rec->id);
                $subtitle = "Code: {$rec->vendor_code} | GST: " . ($rec->gstin ?: '-') . " | Phone: {$rec->phone}";
                break;

            case 'item':
                $title = $rec->name ?? ('Item #' . $rec->id);
                $subtitle = "Code: {$rec->code} | Type: {$rec->material_type}";
                break;

            case 'branch':
                $title = $rec->branch_name ?? ('Godown #' . $rec->id);
                $subtitle = "Code: {$rec->branch_code} | City: {$rec->city}";
                break;

            case 'lot':
                $rec->loadMissing(['item:id,name,code', 'branch:id,branch_name']);
                $title = 'Lot ' . ($rec->lot_number ?? '#' . $rec->id);
                $subtitle = "Item: " . ($rec->item?->name ?? '-') . " | Godown: " . ($rec->branch?->branch_name ?? '-') . " | Avail: " . number_format((float) ($rec->available_qty ?? 0), 3);
                $amount = $rec->stock_value;
                break;

            case 'stock_transfer':
                $rec->loadMissing(['fromBranch:id,branch_name', 'toBranch:id,branch_name', 'item:id,name', 'unit:id,code']);
                $title = $rec->transfer_number ?? ('TRF #' . $rec->id);
                $from = $rec->fromBranch?->branch_name ?? ('Godown #' . $rec->from_branch_id);
                $to = $rec->toBranch?->branch_name ?? ('Godown #' . $rec->to_branch_id);
                $unit = $rec->unit?->code ?: 'KG';
                $subtitle = "From: {$from} -> To: {$to} | Item: " . ($rec->item?->name ?? '-') . " (Qty: " . number_format((float)$rec->quantity, 3) . " {$unit})";
                break;
        }

        return [
            'id' => $rec->id,
            'type' => $type,
            'title' => $title,
            'subtitle' => $subtitle,
            'amount' => $amount,
            'deleted_at' => $rec->deleted_at ? $rec->deleted_at->format('Y-m-d H:i') : null,
            'raw_deleted_at' => $rec->deleted_at ? $rec->deleted_at->toISOString() : null,
        ];
    }
}
