<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('units')) {
            Schema::create('units', function (Blueprint $table) {
                $table->id();
                $table->string('code', 20)->unique();
                $table->string('name', 100);
                $table->string('status', 20)->default('ACTIVE');
                $table->timestamps();
            });

            DB::table('units')->insert([
                ['code' => 'MT', 'name' => 'Metric Tonne', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
                ['code' => 'KG', 'name' => 'Kilogram', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
                ['code' => 'BAG', 'name' => 'Bags', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
                ['code' => 'TON', 'name' => 'Tons', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        if (!Schema::hasTable('item_categories')) {
            Schema::create('item_categories', function (Blueprint $table) {
                $table->id();
                $table->string('code', 30)->unique();
                $table->string('name', 150);
                $table->string('status', 20)->default('ACTIVE');
                $table->timestamps();
            });

            DB::table('item_categories')->insert([
                ['code' => 'AGRO', 'name' => 'Agro Commodities', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
                ['code' => 'PACK', 'name' => 'Packaging Materials', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        if (!Schema::hasTable('items')) {
            Schema::create('items', function (Blueprint $table) {
                $table->id();
                $table->string('code', 50)->unique();
                $table->string('name', 200);
                $table->unsignedBigInteger('category_id')->nullable();
                $table->unsignedBigInteger('unit_id')->nullable();
                $table->decimal('min_stock_level', 15, 3)->default(0.000);
                $table->decimal('max_stock_level', 15, 3)->default(0.000);
                $table->string('status', 20)->default('ACTIVE');
                $table->timestamps();
            });

            // Populate items from products table if available
            if (Schema::hasTable('products')) {
                $products = DB::table('products')->get();
                $unitMt = DB::table('units')->where('code', 'MT')->value('id');
                $catAgro = DB::table('item_categories')->where('code', 'AGRO')->value('id');

                foreach ($products as $p) {
                    DB::table('items')->updateOrInsert(
                        ['code' => $p->product_code ?? ('ITEM-'.$p->id)],
                        [
                            'name' => $p->product_name ?? 'Product '.$p->id,
                            'category_id' => $catAgro,
                            'unit_id' => $unitMt,
                            'min_stock_level' => 10.000,
                            'max_stock_level' => 1000.000,
                            'status' => strtoupper($p->status ?? 'ACTIVE'),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            }

            // Ensure at least default agro items exist
            $defaultItems = [
                ['code' => 'RBRAN-01', 'name' => 'Rice Bran Grade I', 'min_stock_level' => 5.000],
                ['code' => 'RBRAN-02', 'name' => 'Rice Bran Grade II (Raw)', 'min_stock_level' => 5.000],
                ['code' => 'BOILED-BRAN', 'name' => 'Boiled Bran', 'min_stock_level' => 5.000],
                ['code' => 'DOC-01', 'name' => 'De-Oiled Cake (DOC)', 'min_stock_level' => 10.000],
                ['code' => 'HUSK-01', 'name' => 'Rice Husk', 'min_stock_level' => 10.000],
                ['code' => 'PADDY-01', 'name' => 'Paddy Grain', 'min_stock_level' => 15.000],
                ['code' => 'JUTE-BAGS', 'name' => 'Jute Bags (50kg)', 'min_stock_level' => 100.000],
                ['code' => 'PP-BAGS', 'name' => 'PP Bags (50kg)', 'min_stock_level' => 100.000],
            ];

            $unitMt = DB::table('units')->where('code', 'MT')->value('id');
            $catAgro = DB::table('item_categories')->where('code', 'AGRO')->value('id');

            foreach ($defaultItems as $item) {
                DB::table('items')->updateOrInsert(
                    ['code' => $item['code']],
                    [
                        'name' => $item['name'],
                        'category_id' => $catAgro,
                        'unit_id' => $unitMt,
                        'min_stock_level' => $item['min_stock_level'],
                        'max_stock_level' => 5000.000,
                        'status' => 'ACTIVE',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
        Schema::dropIfExists('item_categories');
        Schema::dropIfExists('units');
    }
};
