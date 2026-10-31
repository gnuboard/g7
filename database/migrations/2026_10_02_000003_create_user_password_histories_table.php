<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 회원 이전 비밀번호 해시 이력 테이블을 만듭니다 (재사용 금지 판정).
 *
 * - 현재 비밀번호는 users.password 와 직접 비교하므로 여기에는 **이전** 해시만 둔다.
 * - 회원당 최대 9건(재사용 금지 최대 N=10 = 현재 1 + 이전 9). 초과분은 서비스가 지운다.
 * - created_at 은 그 비밀번호를 그만 쓴 시각이다.
 * - 탈퇴·삭제 시 서비스가 명시 삭제한다. FK CASCADE 는 삭제 누락에 대비한 안전망일 뿐 의존하지 않는다.
 *
 * 인덱스 (user_id, id): 회원별 최신순 조회와 상한 초과분 정리가 이 한 쌍으로 끝난다.
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
        if (Schema::hasTable('user_password_histories')) {
            return;
        }

        Schema::create('user_password_histories', function (Blueprint $table) {
            $table->comment('회원 이전 비밀번호 해시 이력 (재사용 금지 판정, 회원당 최대 9건)');

            $table->id()->comment('이력 ID');
            $table->foreignId('user_id')
                ->comment('회원 users.id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('password')->comment('이전 비밀번호 해시');
            $table->timestamp('created_at')->nullable()->comment('그 비밀번호를 그만 쓴 일시');

            $table->index(['user_id', 'id'], 'user_password_histories_user_id_id_index');
        });
    }

    /**
     * 마이그레이션 되돌리기
     */
    public function down(): void
    {
        Schema::dropIfExists('user_password_histories');
    }
};
