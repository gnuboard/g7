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
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable()->comment('상위 권한 ID (계층 구조)');
            $table->string('identifier')->unique()->comment('권한명 (예: users.create, menus.read)');
            $table->text('name')->comment('권한 이름 (다국어 JSON)');
            $table->mediumText('description')->nullable()->comment('권한 설명 (다국어 JSON)');
            $table->string('extension_type', 20)->nullable()->comment('확장 소유 타입: core(코어), module(모듈), plugin(플러그인), NULL(사용자 정의)');
            $table->string('extension_identifier', 255)->nullable()->comment('확장 식별자 (예: core, sirsoft-board, sirsoft-payment)');
            $table->string('type', 20)->comment('권한 타입');
            $table->string('resource_route_key', 50)->nullable()->comment('리소스 라우트 파라미터명 (예: user, menu, product)');
            $table->string('owner_key', 50)->nullable()->comment('소유자 식별 컬럼명 (예: id, created_by, user_id)');
            $table->integer('order')->default(0)->comment('정렬 순서');
            $table->timestamps();

            $table->index('parent_id');
            $table->index('type');
            $table->index(['extension_type', 'extension_identifier'], 'idx_permissions_extension');
            $table->foreign('parent_id')->references('id')->on('permissions')->cascadeOnDelete();
        });

        if (DB::getDriverName() == 'mysql') {
            Schema::table('permissions', function (Blueprint $table) {
                $table->comment('시스템 권한 정보');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
