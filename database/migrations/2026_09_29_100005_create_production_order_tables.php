<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 80)->unique();
            $table->foreignId('production_request_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipe_version_id')->constrained()->restrictOnDelete();
            $table->decimal('planned_output', 15, 4);
            $table->decimal('actual_output', 15, 4)->nullable();
            $table->decimal('actual_material_cost', 18, 2)->default(0);
            $table->decimal('actual_unit_cost', 15, 2)->default(0);
            $table->string('status', 20)->default('planned');
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['warehouse_id', 'status', 'created_at']);
        });

        Schema::create('production_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('planned_quantity', 15, 4);
            $table->decimal('actual_quantity', 15, 4)->nullable();
            $table->decimal('unit_cost_snapshot', 15, 2);
            $table->decimal('actual_cost', 18, 2)->default(0);
            $table->timestamps();
            $table->unique(['production_order_id', 'ingredient_id'], 'prod_order_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_items');
        Schema::dropIfExists('production_orders');
    }
};
