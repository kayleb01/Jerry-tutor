<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payer_id')->constrained('users');
            $table->string('gateway');
            $table->string('reference')->unique();
            $table->string('gateway_transaction_id')->nullable();
            $table->unsignedInteger('amount');
            $table->string('currency', 3);
            $table->string('status');
            $table->string('failure_reason')->nullable();
            $table->string('card_brand')->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->json('gateway_response')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
