<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiry_quotes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('inquiry_id')->constrained('inquiries')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->decimal('total_amount', 12, 0);
            $table->decimal('tax_amount', 12, 0)->default(0);
            $table->string('currency', 3)->default('KRW');
            $table->date('valid_until')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->unique(['inquiry_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_quotes');
    }
};
