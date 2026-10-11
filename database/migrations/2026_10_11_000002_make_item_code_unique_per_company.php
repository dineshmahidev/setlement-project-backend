<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Item codes must be unique per company, not globally (multi-company).
        // Idempotent: skip if the composite index already exists.
        $hasComposite = $this->indexExists('items', 'items_company_code_unique');
        if (! $hasComposite) {
            // Drop the old global unique on code if present.
            if ($this->indexExists('items', 'items_code_unique')) {
                Schema::table('items', function (Blueprint $table) {
                    $table->dropUnique('items_code_unique');
                });
            }
            Schema::table('items', function (Blueprint $table) {
                $table->unique(['company_id', 'code'], 'items_company_code_unique');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('items', 'items_company_code_unique')) {
            Schema::table('items', function (Blueprint $table) {
                $table->dropUnique('items_company_code_unique');
            });
            Schema::table('items', function (Blueprint $table) {
                $table->unique('code', 'items_code_unique');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list(`{$table}`)");
            foreach ($rows as $row) {
                if (strtolower((string) $row->name) === strtolower($index)) {
                    return true;
                }
            }
            return false;
        }
        // mysql / others
        $rows = DB::select(
            'SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $index]
        );
        return (int) ($rows[0]->c ?? 0) > 0;
    }
};
