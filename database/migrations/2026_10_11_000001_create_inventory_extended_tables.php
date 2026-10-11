<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---------- 1. Material types + oil fields on items ----------
        if (!Schema::hasColumn('items', 'material_type')) {
            Schema::table('items', function (Blueprint $table) {
                $table->string('material_type', 50)->nullable()->after('name'); // Raw, Boiled, Silky, Brown, custom
                $table->decimal('default_oil_pct', 5, 2)->nullable()->after('material_type');
                $table->text('description')->nullable()->after('default_oil_pct');
            });
        }

        // ---------- 2. Grades (configurable, oil%-range driven, not hardcoded) ----------
        if (!Schema::hasTable('grades')) {
            Schema::create('grades', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable()->index(); // null = global template
                $table->string('code', 30);
                $table->string('name', 100);
                $table->string('material_type', 50)->nullable();
                $table->decimal('min_oil_pct', 5, 2)->nullable();
                $table->decimal('max_oil_pct', 5, 2)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('status', 20)->default('ACTIVE');
                $table->timestamps();
                $table->unique(['company_id', 'code']);
            });
        }

        // ---------- 3. Company <-> Godown (shared physical godown support) ----------
        if (!Schema::hasTable('company_godown')) {
            Schema::create('company_godown', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->index(); // godown
                $table->timestamps();
                $table->unique(['company_id', 'branch_id']);
            });
        }

        // ---------- 4. Enrich inventory ledger with company / oil / grade / party / value ----------
        if (Schema::hasTable('inventory_transactions')) {
            Schema::table('inventory_transactions', function (Blueprint $table) {
                if (!Schema::hasColumn('inventory_transactions', 'company_id')) {
                    $table->unsignedBigInteger('company_id')->nullable()->index()->after('branch_id');
                }
                if (!Schema::hasColumn('inventory_transactions', 'oil_pct')) {
                    $table->decimal('oil_pct', 5, 2)->nullable()->after('lot_number');
                }
                if (!Schema::hasColumn('inventory_transactions', 'grade_id')) {
                    $table->unsignedBigInteger('grade_id')->nullable()->index()->after('oil_pct');
                }
                if (!Schema::hasColumn('inventory_transactions', 'party_id')) {
                    $table->unsignedBigInteger('party_id')->nullable()->index()->after('vendor_id');
                }
                if (!Schema::hasColumn('inventory_transactions', 'party_type')) {
                    $table->string('party_type', 20)->nullable()->after('party_id'); // supplier / customer
                }
                if (!Schema::hasColumn('inventory_transactions', 'rate')) {
                    $table->decimal('rate', 15, 4)->nullable()->after('quantity');
                }
                if (!Schema::hasColumn('inventory_transactions', 'amount')) {
                    $table->decimal('amount', 18, 2)->nullable()->after('rate');
                }
            });

            // Backfill company_id from the owning branch so historical rows stay isolated correctly.
            // MySQL supports UPDATE...JOIN; SQLite (tests) uses a portable subquery instead.
            if (DB::getDriverName() === 'mysql') {
                DB::statement('
                    UPDATE inventory_transactions it
                    JOIN branches b ON b.id = it.branch_id
                    SET it.company_id = b.company_id
                    WHERE it.company_id IS NULL
                ');
            } else {
                DB::table('inventory_transactions')
                    ->whereNull('company_id')
                    ->whereIn('branch_id', DB::table('branches')->select('id'))
                    ->update(['company_id' => DB::raw('(SELECT b.company_id FROM branches b WHERE b.id = inventory_transactions.branch_id)')]);
            }
        }

        // ---------- 5. Parties: supplier / customer / both ----------
        if (Schema::hasTable('vendors')) {
            if (!Schema::hasColumn('vendors', 'party_type')) {
                Schema::table('vendors', function (Blueprint $table) {
                    $table->string('party_type', 20)->default('both')->after('vendor_name'); // supplier, customer, both
                });
            }
            if (!Schema::hasColumn('vendors', 'company_id')) {
                Schema::table('vendors', function (Blueprint $table) {
                    $table->unsignedBigInteger('company_id')->nullable()->index()->after('party_type');
                });
            }
        }

        // ---------- 6. Lots: real lot-wise stock identity ----------
        if (!Schema::hasTable('lots')) {
            Schema::create('lots', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->index(); // godown
                $table->unsignedBigInteger('item_id')->index();
                $table->unsignedBigInteger('grade_id')->nullable()->index();
                $table->string('lot_number', 100)->index();
                $table->decimal('oil_pct', 5, 2)->nullable();
                $table->decimal('opening_qty', 15, 3)->default(0);
                $table->decimal('received_qty', 15, 3)->default(0);
                $table->decimal('issued_qty', 15, 3)->default(0);
                $table->decimal('available_qty', 15, 3)->default(0);
                $table->decimal('reserved_qty', 15, 3)->default(0);
                $table->unsignedBigInteger('unit_id')->nullable();
                $table->decimal('unit_cost', 15, 4)->default(0);
                $table->decimal('stock_value', 18, 2)->default(0);
                $table->string('source', 30)->nullable(); // INWARD, PRODUCTION, OPENING, TRANSFER_IN
                $table->unsignedBigInteger('source_id')->nullable();
                $table->string('status', 20)->default('OPEN'); // OPEN, CLOSED
                $table->timestamps();
                $table->unique(['company_id', 'branch_id', 'item_id', 'lot_number']);
            });
        }

        // ---------- 7. Inward (one load = many items) ----------
        if (!Schema::hasTable('inward_entries')) {
            Schema::create('inward_entries', function (Blueprint $table) {
                $table->id();
                $table->string('entry_number', 50)->unique();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->index(); // receiving godown
                $table->unsignedBigInteger('party_id')->nullable()->index(); // supplier
                $table->date('date');
                $table->string('vehicle_number', 50)->nullable();
                $table->string('driver_name', 150)->nullable();
                $table->string('driver_mobile', 30)->nullable();
                $table->string('load_reference', 100)->nullable();
                $table->decimal('total_amount', 18, 2)->default(0);
                $table->string('status', 20)->default('DRAFT'); // DRAFT, CONFIRMED, CANCELLED
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('confirmed_by')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('inward_items')) {
            Schema::create('inward_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('entry_id')->index();
                $table->unsignedBigInteger('item_id')->index();
                $table->string('lot_number', 100)->nullable();
                $table->decimal('quantity', 15, 3);
                $table->unsignedBigInteger('unit_id')->nullable();
                $table->decimal('oil_pct', 5, 2)->nullable();
                $table->unsignedBigInteger('grade_id')->nullable();
                $table->decimal('rate', 15, 4)->default(0);
                $table->decimal('amount', 18, 2)->default(0);
                $table->decimal('additional_charges', 18, 2)->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->foreign('entry_id')->references('id')->on('inward_entries')->cascadeOnDelete();
            });
        }

        // ---------- 8. Outward / dispatch (direct + mixed) ----------
        if (!Schema::hasTable('outward_entries')) {
            Schema::create('outward_entries', function (Blueprint $table) {
                $table->id();
                $table->string('entry_number', 50)->unique();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('branch_id')->index(); // source godown
                $table->unsignedBigInteger('party_id')->nullable()->index(); // customer
                $table->date('date');
                $table->string('dispatch_type', 20)->default('DIRECT'); // DIRECT, MIXED
                $table->string('vehicle_number', 50)->nullable();
                $table->string('driver_name', 150)->nullable();
                $table->string('driver_mobile', 30)->nullable();
                $table->string('destination', 200)->nullable();
                $table->decimal('total_amount', 18, 2)->default(0);
                $table->boolean('is_sale')->default(false);
                $table->string('payment_status', 20)->nullable();
                $table->string('status', 20)->default('DRAFT'); // DRAFT, CONFIRMED, CANCELLED
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('confirmed_by')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('outward_items')) {
            Schema::create('outward_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('entry_id')->index();
                $table->unsignedBigInteger('item_id')->index();
                $table->unsignedBigInteger('lot_id')->nullable()->index(); // source lot for direct
                $table->decimal('quantity', 15, 3);
                $table->unsignedBigInteger('unit_id')->nullable();
                $table->decimal('oil_pct', 5, 2)->nullable();
                $table->unsignedBigInteger('grade_id')->nullable();
                $table->decimal('rate', 15, 4)->default(0);
                $table->decimal('amount', 18, 2)->default(0);
                $table->decimal('discount', 18, 2)->default(0);
                $table->decimal('additional_charges', 18, 2)->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->foreign('entry_id')->references('id')->on('outward_entries')->cascadeOnDelete();
            });
        }

        // ---------- 9. Mixing / production batches ----------
        if (!Schema::hasTable('production_batches')) {
            Schema::create('production_batches', function (Blueprint $table) {
                $table->id();
                $table->string('batch_number', 50)->unique();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('source_branch_id')->nullable()->index();
                $table->unsignedBigInteger('dest_branch_id')->index();
                $table->date('date');
                $table->unsignedBigInteger('output_item_id')->nullable();
                $table->unsignedBigInteger('output_grade_id')->nullable();
                $table->string('output_lot_number', 100)->nullable();
                $table->decimal('output_oil_pct', 5, 2)->nullable();
                $table->decimal('input_total', 15, 3)->default(0);
                $table->decimal('output_total', 15, 3)->default(0);
                $table->decimal('wastage', 15, 3)->default(0);
                $table->string('status', 20)->default('DRAFT'); // DRAFT, CONFIRMED, CANCELLED
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('confirmed_by')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('production_inputs')) {
            Schema::create('production_inputs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('batch_id')->index();
                $table->unsignedBigInteger('lot_id')->index();
                $table->unsignedBigInteger('item_id')->index();
                $table->decimal('quantity', 15, 3);
                $table->decimal('oil_pct', 5, 2)->nullable();
                $table->timestamps();
                $table->foreign('batch_id')->references('id')->on('production_batches')->cascadeOnDelete();
            });
        }
        if (!Schema::hasTable('production_outputs')) {
            Schema::create('production_outputs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('batch_id')->index();
                $table->unsignedBigInteger('lot_id')->nullable()->index();
                $table->unsignedBigInteger('item_id')->index();
                $table->decimal('quantity', 15, 3);
                $table->decimal('oil_pct', 5, 2)->nullable();
                $table->unsignedBigInteger('grade_id')->nullable();
                $table->timestamps();
                $table->foreign('batch_id')->references('id')->on('production_batches')->cascadeOnDelete();
            });
        }

        // Seed a few default grades per company (sample ranges — configurable by client).
        $this->seedDefaultGrades();
    }

    private function seedDefaultGrades(): void
    {
        if (Schema::hasTable('grades') && DB::table('grades')->count() === 0) {
            $sample = [
                ['code' => 'G-15', 'name' => 'Grade 15', 'min_oil_pct' => 14, 'max_oil_pct' => 16],
                ['code' => 'G-18', 'name' => 'Grade 18', 'min_oil_pct' => 17, 'max_oil_pct' => 18.99],
                ['code' => 'G-19', 'name' => 'Grade 19', 'min_oil_pct' => 19, 'max_oil_pct' => 19.99],
                ['code' => 'G-20', 'name' => 'Grade 20', 'min_oil_pct' => 20, 'max_oil_pct' => 20.99],
                ['code' => 'G-21', 'name' => 'Grade 21', 'min_oil_pct' => 21, 'max_oil_pct' => 21.99],
                ['code' => 'G-25', 'name' => 'Grade 25', 'min_oil_pct' => 24, 'max_oil_pct' => 26],
            ];
            $companyIds = Schema::hasTable('companies') ? DB::table('companies')->pluck('id') : [null];
            foreach ($companyIds as $cid) {
                foreach ($sample as $i => $g) {
                    DB::table('grades')->insert([
                        'company_id' => $cid,
                        'code' => $g['code'],
                        'name' => $g['name'],
                        'min_oil_pct' => $g['min_oil_pct'],
                        'max_oil_pct' => $g['max_oil_pct'],
                        'sort_order' => $i,
                        'status' => 'ACTIVE',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        // Material types default set on items (null-safe)
        if (Schema::hasTable('items')) {
            DB::table('items')->whereNull('material_type')->update(['material_type' => 'Raw']);
        }

        // Link every company to its own branches in company_godown
        if (Schema::hasTable('company_godown') && Schema::hasTable('branches') && Schema::hasTable('companies')) {
            $branches = DB::table('branches')->whereNotNull('company_id')->get(['id', 'company_id']);
            foreach ($branches as $b) {
                $exists = DB::table('company_godown')
                    ->where('company_id', $b->company_id)->where('branch_id', $b->id)->exists();
                if (!$exists) {
                    DB::table('company_godown')->insert([
                        'company_id' => $b->company_id,
                        'branch_id' => $b->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('production_outputs');
        Schema::dropIfExists('production_inputs');
        Schema::dropIfExists('production_batches');
        Schema::dropIfExists('outward_items');
        Schema::dropIfExists('outward_entries');
        Schema::dropIfExists('inward_items');
        Schema::dropIfExists('inward_entries');
        Schema::dropIfExists('lots');
        Schema::dropIfExists('company_godown');
        Schema::dropIfExists('grades');
    }
};
