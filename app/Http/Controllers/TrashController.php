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
        'settlement' => Settlement::class,
        'debit_note' => DebitNote::class,
        'invoice' => Invoice::class,
        'vendor' => Vendor::class,
        'item' => Item::class,
        'branch' => Branch::class,
    ];

    /**
     * Get trashed items across all or filtered types
     */
    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type', 'all');
        $companyId = $request->query('company_id') ?? auth()->user()?->company_id;

        $counts = [];
        $items = [];

        foreach ($this->models as $key => $modelClass) {
            $query = $modelClass::onlyTrashed();

            // Filter by company if the model has company_id
            if ($companyId) {
                if (in_array($key, ['inward', 'outward', 'production', 'vendor', 'item', 'branch'])) {
                    $query->where('company_id', $companyId);
                } elseif (in_array($key, ['settlement', 'debit_note', 'invoice'])) {
                    $query->whereHas('branch', function ($q) use ($companyId) {
                        $q->where('company_id', $companyId);
                    });
                }
            }

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

        $item->restore();

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

        DB::transaction(function () use ($type, $item) {
            // Delete dependent child rows if any
            if ($type === 'inward' && method_exists($item, 'items')) {
                $item->items()->delete();
            } elseif ($type === 'outward' && method_exists($item, 'items')) {
                $item->items()->delete();
            } elseif ($type === 'production') {
                if (method_exists($item, 'inputs')) $item->inputs()->delete();
                if (method_exists($item, 'outputs')) $item->outputs()->delete();
            } elseif ($type === 'debit_note' && method_exists($item, 'items')) {
                $item->items()->delete();
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

        $deletedCount = 0;

        DB::transaction(function () use ($typesToEmpty, &$deletedCount) {
            foreach ($typesToEmpty as $t) {
                if (!isset($this->models[$t])) continue;
                $modelClass = $this->models[$t];
                $records = $modelClass::onlyTrashed()->get();
                foreach ($records as $item) {
                    if ($t === 'inward' && method_exists($item, 'items')) {
                        $item->items()->delete();
                    } elseif ($t === 'outward' && method_exists($item, 'items')) {
                        $item->items()->delete();
                    } elseif ($t === 'production') {
                        if (method_exists($item, 'inputs')) $item->inputs()->delete();
                        if (method_exists($item, 'outputs')) $item->outputs()->delete();
                    } elseif ($t === 'debit_note' && method_exists($item, 'items')) {
                        $item->items()->delete();
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
