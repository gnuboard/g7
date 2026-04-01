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
        // 게시판 유형
        Schema::create('board_types', function (Blueprint $table) {
            $table->id()->comment('게시판 유형 ID');
            $table->string('slug', 50)->unique()->comment('유형 식별자 (basic, card, gallery 등)');
            $table->text('name')->comment('유형명 (다국어: {"ko": "기본형", "en": "Basic List"})');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('board_types')) {
            Schema::dropIfExists('board_types');
        }
    }
};
