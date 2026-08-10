<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 발행 페이지 목록의 정렬 색인을 추가합니다.
 *
 * 페이지 목록과 통합검색의 페이지 탭은 `published` 로 좁힌 뒤 등록일 순으로 정렬한다.
 * 기존 색인은 `published` 단독이라 조건은 도왔지만 정렬은 매번 다시 해야 했다.
 *
 * 마지막에 `id` 를 덧붙이는 이유는 전순서 보장이다. 같은 시각에 등록된 페이지가 있으면
 * 그 구간의 순서가 흔들려 인접 페이지가 같은 항목을 중복 노출하고 다른 항목을 빠뜨린다.
 *
 * 기존 사이트에는 마이그레이션이 재실행되지 않으므로 같은 색인을 업그레이드 스텝에서도 적용한다.
 */
return new class extends Migration
{
    /**
     * 대상 테이블
     */
    private const TABLE = 'pages';

    /**
     * 신규 색인명
     */
    private const INDEX = 'idx_pages_published_created_id';

    /**
     * 신규 색인 컬럼
     *
     * @var array<int, string>
     */
    private const COLUMNS = ['published', 'created_at', 'id'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (in_array(self::INDEX, array_column(Schema::getIndexes(self::TABLE), 'name'), true)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $blueprint) {
            $blueprint->index(self::COLUMNS, self::INDEX);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! in_array(self::INDEX, array_column(Schema::getIndexes(self::TABLE), 'name'), true)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $blueprint) {
            $blueprint->dropIndex(self::INDEX);
        });
    }
};
