<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_versions', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('product_id')->constrained('units')->nullOnDelete();
            $table->index(['product_id', 'unit_id', 'version_number'], 'recipe_versions_product_unit_version_index');
        });
    }

    public function down(): void
    {
        Schema::table('recipe_versions', function (Blueprint $table) {
            $table->dropIndex('recipe_versions_product_unit_version_index');
            $table->dropConstrainedForeignId('unit_id');
        });
    }
};
