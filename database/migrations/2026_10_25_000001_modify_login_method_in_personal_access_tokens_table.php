<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * personal_access_tokens.login_method 를 64자로 넓힙니다.
 *
 * 로그인 문맥의 수단 이름은 64자까지 받는데(`AuthService` 로그인 문맥 정규화) 이 컬럼이 50자라, 51자 이상인 수단 이름
 * (예: 외부 인증 확장의 `sso:{공급자 키}` — 키가 길면 54자)은 토큰에 잘려 저장됐다. 그러면 로그인 완료 훅이 받은 수단
 * 이름과 토큰의 수단 이름이 달라져, 특정 수단의 토큰만 회수하는 정리가 그 토큰을 놓친다.
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
        if (! Schema::hasTable('personal_access_tokens') || ! Schema::hasColumn('personal_access_tokens', 'login_method')) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('login_method', 64)
                ->nullable()
                ->comment('이 토큰을 연 로그인 수단 이름 (password, social:kakao, sso:{키} 등, 64자) — 이전 발급분은 NULL')
                ->change();
        });
    }

    /**
     * 마이그레이션 롤백
     */
    public function down(): void
    {
        if (! Schema::hasTable('personal_access_tokens') || ! Schema::hasColumn('personal_access_tokens', 'login_method')) {
            return;
        }

        // 50자를 넘는 값이 있으면 좁힐 때 잘림 오류가 나므로 먼저 50자로 맞춘다(되돌리기 전 상태와 같은 자르기)
        DB::table('personal_access_tokens')
            ->whereNotNull('login_method')
            ->whereRaw('CHAR_LENGTH(login_method) > 50')
            ->update(['login_method' => DB::raw('LEFT(login_method, 50)')]);

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('login_method', 50)
                ->nullable()
                ->comment('이 토큰을 연 로그인 수단 이름 (password, social:kakao 등) — 이전 발급분은 NULL')
                ->change();
        });
    }
};
