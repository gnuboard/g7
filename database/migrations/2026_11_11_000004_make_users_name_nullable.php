<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * users.name 을 NULL 허용으로 바꿉니다.
 *
 * - name : 이름. NULL 이면 이름을 받지 않은 회원이다 — 운영자가 환경설정 > 회원에서 「이름」 항목의 사용을 껐거나
 *          선택으로 돌린 사이트에서 가입한 회원. 화면에 보이는 이름은 표시 이름 계산이 닉네임 등으로 대신한다.
 *
 * 되돌리기(down)는 NULL 행이 하나라도 있으면 중단한다 — 빈 이름을 임의 값으로 채우지 않는다.
 *
 * @since 7.2.0
 */
return new class extends Migration
{
    /**
     * 마이그레이션 실행
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'name')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('name')
                ->nullable()
                ->comment('이름 — NULL 이면 이름을 받지 않은 회원(이름 항목을 끄거나 선택으로 둔 사이트)')
                ->change();
        });
    }

    /**
     * 마이그레이션 롤백
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'name')) {
            return;
        }

        $nameless = DB::table('users')->whereNull('name')->count();

        if ($nameless > 0) {
            throw new RuntimeException(
                "이름이 없는 회원 {$nameless}명이 있어 users.name 을 NOT NULL 로 되돌릴 수 없습니다. 그 회원들의 이름을 채운 뒤 다시 실행하세요."
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('name')
                ->nullable(false)
                ->comment('이름')
                ->change();
        });
    }
};
