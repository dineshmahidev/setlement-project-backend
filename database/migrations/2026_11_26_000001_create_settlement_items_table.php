<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add settlement_type column to settlements table
        if (!Schema::hasColumn('settlements', 'settlement_type')) {
            Schema::table('settlements', function (Blueprint $table) {
                $table->string('settlement_type', 20)->default('single')->after('settlement_number'); // 'single' or 'multiple'
            });
        }

        // 2. Create settlement_items table for multi-product settlements
        if (!Schema::hasTable('settlement_items')) {
            Schema::create('settlement_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('settlement_id')->constrained('settlements')->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->string('lot_number', 50)->nullable();
                
                // Weights
                $table->decimal('net_weight_mt', 15, 3)->default(0.000);
                $table->decimal('jute_bag', 15, 3)->default(0.000);
                $table->decimal('pp_bag', 15, 3)->default(0.000);
                $table->decimal('final_weight_mt', 15, 3)->default(0.000);
                $table->decimal('final_weight_kg', 15, 3)->default(0.000);
                
                // Quality parameters
                $table->decimal('oil_content', 8, 3)->default(0.000);
                $table->string('ffa', 50)->nullable()->default('PASS');
                $table->string('ss', 50)->nullable()->default('PASS');
                $table->string('fibre', 50)->nullable()->default('PASS');
                $table->string('moisture', 50)->nullable()->default('PASS');
                
                // Rates & Bran Value
                $table->decimal('contract_unit_rate', 15, 2)->default(0.00);
                $table->decimal('final_unit_rate', 15, 2)->default(0.00);
                $table->decimal('oil_quantity_kg', 15, 3)->default(0.000);
                $table->decimal('bran_value', 15, 2)->default(0.00);
                
                // Charges specific to item
                $table->decimal('bag_amount', 15, 2)->default(0.00);
                $table->text('remarks')->nullable();
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                
                $table->index('settlement_id');
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_items');

        if (Schema::hasColumn('settlements', 'settlement_type')) {
            Schema::table('settlements', function (Blueprint $table) {
                $table->dropColumn('settlement_type');
            });
        }
    }
};
