<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlet_warehouse_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['outlet_id', 'warehouse_id']);
            $table->index(['warehouse_id', 'outlet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_warehouse_access');
    }
};
