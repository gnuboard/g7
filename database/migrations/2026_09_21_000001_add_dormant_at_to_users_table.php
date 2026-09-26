<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users 테이블에 휴면 전환 시각(dormant_at)을 추가하고 status 컬럼 주석을 갱신합니다.
 *
 * - dormant_at          : 휴면으로 전환된 시각. `dormant_withdraw_days` 경과 판정의 기준점이며,
 *                         해제 시 NULL 로 되돌린다(전환 이력은 활동 로그가 갖는다).
 * - dormant_notified_at : **현재 단계의** 사전 통지를 보낸 시각. 단계가 바뀔 때마다 NULL 로
 *                         되돌려 재사용한다(활성 단계=휴면 전환 예고 / 휴면 단계=탈퇴 예고).
 *                         이 컬럼이 없으면 사전 통지가 매일 다시 발송되고, 반대로 통지 없이
 *                         전환하는 것도 막을 수 없다 — 전환·탈퇴 조건이 "통지 후 N일 경과" 를
 *                         이 값으로 판정하므로 법정 사전 안내가 구조적으로 강제된다.
 *
 * status 주석은 Enum 실제 값과 어긋나 있었다 — `pending_verification` 이 이미 쓰이는데
 * 주석에 없었고, 이번에 `dormant` 가 더해진다. DB 를 직접 들여다보는 운영자에게 주석이
 * 유일한 어휘 설명이라 실제 값과 맞춘다.
 *
 * `->index()` 를 다시 부르지 않는 이유: `->change()` 가 인덱스 재생성을 시도해
 * 'Duplicate key name' 으로 실패한다(선례: identity_verification_logs status 주석 갱신).
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
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'dormant_at')) {
                $table->timestamp('dormant_at')
                    ->nullable()
                    ->after('withdrawn_at')
                    ->comment('휴면 전환 일시 (해제 시 NULL)');
            }

            if (! Schema::hasColumn('users', 'dormant_notified_at')) {
                $table->timestamp('dormant_notified_at')
                    ->nullable()
                    ->after('dormant_at')
                    ->comment('현재 단계의 휴면 사전 통지 발송 일시 (단계 전환·해제 시 NULL)');
            }
        });

        // status 는 신설이 아니라 기존 컬럼의 주석만 바꾸는 `->change()` 다 — hasColumn 가드는
        // "컬럼이 있을 때만 바꾼다" 는 항상 참인 조건이라 뜻이 없고, MODIFY COLUMN 은 몇 번
        // 실행해도 같은 결과다. 체인을 한 줄로 두는 것은 선례(seo_cache_stats.url)와 같다.
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->comment('계정 상태 (active: 활성, inactive: 비활성, blocked: 차단, withdrawn: 탈퇴, pending_verification: 본인인증 대기, dormant: 휴면)')->change();
        });
    }

    /**
     * 마이그레이션 되돌리기
     */
    public function down(): void
    {
        // 기존 컬럼 주석 복원 — up() 과 같은 이유로 가드를 두지 않는다.
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->comment('계정 상태 (active: 활성, inactive: 비활성, blocked: 차단, withdrawn: 탈퇴)')->change();
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'dormant_notified_at')) {
                $table->dropColumn('dormant_notified_at');
            }

            if (Schema::hasColumn('users', 'dormant_at')) {
                $table->dropColumn('dormant_at');
            }
        });
    }
};
