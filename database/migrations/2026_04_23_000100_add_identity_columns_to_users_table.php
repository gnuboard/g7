<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users 테이블에 본인인증(IdentityVerification) 관련 컬럼을 추가합니다.
 *
 * - identity_verified_at           : 최종 성공 본인인증 시각
 * - identity_verified_provider     : 감사용 프로바이더 식별자
 * - identity_verified_purpose_last : 최근 민감 작업 재인증 경과시간 UX
 * - identity_hash                  : 프로바이더 교체 시 동일인 매칭 (SHA256 정규화 식별자)
 * - mobile_verified_at             : email_verified_at 과 대칭, SMS 프로바이더 대비
 *
 * @since 7.0.0-beta.4
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('identity_verified_at')
                ->nullable()
                ->after('email_verified_at')
                ->comment('최종 성공 본인인증 시각');

            $table->string('identity_verified_provider', 64)
                ->nullable()
                ->after('identity_verified_at')
                ->comment('최근 본인인증에 사용한 프로바이더 식별자');

            $table->string('identity_verified_purpose_last', 32)
                ->nullable()
                ->after('identity_verified_provider')
                ->comment('최근 본인인증 목적 (signup|password_reset|self_update|sensitive_action|...)');

            $table->char('identity_hash', 64)
                ->nullable()
                ->after('identity_verified_purpose_last')
                ->comment('프로바이더 교체 시 동일인 매칭용 정규화 식별자 (SHA256)');

            $table->timestamp('mobile_verified_at')
                ->nullable()
                ->after('identity_hash')
                ->comment('휴대전화 인증 시각 (email_verified_at 과 대칭)');

            $table->index('identity_hash', 'idx_users_identity_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_identity_hash');
            $table->dropColumn([
                'identity_verified_at',
                'identity_verified_provider',
                'identity_verified_purpose_last',
                'identity_hash',
                'mobile_verified_at',
            ]);
        });
    }
};
