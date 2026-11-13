<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users 의 닉네임 정책 컬럼에 인덱스를 추가합니다.
 *
 * - idx_users_nickname_normalized : 정규화 닉네임 조회(중복 검사, 겹치는 회원 목록, 작성자 검색)
 * - uq_users_nickname_unique_key  : 닉네임 중복 금지의 최종 방어선. 값이 NULL 인 회원은 제약을 받지 않는다.
 *
 * 재실행·부분 실패에 견디도록 이름으로 존재를 검사한 뒤 추가한다(add_filter_indexes_to_users_table 선례).
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
        if (! Schema::hasTable('users')) {
            return;
        }

        $existing = array_column(Schema::getIndexes('users'), 'name');

        Schema::table('users', function (Blueprint $table) use ($existing) {
            if (! in_array('idx_users_nickname_normalized', $existing, true) && Schema::hasColumn('users', 'nickname_normalized')) {
                $table->index('nickname_normalized', 'idx_users_nickname_normalized');
            }
            if (! in_array('uq_users_nickname_unique_key', $existing, true) && Schema::hasColumn('users', 'nickname_unique_key')) {
                $table->unique('nickname_unique_key', 'uq_users_nickname_unique_key');
            }
        });
    }

    /**
     * 마이그레이션 롤백
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $existing = array_column(Schema::getIndexes('users'), 'name');

        Schema::table('users', function (Blueprint $table) use ($existing) {
            if (in_array('idx_users_nickname_normalized', $existing, true)) {
                $table->dropIndex('idx_users_nickname_normalized');
            }
            if (in_array('uq_users_nickname_unique_key', $existing, true)) {
                $table->dropUnique('uq_users_nickname_unique_key');
            }
        });
    }
};
