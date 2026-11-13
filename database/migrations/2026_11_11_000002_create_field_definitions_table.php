<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * field_definitions 테이블을 신설합니다.
 *
 * 항목 집합(회원 `core.user`, 게시판 비회원 작성자, 주문서 등)에 속한 입력 항목 한 건을 담는다.
 * 코어·확장의 선언(`config/core.php` 의 `fields`, 확장의 `getFields()`)은 FieldDefinitionSyncHelper 가
 * 이 테이블로 동기화하고, 운영자가 만든 항목은 source_type='custom' 으로 같은 테이블에 들어간다.
 *
 * @since 7.2.0
 */
return new class extends Migration
{
    /**
     * 테이블을 만듭니다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (Schema::hasTable('field_definitions')) {
            return;
        }

        Schema::create('field_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('field_set', 100)->comment('항목 집합 식별자 (예: core.user, sirsoft-board.guest_author)');
            $table->string('key', 100)->comment('집합 안에서 유일한 항목 키 (영문 소문자·숫자·밑줄)');
            $table->string('source_type', 20)->default('custom')->comment('출처 (core|module|plugin|custom) — custom 은 운영자가 만든 항목');
            $table->string('source_identifier', 100)->default('custom')->comment('출처 식별자 (core, 확장 식별자, custom)');
            $table->string('type', 50)->comment('입력 유형 키 (text, choice, address 등 — FieldTypeRegistry)');
            $table->string('storage', 20)->default('value')->comment('저장 방식 (column=소유 테이블 컬럼|handler=확장 핸들러|value=field_values)');
            $table->string('storage_target', 255)->nullable()->comment('column 이면 컬럼명, handler 이면 핸들러 클래스명');
            $table->json('name')->comment('항목 이름 (다국어 JSON)');
            $table->json('description')->nullable()->comment('안내 문구 (다국어 JSON)');
            $table->json('placeholder')->nullable()->comment('예시 글 (다국어 JSON)');
            $table->json('options')->nullable()->comment('선택지 목록 [{value, label(다국어)}]');
            $table->json('constraints')->nullable()->comment('입력 제한·표시 옵션 (min_length, max_length, min, max, regex, unique, display 등)');
            $table->boolean('enabled')->default(true)->comment('사용 여부');
            $table->boolean('required')->default(false)->comment('필수 여부');
            $table->boolean('show_on_signup')->default(true)->comment('가입 화면 표시 여부');
            $table->string('edit_scope', 20)->default('self')->comment('범위 (self=본인 수정|admin_edit=관리자만 수정|admin_only=관리자만 열람)');
            $table->boolean('require_existing_members')->default(false)->comment('기존 회원에게도 입력 요구');
            $table->boolean('public_profile')->default(false)->comment('공개 프로필 노출');
            $table->boolean('admin_list_column')->default(false)->comment('관리자 목록 열로 제공');
            $table->boolean('admin_searchable')->default(false)->comment('관리자 목록 검색 대상');
            $table->boolean('display_name_usable')->default(false)->comment('표시 이름 종류로 사용 가능');
            $table->string('idv_claim', 50)->nullable()->comment('본인인증 값 연결 (name|mobile|birth_date|gender 등)');
            $table->boolean('idv_lock')->default(false)->comment('본인인증 값으로 채우고 잠금');
            $table->json('locks')->nullable()->comment('선언한 쪽이 운영자 변경을 막은 속성과 사유 {속성: 사유 키}');
            $table->json('legacy_keys')->nullable()->comment('수용하는 옛 요청 키 목록');
            $table->json('ui')->nullable()->comment('화면 배치 (group, width, surfaces 등)');
            $table->integer('sort_order')->default(0)->comment('표시 순서 (오름차순)');
            $table->json('user_overrides')->nullable()->comment('운영자가 고친 속성 목록 (재동기화 시 보존)');
            $table->timestamps();

            $table->unique(['field_set', 'key'], 'uq_fdef_set_key');
            $table->index(['field_set', 'enabled', 'sort_order'], 'idx_fdef_set_enabled_order');
            $table->index(['source_type', 'source_identifier'], 'idx_fdef_source');
        });
    }

    /**
     * 테이블을 지웁니다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        Schema::dropIfExists('field_definitions');
    }
};
