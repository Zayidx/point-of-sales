<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->string('balance_key', 100)->unique();
            $table->string('location_type', 20);
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('item_type', 30);
            $table->unsignedBigInteger('item_id');
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('average_unit_cost', 15, 2)->default(0);
            $table->decimal('inventory_value', 18, 2)->default(0);
            $table->timestamps();
            $table->index(['location_type', 'item_type', 'item_id']);
        });

        Schema::create('inventory_ledgers', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number', 80);
            $table->string('idempotency_key', 120)->unique();
            $table->string('location_type', 20);
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('item_type', 30);
            $table->unsignedBigInteger('item_id');
            $table->foreignId('ingredient_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('movement_type', 40);
            $table->decimal('quantity', 18, 4);
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('total_cost', 18, 2)->default(0);
            $table->string('reference_type', 100)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['location_type', 'item_type', 'item_id', 'created_at'], 'inventory_ledger_balance_lookup');
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_ledgers');
        Schema::dropIfExists('inventory_balances');
    }
};
