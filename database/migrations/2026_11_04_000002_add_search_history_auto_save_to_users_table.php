<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users 테이블에 최근 검색어 자동 저장 여부 컬럼을 추가합니다.
 *
 * - search_history_auto_save: 회원이 검색창 「자동 저장 끄기」로 끄면 false — 기록을 멈추고 목록도 감춘다(저장된
 *   검색어는 지우지 않는다). 범위별이 아니라 회원 단위 하나다. 기본 켬.
 *
 * @since 7.1.5
 */
return new class extends Migration
{
    /**
     * 마이그레이션 실행
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'search_history_auto_save')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('search_history_auto_save')
                ->default(true)
                ->comment('최근 검색어 자동 저장 여부 (false: 기록 멈춤·목록 감춤)');
        });
    }

    /**
     * 마이그레이션 되돌리기
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'search_history_auto_save')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('search_history_auto_save');
            });
        }
    }
};
