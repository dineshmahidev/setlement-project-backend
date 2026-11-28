<?php

namespace Tests\Feature;

use App\Models\{Branch, Company, InwardEntry, InventoryTransaction, Item, Lot, OutwardEntry, User, Vendor};
use App\Services\InventoryLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryLedgerTest extends TestCase
{
    use RefreshDatabase;

    private InventoryLedgerService $ledger;
    private Company $company;
    private Branch $branch;
    private Item $item;
    private Vendor $vendor;
    private int $userId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(InventoryLedgerService::class);

        $this->company = Company::create([
            'code' => 'testco', 'slug' => 'testco', 'company_name' => 'Test Co', 'status' => 'active',
        ]);
        $this->branch = Branch::create([
            'company_id' => $this->company->id, 'branch_code' => 'TB1', 'branch_name' => 'Test Godown', 'status' => 'active',
        ]);
        $this->item = Item::create([
            'company_id' => $this->company->id, 'code' => 'IT-1', 'name' => 'Rice Bran', 'material_type' => 'Raw', 'status' => 'ACTIVE',
        ]);
        $this->vendor = Vendor::create([
            'company_id' => $this->company->id, 'vendor_code' => 'V1', 'vendor_name' => 'Test Supplier', 'status' => 'active',
        ]);
        User::create(['name' => 'Admin', 'email' => 'a@b.com', 'password' => bcrypt('password')]);
    }

    private function makeInward(array $lines): InwardEntry
    {
        $in = InwardEntry::create([
            'entry_number' => $this->ledger->nextNumber('INW', 'inward_entries', 'entry_number'),
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'party_id' => $this->vendor->id, 'date' => now()->toDateString(),
            'status' => 'DRAFT', 'created_by' => $this->userId, 'total_amount' => 0,
        ]);
        $total = 0;
        foreach ($lines as $l) {
            $amt = $l['quantity'] * ($l['rate'] ?? 0);
            $total += $amt;
            $in->items()->create([...$l, 'amount' => $amt]);
        }
        $in->update(['total_amount' => $total]);
        return $in;
    }

    public function test_draft_inward_does_not_affect_stock(): void
    {
        $before = $this->ledger->availableQty($this->company->id, $this->branch->id, $this->item->id);
        $this->makeInward([['item_id' => $this->item->id, 'quantity' => 100, 'rate' => 10]]);
        $after = $this->ledger->availableQty($this->company->id, $this->branch->id, $this->item->id);
        $this->assertSame($before, $after);
    }

    public function test_confirm_inward_creates_lot_with_grade(): void
    {
        $in = $this->makeInward([['item_id' => $this->item->id, 'lot_number' => 'L1', 'quantity' => 10, 'oil_pct' => 18.5, 'rate' => 300]]);
        $this->ledger->confirmInward($in->fresh('items'), $this->userId);

        $lot = Lot::where('company_id', $this->company->id)->where('lot_number', 'L1')->first();
        $this->assertNotNull($lot);
        $this->assertEquals(10, (float) $lot->available_qty);
        $this->assertEquals(18.5, (float) $lot->oil_pct);
        $this->assertEquals(3000, (float) $lot->stock_value);
        $this->assertEquals(10, $this->ledger->availableQty($this->company->id, $this->branch->id, $this->item->id));
    }

    public function test_direct_outward_deducts_specific_lot(): void
    {
        $in = $this->makeInward([['item_id' => $this->item->id, 'lot_number' => 'L1', 'quantity' => 10, 'oil_pct' => 18, 'rate' => 100]]);
        $this->ledger->confirmInward($in->fresh('items'), $this->userId);
        $lot = Lot::where('lot_number', 'L1')->first();

        $out = OutwardEntry::create([
            'entry_number' => $this->ledger->nextNumber('OUT', 'outward_entries', 'entry_number'),
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'date' => now()->toDateString(), 'dispatch_type' => 'DIRECT', 'status' => 'DRAFT', 'created_by' => $this->userId,
        ]);
        $out->items()->create(['item_id' => $this->item->id, 'lot_id' => $lot->id, 'quantity' => 4, 'rate' => 120, 'amount' => 480]);
        $this->ledger->confirmOutward($out->fresh('items'), $this->userId);

        $this->assertEquals(6, (float) $lot->fresh()->available_qty);
        $this->assertEquals(6, $this->ledger->availableQty($this->company->id, $this->branch->id, $this->item->id));
    }

    public function test_mixed_outward_uses_fifo_across_lots(): void
    {
        foreach ([['LA', 6, 18.0], ['LB', 6, 21.0]] as [$no, $qty, $oil]) {
            $in = $this->makeInward([['item_id' => $this->item->id, 'lot_number' => $no, 'quantity' => $qty, 'oil_pct' => $oil, 'rate' => 100]]);
            $this->ledger->confirmInward($in->fresh('items'), $this->userId);
        }

        $out = OutwardEntry::create([
            'entry_number' => $this->ledger->nextNumber('OUT', 'outward_entries', 'entry_number'),
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'date' => now()->toDateString(), 'dispatch_type' => 'MIXED', 'status' => 'DRAFT', 'created_by' => $this->userId,
        ]);
        $out->items()->create(['item_id' => $this->item->id, 'quantity' => 9, 'oil_pct' => 19, 'rate' => 110, 'amount' => 990]);
        $this->ledger->confirmOutward($out->fresh('items'), $this->userId);

        // FIFO: LA fully drained (6), LB partially (6-3=3)
        $this->assertEquals(0, (float) Lot::where('lot_number', 'LA')->first()->available_qty);
        $this->assertEquals(3, (float) Lot::where('lot_number', 'LB')->first()->available_qty);
        $this->assertEquals(3, $this->ledger->availableQty($this->company->id, $this->branch->id, $this->item->id));
    }

    public function test_insufficient_stock_is_blocked(): void
    {
        $in = $this->makeInward([['item_id' => $this->item->id, 'lot_number' => 'L1', 'quantity' => 5, 'oil_pct' => 18, 'rate' => 100]]);
        $this->ledger->confirmInward($in->fresh('items'), $this->userId);
        $lot = Lot::where('lot_number', 'L1')->first();

        $out = OutwardEntry::create([
            'entry_number' => $this->ledger->nextNumber('OUT', 'outward_entries', 'entry_number'),
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'date' => now()->toDateString(), 'dispatch_type' => 'DIRECT', 'status' => 'DRAFT', 'created_by' => $this->userId,
        ]);
        $out->items()->create(['item_id' => $this->item->id, 'lot_id' => $lot->id, 'quantity' => 999, 'rate' => 1, 'amount' => 1]);

        $this->expectException(\Exception::class);
        $this->ledger->confirmOutward($out->fresh('items'), $this->userId);
    }

    public function test_cancel_inward_reverses_stock_and_preserves_history(): void
    {
        $in = $this->makeInward([['item_id' => $this->item->id, 'lot_number' => 'L1', 'quantity' => 10, 'oil_pct' => 18, 'rate' => 100]]);
        $this->ledger->confirmInward($in->fresh('items'), $this->userId);
        $this->assertEquals(10, $this->ledger->availableQty($this->company->id, $this->branch->id, $this->item->id));

        $txBefore = InventoryTransaction::where('company_id', $this->company->id)->count();
        $this->ledger->cancelInward($in->fresh('items'), $this->userId, 'test');

        $this->assertEquals(0, $this->ledger->availableQty($this->company->id, $this->branch->id, $this->item->id));
        $this->assertEquals('CANCELLED', $in->fresh()->status);
        // History preserved: reversal rows added, none deleted
        $this->assertGreaterThan($txBefore, InventoryTransaction::where('company_id', $this->company->id)->count());
    }

    public function test_stock_is_isolated_between_companies(): void
    {
        $other = Company::create(['code' => 'other', 'slug' => 'other', 'company_name' => 'Other Co', 'status' => 'active']);
        $otherBranch = Branch::create(['company_id' => $other->id, 'branch_code' => 'OB1', 'branch_name' => 'Other Godown', 'status' => 'active']);
        $otherItem = Item::create(['company_id' => $other->id, 'code' => 'IT-1', 'name' => 'Other Bran', 'material_type' => 'Raw', 'status' => 'ACTIVE']);

        $in = $this->makeInward([['item_id' => $this->item->id, 'lot_number' => 'L1', 'quantity' => 10, 'oil_pct' => 18, 'rate' => 100]]);
        $this->ledger->confirmInward($in->fresh('items'), $this->userId);

        $this->assertEquals(10, $this->ledger->availableQty($this->company->id, $this->branch->id, $this->item->id));
        $this->assertEquals(0, $this->ledger->availableQty($other->id, $otherBranch->id, $otherItem->id));
    }

    public function test_production_consumes_inputs_and_creates_output_lot(): void
    {
        $in = $this->makeInward([['item_id' => $this->item->id, 'lot_number' => 'L1', 'quantity' => 10, 'oil_pct' => 18, 'rate' => 100]]);
        $this->ledger->confirmInward($in->fresh('items'), $this->userId);
        $lot = Lot::where('lot_number', 'L1')->first();

        $outItem = Item::create(['company_id' => $this->company->id, 'code' => 'OUT-1', 'name' => 'Boiled Bran', 'material_type' => 'Boiled', 'status' => 'ACTIVE']);
        $batch = \App\Models\ProductionBatch::create([
            'batch_number' => $this->ledger->nextNumber('PRD', 'production_batches', 'batch_number'),
            'company_id' => $this->company->id, 'source_branch_id' => $this->branch->id, 'dest_branch_id' => $this->branch->id,
            'date' => now()->toDateString(), 'output_item_id' => $outItem->id, 'output_lot_number' => 'PRD-1',
            'output_oil_pct' => 19.5, 'status' => 'DRAFT', 'created_by' => $this->userId,
        ]);
        $batch->inputs()->create(['lot_id' => $lot->id, 'item_id' => $this->item->id, 'quantity' => 10, 'oil_pct' => 18]);
        $batch->outputs()->create(['item_id' => $outItem->id, 'quantity' => 9.7, 'oil_pct' => 19.5]);
        $this->ledger->confirmProduction($batch->fresh(['inputs', 'outputs']), $this->userId);

        $this->assertEquals(0, (float) $lot->fresh()->available_qty);
        $outLot = Lot::where('lot_number', 'PRD-1')->first();
        $this->assertNotNull($outLot);
        $this->assertEquals(9.7, (float) $outLot->available_qty);
        $this->assertEquals(0.3, round((float) $batch->fresh()->wastage, 2));
    }

    public function test_transfer_moves_lot_between_godowns(): void
    {
        $dest = Branch::create(['company_id' => $this->company->id, 'branch_code' => 'TB2', 'branch_name' => 'Dest Godown', 'status' => 'active']);
        $in = $this->makeInward([['item_id' => $this->item->id, 'lot_number' => 'L1', 'quantity' => 10, 'oil_pct' => 18, 'rate' => 100]]);
        $this->ledger->confirmInward($in->fresh('items'), $this->userId);
        $lot = Lot::where('lot_number', 'L1')->first();

        $result = $this->ledger->transfer([
            'company_id' => $this->company->id, 'source_branch_id' => $this->branch->id,
            'dest_branch_id' => $dest->id, 'lot_id' => $lot->id, 'quantity' => 4,
        ], $this->userId);

        // Source reduced, destination has its own lot row
        $this->assertEquals(6, (float) $lot->fresh()->available_qty);
        $destLot = Lot::where('branch_id', $dest->id)->where('lot_number', 'L1')->first();
        $this->assertNotNull($destLot);
        $this->assertEquals(4, (float) $destLot->available_qty);
        // Both movements posted
        $this->assertEquals(2, InventoryTransaction::where('company_id', $this->company->id)->whereIn('type', ['TRANSFER_OUT', 'TRANSFER_IN'])->count());
    }

    public function test_trash_inward_subtracts_stock_but_lot_remains_visible(): void
    {
        $in = $this->makeInward([['item_id' => $this->item->id, 'lot_number' => 'L1', 'quantity' => 10, 'oil_pct' => 18, 'rate' => 100]]);
        $this->ledger->confirmInward($in->fresh('items'), $this->userId);
        $lot = Lot::where('lot_number', 'L1')->first();
        $this->assertEquals(10, (float) $lot->available_qty);

        $this->ledger->trashInward($in->fresh('items'));

        // Entry trashed, stock subtracted, but the lot itself is NOT deleted (0-stock stays listed)
        $this->assertTrue($in->fresh()->trashed());
        $lot->refresh();
        $this->assertNotNull(Lot::where('lot_number', 'L1')->first());
        $this->assertEquals(0, (float) $lot->available_qty);
        $this->assertEquals(0, $this->ledger->availableQty($this->company->id, $this->branch->id, $this->item->id));
    }

    public function test_lot_delete_trashes_lot_and_transactions_then_restores(): void
    {
        $in = $this->makeInward([['item_id' => $this->item->id, 'lot_number' => 'L9', 'quantity' => 5, 'oil_pct' => 18, 'rate' => 100]]);
        $this->ledger->confirmInward($in->fresh('items'), $this->userId);
        $lot = Lot::where('lot_number', 'L9')->first();

        // Mirrors LotController::destroy — lot + dependent transactions to trash
        DB::transaction(function () use ($lot) {
            InventoryTransaction::where('lot_number', $lot->lot_number)
                ->where('branch_id', $lot->branch_id)
                ->where('item_id', $lot->item_id)
                ->delete();
            $lot->delete();
        });

        // Hidden from live views, sitting in trash with its transactions
        $this->assertNull(Lot::where('lot_number', 'L9')->first());
        $this->assertTrue($lot->fresh()->trashed());
        $this->assertEquals(0, InventoryTransaction::where('lot_number', 'L9')->count());
        $this->assertGreaterThan(0, InventoryTransaction::onlyTrashed()->where('lot_number', 'L9')->count());
        $this->assertEquals(0, $this->ledger->availableQty($this->company->id, $this->branch->id, $this->item->id));

        // Mirrors TrashController::restore (lot) — everything comes back
        DB::transaction(function () use ($lot) {
            $lot->restore();
            InventoryTransaction::onlyTrashed()
                ->where('lot_number', $lot->lot_number)
                ->where('branch_id', $lot->branch_id)
                ->where('item_id', $lot->item_id)
                ->restore();
        });

        $this->assertNotNull(Lot::where('lot_number', 'L9')->first());
        $this->assertEquals(5, (float) $lot->fresh()->available_qty);
        $this->assertGreaterThan(0, InventoryTransaction::where('lot_number', 'L9')->count());
        $this->assertEquals(5, $this->ledger->availableQty($this->company->id, $this->branch->id, $this->item->id));
    }

    public function test_edit_confirmed_inward_updates_ledger_row_in_place(): void
    {
        $in = $this->makeInward([['item_id' => $this->item->id, 'lot_number' => 'L1', 'quantity' => 5, 'oil_pct' => 18, 'rate' => 100]]);
        $this->ledger->confirmInward($in->fresh('items'), $this->userId);
        $origTx = InventoryTransaction::where('reference_type', 'INWARD')->where('reference_id', $in->id)->first();

        // Stale reversal row left behind by the old cancel-based edit flow
        InventoryTransaction::create([
            'transaction_number' => $in->entry_number.'-R-STALE',
            'date' => now()->toDateString(),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'item_id' => $this->item->id,
            'type' => 'STOCK_OUT',
            'quantity' => 5,
            'signed_quantity' => -5,
            'lot_number' => 'L1',
            'reference_type' => 'INWARD_CANCEL',
            'reference_id' => $in->id,
            'status' => 'POSTED',
            'created_by' => $this->userId,
        ]);

        // PUT edit 5 -> 15 (mirrors InwardController::update CONFIRMED path)
        $request = \Illuminate\Http\Request::create('/api/inward/'.$in->id, 'PUT', [
            'branch_id' => $this->branch->id,
            'date' => now()->toDateString(),
            'items' => [[
                'item_id' => $this->item->id, 'lot_number' => 'L1', 'quantity' => 15, 'rate' => 100, 'oil_pct' => 18,
            ]],
        ]);
        $request->setUserResolver(fn () => User::find(1));
        $response = app(\App\Http\Controllers\InwardController::class)->update($request, $in->fresh());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue(json_decode($response->getContent(), true)['success'] ?? false, (string) json_decode($response->getContent(), true)['message']);

        // ONE live row — the SAME row updated in place (id + number kept → order stable)
        $live = InventoryTransaction::where('reference_type', 'INWARD')->where('reference_id', $in->id)->get();
        $this->assertCount(1, $live);
        $this->assertEquals($origTx->id, $live->first()->id);
        $this->assertEquals($origTx->transaction_number, $live->first()->transaction_number);
        $this->assertEquals(15, (float) $live->first()->signed_quantity);
        // Legacy cancel row cleaned up; raw history kept in trash
        $this->assertEquals(0, InventoryTransaction::where('reference_id', $in->id)
            ->where('reference_type', 'INWARD_CANCEL')->count());
        $this->assertGreaterThan(0, InventoryTransaction::onlyTrashed()->where('reference_id', $in->id)->count());

        // Lot adjusted in place, still OPEN
        $lot = Lot::where('lot_number', 'L1')->first();
        $this->assertEquals(15, (float) $lot->available_qty);
        $this->assertEquals(15, (float) $lot->received_qty);
        $this->assertEquals('OPEN', $lot->status);
        $this->assertEquals(1500, (float) $lot->stock_value);
        $this->assertEquals(15, $this->ledger->availableQty($this->company->id, $this->branch->id, $this->item->id));
        $this->assertEquals('CONFIRMED', $in->fresh()->status);
    }

    public function test_edit_confirmed_outward_updates_ledger_row_in_place(): void
    {
        $in = $this->makeInward([['item_id' => $this->item->id, 'lot_number' => 'L1', 'quantity' => 10, 'oil_pct' => 18, 'rate' => 100]]);
        $this->ledger->confirmInward($in->fresh('items'), $this->userId);
        $lot = Lot::where('lot_number', 'L1')->first();

        $out = OutwardEntry::create([
            'entry_number' => $this->ledger->nextNumber('OUT', 'outward_entries', 'entry_number'),
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'date' => now()->toDateString(), 'dispatch_type' => 'DIRECT', 'status' => 'DRAFT', 'created_by' => $this->userId,
        ]);
        $out->items()->create(['item_id' => $this->item->id, 'lot_id' => $lot->id, 'quantity' => 4, 'rate' => 120, 'amount' => 480]);
        $this->ledger->confirmOutward($out->fresh('items'), $this->userId);
        $origTx = InventoryTransaction::where('reference_type', 'OUTWARD')->where('reference_id', $out->id)->first();

        // PUT edit 4 -> 8 (mirrors OutwardController::update CONFIRMED path)
        $request = \Illuminate\Http\Request::create('/api/outward/'.$out->id, 'PUT', [
            'branch_id' => $this->branch->id,
            'date' => now()->toDateString(),
            'items' => [[
                'item_id' => $this->item->id, 'lot_id' => $lot->id, 'quantity' => 8, 'rate' => 120,
            ]],
        ]);
        $request->setUserResolver(fn () => User::find(1));
        $response = app(\App\Http\Controllers\OutwardController::class)->update($request, $out->fresh());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue(json_decode($response->getContent(), true)['success'] ?? false, (string) json_decode($response->getContent(), true)['message']);

        $live = InventoryTransaction::where('reference_type', 'OUTWARD')->where('reference_id', $out->id)->get();
        $this->assertCount(1, $live);
        $this->assertEquals($origTx->id, $live->first()->id); // same row, order stable
        $this->assertEquals(-8, (float) $live->first()->signed_quantity);
        $this->assertEquals(0, InventoryTransaction::where('reference_id', $out->id)
            ->where('reference_type', 'OUTWARD_CANCEL')->count());

        $lot->refresh();
        $this->assertEquals(2, (float) $lot->available_qty);
        $this->assertEquals(8, (float) $lot->issued_qty);
        $this->assertEquals('OPEN', $lot->status);
        $this->assertEquals('CONFIRMED', $out->fresh()->status);
    }

    public function test_inward_update_removing_line_voids_row_and_restores_stock(): void
    {
        $item2 = Item::create([
            'company_id' => $this->company->id, 'code' => 'IT-2', 'name' => 'Other Bran', 'material_type' => 'Raw', 'status' => 'ACTIVE',
        ]);
        $in = $this->makeInward([
            ['item_id' => $this->item->id, 'lot_number' => 'LA', 'quantity' => 5, 'rate' => 100],
            ['item_id' => $item2->id, 'lot_number' => 'LB', 'quantity' => 7, 'rate' => 50],
        ]);
        $this->ledger->confirmInward($in->fresh('items'), $this->userId);
        $this->assertEquals(7, $this->ledger->availableQty($this->company->id, $this->branch->id, $item2->id));

        // Edit keeps only the first line — second line removed
        $request = \Illuminate\Http\Request::create('/api/inward/'.$in->id, 'PUT', [
            'branch_id' => $this->branch->id,
            'date' => now()->toDateString(),
            'items' => [[
                'item_id' => $this->item->id, 'lot_number' => 'LA', 'quantity' => 5, 'rate' => 100,
            ]],
        ]);
        $request->setUserResolver(fn () => User::find(1));
        $response = app(\App\Http\Controllers\InwardController::class)->update($request, $in->fresh());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue(json_decode($response->getContent(), true)['success'] ?? false, (string) json_decode($response->getContent(), true)['message']);

        // Removed line: stock reversed, row voided to trash, line deleted
        $this->assertEquals(0, $this->ledger->availableQty($this->company->id, $this->branch->id, $item2->id));
        $this->assertEquals(0, InventoryTransaction::where('reference_id', $in->id)->where('item_id', $item2->id)->count());
        $this->assertGreaterThan(0, InventoryTransaction::onlyTrashed()->where('reference_id', $in->id)->where('item_id', $item2->id)->count());
        $this->assertEquals(1, $in->fresh()->items()->count());
        // Kept line untouched
        $this->assertEquals(1, InventoryTransaction::where('reference_type', 'INWARD')->where('reference_id', $in->id)->count());
        $this->assertEquals(5, $this->ledger->availableQty($this->company->id, $this->branch->id, $this->item->id));
        $this->assertEquals('CONFIRMED', $in->fresh()->status);
    }

    public function test_inward_update_carries_auto_lot_and_posts_single_ledger_row(): void
    {
        // Confirmed entry with NO explicit lot number (auto-generated at confirm)
        $in = $this->makeInward([['item_id' => $this->item->id, 'quantity' => 5, 'rate' => 100]]);
        $this->ledger->confirmInward($in->fresh('items'), $this->userId);
        $this->assertNotNull($in->fresh()->items->first()->lot_number);
        $this->assertEquals(1, Lot::where('item_id', $this->item->id)->count());
        $origTx = InventoryTransaction::where('reference_type', 'INWARD')->where('reference_id', $in->id)->first();

        // PUT /inward/{id} without a lot number → lot must carry over, not fork
        $request = \Illuminate\Http\Request::create('/api/inward/'.$in->id, 'PUT', [
            'branch_id' => $this->branch->id,
            'date' => now()->toDateString(),
            'items' => [[
                'item_id' => $this->item->id,
                'quantity' => 15,
                'rate' => 100,
                'lot_number' => null,
            ]],
        ]);
        $request->setUserResolver(fn () => User::find(1));

        $response = app(\App\Http\Controllers\InwardController::class)->update($request, $in->fresh());
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success'] ?? false, (string) ($data['message'] ?? 'update failed'));

        // Same lot reused (no second auto lot), stock correct, entry still CONFIRMED
        $this->assertEquals(1, Lot::where('item_id', $this->item->id)->count());
        $lot = Lot::where('item_id', $this->item->id)->first();
        $this->assertEquals(15, (float) $lot->available_qty);
        $this->assertEquals('OPEN', $lot->status);
        $this->assertEquals('CONFIRMED', $in->fresh()->status);

        // Ledger: single STOCK_IN 15 row — same row id + transaction_number kept
        $live = InventoryTransaction::where('reference_type', 'INWARD')->where('reference_id', $in->id)->get();
        $this->assertCount(1, $live);
        $this->assertEquals($origTx->id, $live->first()->id);
        $this->assertEquals($origTx->transaction_number, $live->first()->transaction_number);
        $this->assertEquals(15, (float) $live->first()->signed_quantity);
        $this->assertEquals(0, InventoryTransaction::where('reference_id', $in->id)
            ->where('reference_type', 'INWARD_CANCEL')->count());
        $this->assertEquals(15, $this->ledger->availableQty($this->company->id, $this->branch->id, $this->item->id));
    }

    /** ---- Inward rate → outward rate propagation ---- */

    private function makeConfirmedInward(float $qty, float $rate): InwardEntry
    {
        $in = $this->makeInward([['item_id' => $this->item->id, 'lot_number' => 'L1', 'quantity' => $qty, 'rate' => $rate]]);
        return $this->ledger->confirmInward($in->fresh('items'), $this->userId)->fresh('items');
    }

    private function makeOutward(Lot $lot, float $qty, float $rate, bool $confirm = true): OutwardEntry
    {
        $out = OutwardEntry::create([
            'entry_number' => $this->ledger->nextNumber('OUT', 'outward_entries', 'entry_number'),
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'date' => now()->toDateString(), 'dispatch_type' => 'DIRECT',
            'status' => 'DRAFT', 'created_by' => $this->userId, 'total_amount' => $qty * $rate,
        ]);
        $out->items()->create([
            'item_id' => $this->item->id, 'lot_id' => $lot->id,
            'quantity' => $qty, 'rate' => $rate, 'amount' => $qty * $rate,
        ]);
        return $confirm
            ? $this->ledger->confirmOutward($out->fresh('items'), $this->userId)->fresh('items')
            : $out;
    }

    private function editInwardRate(InwardEntry $in, float $rate, float $qty = 100): void
    {
        $request = \Illuminate\Http\Request::create('/api/inward/'.$in->id, 'PUT', [
            'branch_id' => $this->branch->id,
            'date' => now()->toDateString(),
            'items' => [[
                'item_id' => $this->item->id, 'lot_number' => 'L1',
                'quantity' => $qty, 'rate' => $rate,
            ]],
        ]);
        $request->setUserResolver(fn () => User::find(1));
        $response = app(\App\Http\Controllers\InwardController::class)->update($request, $in->fresh());
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success'] ?? false, (string) ($data['message'] ?? 'inward update failed'));
    }

    public function test_inward_rate_change_propagates_to_confirmed_outward_in_place(): void
    {
        $in = $this->makeConfirmedInward(100, 100);
        $lot = Lot::where('lot_number', 'L1')->first();
        $out = $this->makeOutward($lot, 4, 100);
        $origInwardTx = InventoryTransaction::where('reference_type', 'INWARD')->where('reference_id', $in->id)->first();
        $origOutwardTx = InventoryTransaction::where('reference_type', 'OUTWARD')->where('reference_id', $out->id)->first();

        // Inward rate 100 -> 150
        $this->editInwardRate($in, 150);

        // Outward line follows the inward rate, amount recalculated
        $line = $out->fresh()->items->first();
        $this->assertEquals(150, (float) $line->rate);
        $this->assertEquals(600, (float) $line->amount);
        $this->assertEquals(600, (float) $out->fresh()->total_amount);

        // Existing OUTWARD ledger row updated in place — no duplicate / no reversal
        $liveOutward = InventoryTransaction::where('reference_type', 'OUTWARD')->where('reference_id', $out->id)->get();
        $this->assertCount(1, $liveOutward);
        $this->assertEquals($origOutwardTx->id, $liveOutward->first()->id);
        $this->assertEquals(150, (float) $liveOutward->first()->rate);
        $this->assertEquals(600, (float) $liveOutward->first()->amount);

        // Existing INWARD ledger row updated in place too
        $liveInward = InventoryTransaction::where('reference_type', 'INWARD')->where('reference_id', $in->id)->get();
        $this->assertCount(1, $liveInward);
        $this->assertEquals($origInwardTx->id, $liveInward->first()->id);
        $this->assertEquals(150, (float) $liveInward->first()->rate);
        $this->assertEquals(15000, (float) $liveInward->first()->amount);

        // Header total + lot cost moved with the new rate
        $this->assertEquals(15000, (float) $in->fresh()->total_amount);
        $this->assertEquals(150, (float) $lot->fresh()->unit_cost);
        $this->assertEquals('CONFIRMED', $out->fresh()->status);
    }

    public function test_inward_rate_change_also_updates_draft_outward(): void
    {
        $in = $this->makeConfirmedInward(100, 100);
        $lot = Lot::where('lot_number', 'L1')->first();
        $out = $this->makeOutward($lot, 5, 100, confirm: false);

        $this->editInwardRate($in, 120);

        $line = $out->fresh()->items->first();
        $this->assertEquals(120, (float) $line->rate);
        $this->assertEquals(600, (float) $line->amount);
        $this->assertEquals(600, (float) $out->fresh()->total_amount);
        $this->assertEquals('DRAFT', $out->fresh()->status);
        // A draft never posts a ledger row — and this must not create one
        $this->assertEquals(0, InventoryTransaction::where('reference_type', 'OUTWARD')->where('reference_id', $out->id)->count());
    }

    public function test_inward_rate_change_keeps_lot_cost_exact_when_partially_dispatched(): void
    {
        // 100 units @ 100, then dispatch 60 -> lot holds 40 @ 100
        $in = $this->makeConfirmedInward(100, 100);
        $lot = Lot::where('lot_number', 'L1')->first();
        $this->makeOutward($lot, 60, 100);
        $lot->refresh();
        $this->assertEquals(40, (float) $lot->available_qty);
        $this->assertEquals(100, (float) $lot->unit_cost);

        // Rate-only edit 100 -> 110 (qty unchanged)
        $this->editInwardRate($in, 110, qty: 100);

        $lot->refresh();
        $this->assertEquals(40, (float) $lot->available_qty);
        $this->assertEquals(110, (float) $lot->unit_cost);   // not 125
        $this->assertEquals(4400, (float) $lot->stock_value); // available * unit_cost
        $this->assertEquals(110, $this->ledger->resolveLotRate($lot));
    }

    public function test_inward_rate_sync_is_idempotent_and_never_duplicates_rows(): void
    {
        $in = $this->makeConfirmedInward(100, 100);
        $lot = Lot::where('lot_number', 'L1')->first();
        $this->makeOutward($lot, 10, 100);

        $inwardRows = InventoryTransaction::where('reference_type', 'INWARD')->where('reference_id', $in->id)->count();
        $outwardRows = InventoryTransaction::where('reference_type', 'OUTWARD')->count();

        $this->editInwardRate($in, 200);
        $this->editInwardRate($in, 200); // same value again
        $this->ledger->syncOutwardRatesFromInward($in->fresh('items'));

        $this->assertEquals($inwardRows, InventoryTransaction::where('reference_type', 'INWARD')->where('reference_id', $in->id)->count());
        $this->assertEquals($outwardRows, InventoryTransaction::where('reference_type', 'OUTWARD')->count());
        $this->assertEquals(0, InventoryTransaction::withTrashed()
            ->whereIn('reference_type', ['INWARD_CANCEL', 'OUTWARD_CANCEL'])
            ->whereIn('reference_id', [$in->id])->count());
    }

    public function test_resolve_lot_rate_prefers_inward_line_over_weighted_lot_cost(): void
    {
        $in = $this->makeInward([['item_id' => $this->item->id, 'lot_number' => 'LX', 'quantity' => 10, 'rate' => 250, 'additional_charges' => 500]]);
        $in->update(['total_amount' => 3000]);
        $in->items()->first()->update(['amount' => 3000]);
        $this->ledger->confirmInward($in->fresh('items'), $this->userId);

        $lot = Lot::where('lot_number', 'LX')->first();
        // Lot cost blends the charges (3000/10 = 300); the booked rate is 250.
        $this->assertEquals(300, (float) $lot->unit_cost);
        $this->assertEquals(250, $this->ledger->resolveLotRate($lot));
    }
}
