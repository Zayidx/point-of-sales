<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receivings', function (Blueprint $table) {
            $table->string('request_key', 120)->nullable()->unique();
            $table->string('payload_hash', 64)->nullable();
        });

        Schema::table('goods_receiving_items', function (Blueprint $table) {
            $table->decimal('qty_sent', 18, 4)->default(0);
            $table->decimal('qty_accepted', 18, 4)->default(0);
            $table->string('qc_status', 20)->default('good');
            $table->text('condition_notes')->nullable();
            $table->string('proof_path')->nullable();
        });

        DB::table('goods_receiving_items')->update([
            'qty_sent' => DB::raw('qty_received'),
            'qty_accepted' => DB::raw('qty_received'),
        ]);
    }

    public function down(): void
    {
        if (DB::table('goods_receivings')->whereNotNull('request_key')->exists()) {
            throw new RuntimeException('Idempotent goods receipt records exist and must be migrated before rollback.');
        }

        Schema::table('goods_receiving_items', function (Blueprint $table) {
            $table->dropColumn(['qty_sent', 'qty_accepted', 'qc_status', 'condition_notes', 'proof_path']);
        });

        Schema::table('goods_receivings', function (Blueprint $table) {
            $table->dropUnique(['request_key']);
            $table->dropColumn(['request_key', 'payload_hash']);
        });
    }
};
