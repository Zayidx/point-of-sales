<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlet_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('expense_number', 80)->unique();
            $table->string('request_key', 120)->unique();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('cashier_shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->string('item_name', 160);
            $table->decimal('quantity', 15, 4);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('total');
            $table->string('payment_source', 30);
            $table->text('notes')->nullable();
            $table->string('receipt_path')->nullable();
            $table->foreignId('cash_movement_id')->nullable()->unique()->constrained('shift_cash_movements')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['warehouse_id', 'created_at']);
            $table->index(['cashier_shift_id', 'payment_source']);
        });

        Schema::create('outlet_waste_records', function (Blueprint $table) {
            $table->id();
            $table->string('waste_number', 80)->unique();
            $table->string('request_key', 120)->unique();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('cashier_shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('quantity', 15, 4);
            $table->unsignedBigInteger('unit_cost_snapshot');
            $table->unsignedBigInteger('total_cost');
            $table->string('reason', 30);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['warehouse_id', 'created_at']);
            $table->index(['cashier_shift_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_waste_records');
        Schema::dropIfExists('outlet_expenses');
    }
};
