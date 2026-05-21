<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiry_quote_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('quote_id')->constrained('inquiry_quotes')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->decimal('qty', 10, 2);
            $table->decimal('unit_price', 12, 0);
            $table->decimal('amount', 12, 0);
            $table->timestamps();

            $table->index(['quote_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_quote_items');
    }
};
