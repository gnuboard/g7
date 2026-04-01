<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 사용자 테이블에 UUID 컬럼을 추가합니다.
     *
     * 외부 API 응답에서 정수 ID 노출을 방지하기 위해
     * UUID v7 기반 고유 식별자를 추가합니다.
     *
     * - 신규 설치: User 모델 boot() creating 이벤트에서 UUID 자동 생성
     * - 버전 업그레이드: 업그레이드 스크립트에서 기존 레코드 백필 + NOT NULL 변환
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id')->unique()->comment('외부 노출용 UUID v7');
        });
    }

    /**
     * UUID 컬럼을 제거합니다.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
