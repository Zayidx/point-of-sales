<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_shift_opening_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_shift_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 20);
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('closing_quantity', 18, 4)->nullable();
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->timestamps();
            $table->unique(['cashier_shift_id', 'item_type', 'product_id', 'ingredient_id'], 'shift_opening_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_shift_opening_items');
    }
};
