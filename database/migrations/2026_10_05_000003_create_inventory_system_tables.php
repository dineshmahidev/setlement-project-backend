<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('inventory_transactions')) {
            Schema::create('inventory_transactions', function (Blueprint $table) {
                $table->id();
                $table->string('transaction_number', 50)->unique();
                $table->date('date');
                $table->unsignedBigInteger('branch_id')->index();
                $table->unsignedBigInteger('item_id')->index();
                $table->string('type', 30); // STOCK_IN, STOCK_OUT, TRANSFER_IN, TRANSFER_OUT, ADJUSTMENT_IN, ADJUSTMENT_OUT, OPENING
                $table->decimal('quantity', 15, 3);
                $table->decimal('signed_quantity', 15, 3);
                $table->unsignedBigInteger('unit_id')->nullable();
                $table->unsignedBigInteger('vehicle_id')->nullable();
                $table->string('driver_name', 150)->nullable();
                $table->string('driver_mobile', 30)->nullable();
                $table->unsignedBigInteger('vendor_id')->nullable()->index();
                $table->string('customer', 200)->nullable();
                $table->string('destination', 200)->nullable();
                $table->string('lot_number', 100)->nullable();
                $table->string('reference_type', 50)->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->string('source_reference', 100)->nullable();
                $table->string('status', 20)->default('POSTED');
                $table->text('remarks')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('stock_transfers')) {
            Schema::create('stock_transfers', function (Blueprint $table) {
                $table->id();
                $table->string('transfer_number', 50)->unique();
                $table->date('date');
                $table->unsignedBigInteger('from_branch_id')->index();
                $table->unsignedBigInteger('to_branch_id')->index();
                $table->unsignedBigInteger('vehicle_id')->nullable();
                $table->unsignedBigInteger('item_id')->index();
                $table->decimal('quantity', 15, 3);
                $table->unsignedBigInteger('unit_id')->nullable();
                $table->string('lot_number', 100)->nullable();
                $table->string('driver_name', 150)->nullable();
                $table->string('driver_mobile', 30)->nullable();
                $table->string('status', 20)->default('POSTED');
                $table->text('remarks')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('stock_adjustments')) {
            Schema::create('stock_adjustments', function (Blueprint $table) {
                $table->id();
                $table->string('adjustment_number', 50)->unique();
                $table->date('date');
                $table->unsignedBigInteger('branch_id')->index();
                $table->unsignedBigInteger('item_id')->index();
                $table->string('direction', 10); // IN, OUT
                $table->decimal('quantity', 15, 3);
                $table->unsignedBigInteger('unit_id')->nullable();
                $table->text('reason')->nullable();
                $table->string('status', 20)->default('POSTED');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('vehicle_movements')) {
            Schema::create('vehicle_movements', function (Blueprint $table) {
                $table->id();
                $table->date('date');
                $table->unsignedBigInteger('vehicle_id')->index();
                $table->string('driver_name', 150)->nullable();
                $table->string('driver_mobile', 30)->nullable();
                $table->unsignedBigInteger('branch_id')->index();
                $table->string('transaction_type', 50);
                $table->unsignedBigInteger('vendor_id')->nullable();
                $table->string('customer', 200)->nullable();
                $table->unsignedBigInteger('item_id')->nullable();
                $table->decimal('quantity', 15, 3)->default(0);
                $table->string('lot_number', 100)->nullable();
                $table->string('reference_type', 50)->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->string('reference_number', 100)->nullable();
                $table->text('remarks')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_movements');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('inventory_transactions');
    }
};
