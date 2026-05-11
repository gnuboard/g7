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
        Schema::create('gnuboard7_hello_module_memos', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('메모 고유번호');
            $table->string('uuid', 36)->unique()->comment('메모 UUID (외부 식별자)');
            $table->string('title', 255)->comment('메모 제목');
            $table->text('content')->comment('메모 본문');
            $table->timestamps();

            $table->index('created_at', 'gnuboard7_hello_module_memos_created_at_index');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('gnuboard7_hello_module_memos', function (Blueprint $table) {
                $table->comment('Hello 모듈 메모 (학습용 샘플)');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('gnuboard7_hello_module_memos')) {
            Schema::dropIfExists('gnuboard7_hello_module_memos');
        }
    }
};
