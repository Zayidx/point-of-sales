<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_pickups', function (Blueprint $table) {
            $table->string('proof_path')->nullable()->after('notes');
            $table->string('proof_hash', 64)->nullable()->after('proof_path');
        });
    }

    public function down(): void
    {
        Schema::table('cash_pickups', function (Blueprint $table) {
            $table->dropColumn(['proof_path', 'proof_hash']);
        });
    }
};
