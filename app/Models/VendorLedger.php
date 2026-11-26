<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class VendorLedger extends Model
{
    use HasFactory;

    protected $table = 'vendor_ledger';

    protected $fillable = [
        'vendor_id',
        'branch_id',
        'transaction_date',
        'document_type',
        'document_id',
        'document_number',
        'lot_number',
        'description',
        'debit',
        'credit',
        'balance',
        'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Post a transaction to the vendor ledger with automatic running balance
     */
    public static function postTransaction(array $data): self
    {
        return DB::transaction(function () use ($data) {
            $vendorId = $data['vendor_id'];
            $vendor = Vendor::findOrFail($vendorId);

            $transactionDate = $data['transaction_date'] ?? now()->toDateString();
            $transactionDate = $transactionDate instanceof \Carbon\CarbonInterface
                ? $transactionDate->toDateString()
                : \Carbon\Carbon::parse($transactionDate)->toDateString();

            // The balance must be derived from the row that will immediately
            // precede this one chronologically - not simply the newest row,
            // which may carry a later date than the entry being posted.
            $previousEntry = self::where('vendor_id', $vendorId)
                ->where('transaction_date', '<=', $transactionDate)
                ->orderBy('transaction_date', 'desc')
                ->orderBy('id', 'desc')
                ->lockForUpdate()
                ->first();

            $previousBalance = $previousEntry ? (float)$previousEntry->balance : (float)$vendor->opening_balance;

            $debit = (float)($data['debit'] ?? 0);
            $credit = (float)($data['credit'] ?? 0);

            // Standard accounting for vendor (payable account):
            // Invoices/Settlement net payables credited (we owe vendor),
            // Debit Notes/Payments debited (reduces vendor liability)
            // Or if debit balance represents amount receivable and credit represents payable:
            // Let's ensure Balance = Previous Balance + Debit - Credit (or + Credit - Debit according to standard convention)
            // Conventional Vendor Ledger in Indian Agro accounting:
            // Purchase/Invoice -> Credit to vendor account (+ liability)
            // Payment / Debit Note (Rebate) -> Debit to vendor account (- liability)
            // Balance = Previous Balance - Debit + Credit (Payable is positive)
            $newBalance = round($previousBalance - $debit + $credit, 2);

            $entry = self::create([
                'vendor_id' => $vendorId,
                'branch_id' => $data['branch_id'] ?? null,
                'transaction_date' => $transactionDate,
                'document_type' => $data['document_type'],
                'document_id' => $data['document_id'] ?? null,
                'document_number' => $data['document_number'] ?? null,
                'lot_number' => $data['lot_number'] ?? null,
                'description' => $data['description'] ?? null,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $newBalance,
                'created_by' => auth()->id() ?? $data['created_by'] ?? null,
            ]);

            // Anything dated after this entry was computed without it, so carry
            // the new effect forward to keep the running balance consistent.
            self::shiftBalancesAfter($entry, round($credit - $debit, 2));

            return $entry;
        });
    }

    /**
     * Recalculate running balances for a vendor chronologically
     */
    public static function recalculateBalances($vendorId): void
    {
        $vendor = Vendor::find($vendorId);
        if (!$vendor) return;

        $running = (float)$vendor->opening_balance;
        $entries = self::where('vendor_id', $vendorId)
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        foreach ($entries as $entry) {
            $running = $running - (float)$entry->debit + (float)$entry->credit;
            $entry->balance = $running;
            $entry->saveQuietly();
        }
    }

    /**
     * The original posting rows that belong to a debit/credit note.
     * Cancellation/reversal rows use other document_type values and are excluded.
     */
    public static function notePostings(int $noteId)
    {
        return self::where('document_id', $noteId)
            ->whereIn('document_type', ['debit_note', 'credit_note']);
    }

    /**
     * Keep a note's ledger posting in step with the note row itself.
     *
     * - Reuses the existing posting row (never appends a second one).
     * - Fixes direction, amount, document_type and document_number together.
     * - Applies the resulting difference to this row and every later row for
     *   the vendor, so the running balance stays correct without touching
     *   unrelated history.
     * - Collapses accidental duplicate postings for the same note so a note is
     *   only ever counted once.
     */
    public static function syncForNote(DebitNote $note, ?string $description = null): void
    {
        if ($note->status === 'cancelled') {
            // cancel() already posted its own reversal row; do not touch it.
            return;
        }

        $isDebit = ($note->transaction_type === 'debit');
        $amount = round((float) $note->total_amount, 2);

        if ($amount <= 0) {
            // A zero-valued note must not leave a zero posting behind.
            self::removeNoteEntries($note);

            return;
        }

        $newDebit = $isDebit ? $amount : 0.0;
        $newCredit = $isDebit ? 0.0 : $amount;

        $entries = self::notePostings($note->id)->lockForUpdate()->orderBy('id')->get();

        if ($entries->isEmpty()) {
            self::postTransaction([
                'vendor_id' => $note->vendor_id,
                'branch_id' => $note->branch_id,
                'transaction_date' => $note->date ? $note->date->toDateString() : now()->toDateString(),
                'document_type' => $isDebit ? 'debit_note' : 'credit_note',
                'document_id' => $note->id,
                'document_number' => $note->debit_note_number,
                'lot_number' => $note->lot_number,
                'description' => $description ?? self::defaultNoteDescription($note),
                'debit' => $newDebit,
                'credit' => $newCredit,
            ]);

            return;
        }

        $canonical = $entries->first();
        $existingEffect = round($entries->sum(fn ($e) => (float) $e->credit - (float) $e->debit), 2);
        $desiredEffect = round($newCredit - $newDebit, 2);
        $delta = round($desiredEffect - $existingEffect, 2);

        // A note must have exactly one posting; drop any accidental repeats.
        $extraIds = $entries->slice(1)->pluck('id');
        if ($extraIds->isNotEmpty()) {
            self::whereIn('id', $extraIds)->delete();
        }

        $canonical->document_type = $isDebit ? 'debit_note' : 'credit_note';
        $canonical->document_number = $note->debit_note_number;
        $canonical->debit = $newDebit;
        $canonical->credit = $newCredit;
        $canonical->balance = round((float) $canonical->balance + $delta, 2);
        $canonical->save();

        self::shiftBalancesAfter($canonical, $delta);
    }

    /**
     * Remove a note's postings (note became zero / was removed) and repair the
     * running balance of everything that followed, leaving no orphan rows.
     */
    public static function removeNoteEntries(DebitNote $note): void
    {
        $entries = self::notePostings($note->id)->lockForUpdate()->orderBy('id')->get();
        if ($entries->isEmpty()) {
            return;
        }

        $first = $entries->first();
        $effect = round($entries->sum(fn ($e) => (float) $e->credit - (float) $e->debit), 2);

        self::whereIn('id', $entries->pluck('id'))->delete();

        self::shiftBalancesAfter($first, -$effect);
    }

    /**
     * Add $delta to the balance of every row that sorts after $entry for the
     * same vendor. Rows before the change are never rewritten.
     */
    protected static function shiftBalancesAfter(self $entry, float $delta): void
    {
        if (abs($delta) < 0.0001) {
            return;
        }

        $date = $entry->transaction_date
            ? \Carbon\Carbon::parse($entry->transaction_date)->toDateString()
            : now()->toDateString();

        self::where('vendor_id', $entry->vendor_id)
            ->where(function ($q) use ($date, $entry) {
                $q->where('transaction_date', '>', $date)
                    ->orWhere(function ($q2) use ($date, $entry) {
                        $q2->where('transaction_date', $date)->where('id', '>', $entry->id);
                    });
            })
            ->update(['balance' => DB::raw('ROUND(balance + (' . $delta . '), 2)')]);
    }

    protected static function defaultNoteDescription(DebitNote $note): string
    {
        $against = $note->invoice_number
            ? 'Inv #' . $note->invoice_number
            : ($note->settlement_id ? 'Settlement' : 'Note');

        return trim($note->reason . ' against ' . $against);
    }
}
