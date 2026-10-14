<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_code',
        'branch_name',
        'address',
        'city',
        'district',
        'state',
        'pincode',
        'gstin',
        'phone',
        'phone_2',
        'email',
        'status',
    ];

    protected $appends = ['name', 'code'];

    protected static function booted()
    {
        static::deleting(function (Branch $branch) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($branch) {
                // 1. Company-Godown mapping
                if (\Illuminate\Support\Facades\Schema::hasTable('company_godown')) {
                    \Illuminate\Support\Facades\DB::table('company_godown')->where('branch_id', $branch->id)->delete();
                }

                // 2. Inwards (items + entries)
                if (\Illuminate\Support\Facades\Schema::hasTable('inward_entries')) {
                    $inwardIds = \Illuminate\Support\Facades\DB::table('inward_entries')->where('branch_id', $branch->id)->pluck('id');
                    if ($inwardIds->isNotEmpty() && \Illuminate\Support\Facades\Schema::hasTable('inward_items')) {
                        \Illuminate\Support\Facades\DB::table('inward_items')->whereIn('entry_id', $inwardIds)->delete();
                    }
                    \Illuminate\Support\Facades\DB::table('inward_entries')->where('branch_id', $branch->id)->delete();
                }

                // 3. Outwards (items + entries)
                if (\Illuminate\Support\Facades\Schema::hasTable('outward_entries')) {
                    $outwardIds = \Illuminate\Support\Facades\DB::table('outward_entries')->where('branch_id', $branch->id)->pluck('id');
                    if ($outwardIds->isNotEmpty() && \Illuminate\Support\Facades\Schema::hasTable('outward_items')) {
                        \Illuminate\Support\Facades\DB::table('outward_items')->whereIn('entry_id', $outwardIds)->delete();
                    }
                    \Illuminate\Support\Facades\DB::table('outward_entries')->where('branch_id', $branch->id)->delete();
                }

                // 4. Production / Mixing batches
                if (\Illuminate\Support\Facades\Schema::hasTable('production_batches')) {
                    \Illuminate\Support\Facades\DB::table('production_batches')
                        ->where('source_branch_id', $branch->id)
                        ->orWhere('dest_branch_id', $branch->id)
                        ->delete();
                }

                // 5. Stock transfers & adjustments
                if (\Illuminate\Support\Facades\Schema::hasTable('stock_transfers')) {
                    \Illuminate\Support\Facades\DB::table('stock_transfers')
                        ->where('from_branch_id', $branch->id)
                        ->orWhere('to_branch_id', $branch->id)
                        ->delete();
                }
                if (\Illuminate\Support\Facades\Schema::hasTable('stock_adjustments')) {
                    \Illuminate\Support\Facades\DB::table('stock_adjustments')->where('branch_id', $branch->id)->delete();
                }

                // 6. Lots, Inventory ledger, Vehicle movements
                if (\Illuminate\Support\Facades\Schema::hasTable('lots')) {
                    \Illuminate\Support\Facades\DB::table('lots')->where('branch_id', $branch->id)->delete();
                }
                if (\Illuminate\Support\Facades\Schema::hasTable('inventory_transactions')) {
                    \Illuminate\Support\Facades\DB::table('inventory_transactions')->where('branch_id', $branch->id)->delete();
                }
                if (\Illuminate\Support\Facades\Schema::hasTable('vehicle_movements')) {
                    \Illuminate\Support\Facades\DB::table('vehicle_movements')->where('branch_id', $branch->id)->delete();
                }

                // 7. Users & Sequences
                if (\Illuminate\Support\Facades\Schema::hasTable('user_branches')) {
                    \Illuminate\Support\Facades\DB::table('user_branches')->where('branch_id', $branch->id)->delete();
                }
                if (\Illuminate\Support\Facades\Schema::hasTable('users')) {
                    \Illuminate\Support\Facades\DB::table('users')->where('default_branch_id', $branch->id)->update(['default_branch_id' => null]);
                }
                if (\Illuminate\Support\Facades\Schema::hasTable('document_sequences')) {
                    \Illuminate\Support\Facades\DB::table('document_sequences')->where('branch_id', $branch->id)->delete();
                }

                // 8. Financials: Invoices, Settlements, Debit Notes, Ledgers
                if (\Illuminate\Support\Facades\Schema::hasTable('invoices')) {
                    $invoiceIds = \Illuminate\Support\Facades\DB::table('invoices')->where('branch_id', $branch->id)->pluck('id');
                    if ($invoiceIds->isNotEmpty() && \Illuminate\Support\Facades\Schema::hasTable('invoice_items')) {
                        \Illuminate\Support\Facades\DB::table('invoice_items')->whereIn('invoice_id', $invoiceIds)->delete();
                    }
                    \Illuminate\Support\Facades\DB::table('invoices')->where('branch_id', $branch->id)->delete();
                }

                if (\Illuminate\Support\Facades\Schema::hasTable('settlements')) {
                    $settlementIds = \Illuminate\Support\Facades\DB::table('settlements')->where('branch_id', $branch->id)->pluck('id');
                    if ($settlementIds->isNotEmpty()) {
                        if (\Illuminate\Support\Facades\Schema::hasTable('settlement_items')) {
                            \Illuminate\Support\Facades\DB::table('settlement_items')->whereIn('settlement_id', $settlementIds)->delete();
                        }
                        if (\Illuminate\Support\Facades\Schema::hasTable('settlement_allocations')) {
                            \Illuminate\Support\Facades\DB::table('settlement_allocations')->whereIn('settlement_id', $settlementIds)->delete();
                        }
                        if (\Illuminate\Support\Facades\Schema::hasTable('settlement_advances')) {
                            \Illuminate\Support\Facades\DB::table('settlement_advances')->whereIn('settlement_id', $settlementIds)->delete();
                        }
                        if (\Illuminate\Support\Facades\Schema::hasTable('settlement_adjustments')) {
                            \Illuminate\Support\Facades\DB::table('settlement_adjustments')->whereIn('settlement_id', $settlementIds)->delete();
                        }
                    }
                    \Illuminate\Support\Facades\DB::table('settlements')->where('branch_id', $branch->id)->delete();
                }

                if (\Illuminate\Support\Facades\Schema::hasTable('debit_notes')) {
                    $debitNoteIds = \Illuminate\Support\Facades\DB::table('debit_notes')->where('branch_id', $branch->id)->pluck('id');
                    if ($debitNoteIds->isNotEmpty() && \Illuminate\Support\Facades\Schema::hasTable('debit_note_items')) {
                        \Illuminate\Support\Facades\DB::table('debit_note_items')->whereIn('debit_note_id', $debitNoteIds)->delete();
                    }
                    \Illuminate\Support\Facades\DB::table('debit_notes')->where('branch_id', $branch->id)->delete();
                }

                if (\Illuminate\Support\Facades\Schema::hasTable('vendor_ledger')) {
                    \Illuminate\Support\Facades\DB::table('vendor_ledger')->where('branch_id', $branch->id)->delete();
                }
            });
        });
    }

    public function getNameAttribute(): string
    {
        return $this->attributes['branch_name'] ?? '';
    }

    public function getCodeAttribute(): string
    {
        return $this->attributes['branch_code'] ?? '';
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_branches');
    }

    public function settlements()
    {
        return $this->hasMany(Settlement::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function debitNotes()
    {
        return $this->hasMany(DebitNote::class);
    }

    public function ledgerEntries()
    {
        return $this->hasMany(VendorLedger::class);
    }
}
