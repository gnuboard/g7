<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * personal_access_tokens 에 로그인 세션 상한·로그인 수단 컬럼을 더합니다.
 *
 * - absolute_expires_at : 이 시각 뒤로는 연장하지 않는 절대 만료. 외부 인증 확장이 로그인 문맥 `max_session_minutes` 로
 *                         세션 수명 상한을 주면 그 시각이 들어간다. 사용 중 만료 연장(슬라이드)과 토큰 갱신(refresh)이
 *                         이 시각을 넘지 못한다. NULL 이면 상한 없음(지금까지와 같다).
 * - login_method        : 이 토큰을 연 로그인 수단 이름(`password`, `social:kakao` 등). 운영 정책이 바뀌어 특정 수단의
 *                         토큰만 회수해야 할 때 쓴다. 이 컬럼 이전에 발급된 토큰은 NULL.
 *
 * @since 7.2.0
 */
return new class extends Migration
{
    /**
     * 마이그레이션 실행
     */
    public function up(): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('personal_access_tokens', 'absolute_expires_at')) {
                $table->timestamp('absolute_expires_at')
                    ->nullable()
                    ->after('expires_at')
                    ->comment('이 시각 뒤로는 연장하지 않는 절대 만료 — NULL 이면 상한 없음');
            }

            if (! Schema::hasColumn('personal_access_tokens', 'login_method')) {
                $table->string('login_method', 50)
                    ->nullable()
                    ->after('absolute_expires_at')
                    ->comment('이 토큰을 연 로그인 수단 이름 (password, social:kakao 등) — 이전 발급분은 NULL');
            }
        });
    }

    /**
     * 마이그레이션 롤백
     */
    public function down(): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            if (Schema::hasColumn('personal_access_tokens', 'login_method')) {
                $table->dropColumn('login_method');
            }

            if (Schema::hasColumn('personal_access_tokens', 'absolute_expires_at')) {
                $table->dropColumn('absolute_expires_at');
            }
        });
    }
};
