<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * field_values 테이블을 신설합니다.
 *
 * 저장 방식이 `value` 인 항목의 값을 소유자(회원, 게시글, 댓글, 주문)별로 한 행씩 담는다.
 * 중복 금지는 `unique_key` 로 건다 — 중복 금지를 켠 항목만 값을 채우고, 켜는 시점에 이미 겹친 값은
 * NULL 로 둔다(unique 인덱스는 NULL 을 여러 개 허용한다).
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
        if (Schema::hasTable('field_values')) {
            return;
        }

        Schema::create('field_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('field_definition_id')->comment('항목 정의 ID (field_definitions.id)');
            $table->string('owner_type', 40)->comment('소유자 종류 (user|board_post|board_comment|ecommerce_order)');
            $table->unsignedBigInteger('owner_id')->comment('소유자 ID');
            $table->text('value_text')->nullable()->comment('글 값 (한 줄·여러 줄 글, 기타 직접 입력 글 등)');
            $table->string('value_index', 191)->nullable()->comment('검색·정렬용 정규화 문자열 (선택 값, 글 앞부분)');
            $table->decimal('value_number', 20, 6)->nullable()->comment('숫자 값 (예/아니오는 1·0)');
            $table->dateTime('value_date')->nullable()->comment('날짜·시각 값 (기간은 시작)');
            $table->dateTime('value_date_end')->nullable()->comment('기간의 끝');
            $table->json('value_json')->nullable()->comment('구조 값 (여러 개 선택, 주소 등)');
            $table->string('unique_key', 191)->nullable()->comment('중복 금지용 정규화 값 (중복 금지 항목만 채움)');
            $table->timestamps();

            $table->unique(['field_definition_id', 'owner_type', 'owner_id'], 'uq_fval_def_owner');
            $table->unique(['field_definition_id', 'unique_key'], 'uq_fval_def_unique');
            $table->index(['field_definition_id', 'value_index'], 'idx_fval_def_index');
            $table->index(['field_definition_id', 'value_number'], 'idx_fval_def_number');
            $table->index(['field_definition_id', 'value_date'], 'idx_fval_def_date');
            $table->index(['owner_type', 'owner_id'], 'idx_fval_owner');
        });
    }

    /**
     * 테이블을 지웁니다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        Schema::dropIfExists('field_values');
    }
};
