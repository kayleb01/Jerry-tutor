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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payer_id')->constrained('users');
            $table->foreignId('plan_id')->constrained();
            $table->string('gateway');
            $table->string('gateway_customer_code')->nullable();
            $table->string('gateway_subscription_code')->nullable()->index();
            $table->text('gateway_email_token')->nullable();
            $table->text('gateway_authorization')->nullable();
            $table->string('card_brand')->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->string('status')->index();
            $table->dateTime('current_period_ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();
            $table->index(['gateway', 'gateway_customer_code']);
        });

        Schema::create('subscription_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->index()->constrained('users');
            $table->timestamps();
            $table->unique(['subscription_id', 'student_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_student');
        Schema::dropIfExists('subscriptions');
    }
};
