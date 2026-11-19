<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users 에 회원 항목·표시 이름·닉네임 정책 컬럼을 추가합니다.
 *
 * - display_name_kind           : 회원이 고른 표시 이름 종류. NULL 이면 사이트의 회원 기본 종류를 따른다.
 * - nickname_normalized         : 닉네임의 정규화 값(NFKC → 소문자 → 공백 제거). 중복·금지어 비교와 검색에 쓴다.
 * - nickname_unique_key         : 닉네임 중복 금지용 값. 중복 금지 정책이 켜져 있고 값이 유일한 회원만 채운다.
 *                                 정책을 켜는 시점에 이미 겹친 닉네임은 NULL 로 둔다(unique 인덱스는 NULL 을 여러 개 허용).
 * - nickname_changed_at         : 닉네임을 마지막으로 바꾼 시각. 변경 주기 판정에 쓴다.
 * - required_fields_deferred_at : 필수 항목을 건너뛰고 만들어진 회원(화면 없는 외부 인증 생성, 관리자 사전 등록 등)의
 *                                 생성 시각. 값이 있으면 첫 로그인 뒤 추가 정보 입력 화면으로 보내고, 입력을 마치면 NULL 로 지운다.
 *
 * 인덱스는 다음 마이그레이션에서 따로 추가한다(컬럼 추가와 인덱스를 분리 — 큰 표의 부분 실패 뒤 재실행을 위해).
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

        // 컬럼마다 존재를 확인한다 — 일부만 추가된 채 실패한 뒤 다시 실행해도 남은 컬럼을 채운다.
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'display_name_kind')) {
                $table->string('display_name_kind', 120)->nullable()->after('nickname')
                    ->comment('회원이 고른 표시 이름 종류 (name|nickname|masked_name|masked_id|name_nickname|field:{키}|확장 종류) — NULL 이면 회원 기본 종류');
            }
            if (! Schema::hasColumn('users', 'nickname_normalized')) {
                $table->string('nickname_normalized', 191)->nullable()->after('nickname')
                    ->comment('닉네임 정규화 값 (NFKC → 소문자 → 공백 제거) — 중복·금지어 비교와 검색용');
            }
            if (! Schema::hasColumn('users', 'nickname_unique_key')) {
                $table->string('nickname_unique_key', 191)->nullable()->after('nickname')
                    ->comment('닉네임 중복 금지용 값 — 정책이 켜져 있고 값이 유일한 회원만 채움 (겹친 기존 값은 NULL)');
            }
            if (! Schema::hasColumn('users', 'nickname_changed_at')) {
                $table->timestamp('nickname_changed_at')->nullable()->after('nickname')
                    ->comment('닉네임 마지막 변경 시각 — 변경 주기 판정용');
            }
            if (! Schema::hasColumn('users', 'required_fields_deferred_at')) {
                $table->timestamp('required_fields_deferred_at')->nullable()
                    ->comment('필수 항목을 건너뛰고 만들어진 시각 — 값이 있으면 첫 로그인 뒤 추가 정보 입력을 요구, 입력을 마치면 NULL');
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

        $columns = array_values(array_filter(
            ['display_name_kind', 'nickname_normalized', 'nickname_unique_key', 'nickname_changed_at', 'required_fields_deferred_at'],
            fn (string $column) => Schema::hasColumn('users', $column),
        ));

        if ($columns !== []) {
            Schema::table('users', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
