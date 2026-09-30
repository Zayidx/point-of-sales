<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignId('ingredient_id')->nullable()->after('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->change();
            $table->decimal('qty_ordered', 18, 4)->default(0)->change();
            $table->decimal('qty_received', 18, 4)->default(0)->change();
        });

        Schema::table('goods_receiving_items', function (Blueprint $table) {
            $table->foreignId('ingredient_id')->nullable()->after('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->change();
            $table->decimal('qty_received', 18, 4)->default(0)->change();
        });
    }

    public function down(): void
    {
        if (DB::table('purchase_order_items')->whereNotNull('ingredient_id')->exists()
            || DB::table('goods_receiving_items')->whereNotNull('ingredient_id')->exists()) {
            throw new RuntimeException('Ingredient purchasing records exist and must be migrated before rolling back this migration.');
        }

        Schema::table('goods_receiving_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ingredient_id');
            $table->foreignId('product_id')->nullable(false)->change();
            $table->integer('qty_received')->default(0)->change();
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ingredient_id');
            $table->foreignId('product_id')->nullable(false)->change();
            $table->integer('qty_ordered')->default(0)->change();
            $table->integer('qty_received')->default(0)->change();
        });
    }
};
