<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('content');
            $table->string('category', 50)->nullable();
            $table->string('budget_range', 100)->nullable();
            $table->date('desired_due_at')->nullable();
            $table->string('status', 20)->default('received')->index();
            $table->unsignedBigInteger('accepted_quote_id')->nullable();
            $table->string('payment_id', 64)->nullable();
            $table->json('extra_data')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('quoted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
