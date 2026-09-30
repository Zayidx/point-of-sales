<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlet_stock_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_number', 80)->unique();
            $table->string('request_key', 120)->unique();
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->text('notes')->nullable();
            $table->text('receiving_notes')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->index(['source_warehouse_id', 'status', 'created_at']);
            $table->index(['destination_warehouse_id', 'status', 'created_at'], 'outlet_returns_destination_status_idx');
        });

        Schema::create('outlet_stock_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_stock_return_id')->constrained('outlet_stock_returns')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity_requested');
            $table->unsignedInteger('quantity_received')->nullable();
            $table->unsignedBigInteger('unit_cost_snapshot')->nullable();
            $table->timestamps();
            $table->unique(['outlet_stock_return_id', 'product_id'], 'outlet_returns_item_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_stock_return_items');
        Schema::dropIfExists('outlet_stock_returns');
    }
};
