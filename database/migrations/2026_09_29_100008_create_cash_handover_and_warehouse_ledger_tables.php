<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_handovers', function (Blueprint $table) {
            $table->id();
            $table->string('handover_number', 80)->unique();
            $table->string('request_key', 120)->unique();
            $table->foreignId('cashier_shift_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('expected_cash');
            $table->unsignedBigInteger('cashier_amount');
            $table->unsignedBigInteger('received_amount')->nullable();
            $table->bigInteger('variance')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('cashier_notes')->nullable();
            $table->text('warehouse_notes')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['warehouse_id', 'status', 'created_at']);
        });

        Schema::create('cash_pickups', function (Blueprint $table) {
            $table->id();
            $table->string('pickup_number', 80)->unique();
            $table->string('request_key', 120)->unique();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('status', 20)->default('requested');
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['warehouse_id', 'status', 'created_at']);
        });

        Schema::create('warehouse_cash_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('reference_number', 80)->unique();
            $table->string('idempotency_key', 120)->unique();
            $table->string('movement_type', 40);
            $table->string('direction', 3);
            $table->unsignedBigInteger('amount');
            $table->string('reference_type', 100);
            $table->unsignedBigInteger('reference_id');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['warehouse_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_cash_ledger');
        Schema::dropIfExists('cash_pickups');
        Schema::dropIfExists('cash_handovers');
    }
};
