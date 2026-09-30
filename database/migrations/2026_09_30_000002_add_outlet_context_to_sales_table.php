<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['cashier_shifts', 'transactions'] as $tableName) {
            if (! Schema::hasColumn($tableName, 'outlet_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->foreignId('outlet_id')->nullable()->after('warehouse_id')->constrained()->nullOnDelete();
                    $table->index(['outlet_id', 'created_at']);
                });
            }
        }

        $centralOutletId = DB::table('outlets')->where('code', 'PUSAT')->value('id');
        foreach (DB::table('warehouses')->whereNotNull('outlet_id')->get(['id', 'outlet_id']) as $warehouse) {
            if ((int) $warehouse->outlet_id === (int) $centralOutletId) {
                continue;
            }

            foreach (['cashier_shifts', 'transactions'] as $tableName) {
                DB::table($tableName)
                    ->where('warehouse_id', $warehouse->id)
                    ->whereNull('outlet_id')
                    ->update(['outlet_id' => $warehouse->outlet_id]);
            }
        }
    }

    public function down(): void
    {
        foreach (['cashier_shifts', 'transactions'] as $tableName) {
            if (Schema::hasColumn($tableName, 'outlet_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropIndex(['outlet_id', 'created_at']);
                    $table->dropConstrainedForeignId('outlet_id');
                });
            }
        }
    }
};
