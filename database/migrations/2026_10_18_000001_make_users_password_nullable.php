<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * users.password 를 NULL 허용으로 바꿉니다.
 *
 * - password : 비밀번호 해시. NULL 이면 비밀번호 로그인이 불가능한 회원이다 — 소셜 로그인 같은 외부 인증으로만
 *              가입해 아직 비밀번호를 정하지 않았다. 비밀번호 로그인은 프레임워크가 NULL 해시를 먼저 실패로
 *              처리하므로 반드시 실패하고, 마이페이지 비밀번호 변경·비밀번호 재설정 메일로 처음 설정할 수 있다.
 *
 * 되돌리기(down)는 NULL 행이 하나라도 있으면 중단한다 — 그 회원들의 로그인 수단을 지우게 된다.
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
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'password')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')
                ->nullable()
                ->comment('비밀번호 해시 — NULL 이면 비밀번호 로그인 불가(외부 인증 전용 회원)')
                ->change();
        });
    }

    /**
     * 마이그레이션 롤백
     */
    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'password')) {
            return;
        }

        $passwordless = DB::table('users')->whereNull('password')->count();

        if ($passwordless > 0) {
            throw new RuntimeException(
                "비밀번호가 없는 회원 {$passwordless}명이 있어 users.password 를 NOT NULL 로 되돌릴 수 없습니다. 그 회원들이 비밀번호를 설정한 뒤 다시 실행하세요."
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')
                ->nullable(false)
                ->comment('비밀번호 해시')
                ->change();
        });
    }
};
