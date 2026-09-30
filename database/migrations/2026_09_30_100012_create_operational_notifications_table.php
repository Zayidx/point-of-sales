<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 80);
            $table->string('idempotency_key', 190)->unique();
            $table->string('title', 160);
            $table->text('body');
            $table->string('url', 500)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at', 'created_at']);
            $table->index(['warehouse_id', 'event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_notifications');
    }
};
