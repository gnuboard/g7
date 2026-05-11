<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * identity_verification_logs.status 컬럼에 'processing' 값을 추가 반영합니다.
 *
 * 컬럼 자체는 string(32) — 새 enum 값 'processing' 을 위한 스키마 변경은 불필요.
 * 단, 컬럼 comment 가 기존 7값만 나열하고 있으므로 운영자 / DBA 가 raw 스키마만 봐도
 * 신규 비동기(Stripe Identity / 토스인증 push 등) 흐름의 의미를 파악할 수 있도록 갱신합니다.
 *
 * 함께 들어가는 변경:
 * - 코어 신규 엔드포인트 GET /api/identity/challenges/{id} (상태 폴링)
 * - 코어 신규 엔드포인트 POST /api/identity/callback/{providerId} (외부 redirect 콜백 수신)
 *
 * @since engine-v1.46.0
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('identity_verification_logs')) {
            return;
        }

        // 기존 인덱스를 유지하면서 column comment 만 갱신.
        // ->index() 호출을 함께 두면 ->change() 가 인덱스 재추가를 시도해 'Duplicate key name' 에러 발생.
        Schema::table('identity_verification_logs', function (Blueprint $table) {
            $table->string('status', 32)
                ->comment('requested|sent|processing|verified|failed|expired|cancelled|policy_violation_logged')
                ->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('identity_verification_logs')) {
            return;
        }

        Schema::table('identity_verification_logs', function (Blueprint $table) {
            $table->string('status', 32)
                ->comment('requested|sent|verified|failed|expired|cancelled|policy_violation_logged')
                ->change();
        });
    }
};
