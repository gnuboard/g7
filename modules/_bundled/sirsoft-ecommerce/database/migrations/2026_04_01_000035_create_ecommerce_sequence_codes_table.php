<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ecommerce_sequence_codes', function (Blueprint $table) {
            $table->id()->comment('시퀀스 코드 ID');
            $table->string('type', 50)->comment('시퀀스 타입 (product, order, shipping)');
            $table->string('code', 50)->comment('발급된 코드');
            $table->timestamp('created_at')->useCurrent()->comment('발급 일시');

            $table->unique(['type', 'code'], 'ecommerce_sequence_codes_type_code_unique');
            $table->index('type', 'ecommerce_sequence_codes_type_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `".DB::getTablePrefix()."ecommerce_sequence_codes` COMMENT '채번 코드 정보'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ecommerce_sequence_codes')) {
            Schema::dropIfExists('ecommerce_sequence_codes');
        }
    }
};
