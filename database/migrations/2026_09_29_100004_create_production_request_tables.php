<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number', 80)->unique();
            $table->string('request_key', 120)->unique();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipe_version_id')->constrained()->restrictOnDelete();
            $table->decimal('target_output', 15, 4);
            $table->decimal('estimated_material_cost', 18, 2)->default(0);
            $table->string('status', 30)->default('requested');
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['warehouse_id', 'status', 'created_at']);
        });

        Schema::create('production_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('required_quantity', 15, 4);
            $table->decimal('available_quantity', 15, 4)->default(0);
            $table->decimal('shortage_quantity', 15, 4)->default(0);
            $table->decimal('unit_cost_snapshot', 15, 2)->default(0);
            $table->decimal('estimated_cost', 18, 2)->default(0);
            $table->timestamps();
            $table->unique(['production_request_id', 'ingredient_id'], 'prod_req_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_request_items');
        Schema::dropIfExists('production_requests');
    }
};
