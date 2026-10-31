<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * roles 테이블에 역할 단위 비밀번호 만료 적용 설정을 추가합니다.
 *
 * 우선순위는 회원 개별 > 역할 > 사이트 범위이며, 한 회원의 역할끼리 apply·exempt 가
 * 부딪치면 apply(보안 우선)다. 확장 역할 동기화(`syncRole`)는 이 값을 덮지 않는다 —
 * 운영자가 고른 값이고 확장 선언에는 이 필드가 없다.
 *
 * @since 7.1.0
 */
return new class extends Migration
{
    /**
     * 마이그레이션 실행
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (! Schema::hasColumn('roles', 'password_expiry_policy')) {
                $table->string('password_expiry_policy', 10)
                    ->default('inherit')
                    ->after('is_active')
                    ->comment('비밀번호 만료 (inherit: 사이트 설정 따름, apply: 적용, exempt: 제외)');
            }
        });
    }

    /**
     * 마이그레이션 되돌리기
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (Schema::hasColumn('roles', 'password_expiry_policy')) {
                $table->dropColumn('password_expiry_policy');
            }
        });
    }
};
