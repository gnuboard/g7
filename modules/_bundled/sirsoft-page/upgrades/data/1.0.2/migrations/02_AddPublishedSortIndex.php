<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftPage\V1_0_2\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Schema;

/**
 * 발행 페이지 목록의 정렬 색인을 추가합니다.
 *
 * 페이지 목록과 통합검색의 페이지 탭은 발행 여부로 좁힌 뒤 등록일 순으로 정렬하는데,
 * 기존 색인은 발행 여부 단독이라 정렬을 매번 다시 해야 했습니다. 마지막에 기본키를
 * 덧붙여 같은 시각에 등록된 페이지들의 순서까지 고정합니다 — 그래야 인접 페이지가
 * 같은 항목을 중복 노출하거나 빠뜨리지 않습니다.
 *
 * 신규 설치는 마이그레이션이 처리하지만 기존 사이트에는 반영되지 않으므로 업그레이드
 * 시점에 같은 색인을 적용합니다.
 *
 * idempotent: 이미 적용됐으면 건너뜁니다. V-1 안전: Facades\Schema 만 사용합니다.
 */
class AddPublishedSortIndex implements DataMigration
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
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'AddPublishedSortIndex';
    }

    /**
     * 정렬 색인을 적용합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $context->logger->info('[page:1.0.2] 테이블 부재 — 색인 적용 스킵: '.self::TABLE);

            return;
        }

        if (in_array(self::INDEX, array_column(Schema::getIndexes(self::TABLE), 'name'), true)) {
            $context->logger->info('[page:1.0.2] 이미 적용된 색인 — 스킵: '.self::INDEX);

            return;
        }

        $context->logger->info('[page:1.0.2] 목록 정렬 색인 적용 시작 (데이터가 많으면 수 분 걸릴 수 있고 그동안 해당 테이블 쓰기가 대기합니다)');

        Schema::table(self::TABLE, function ($blueprint) {
            $blueprint->index(self::COLUMNS, self::INDEX);
        });

        $context->logger->info('[page:1.0.2] 색인 적용 완료: '.self::INDEX);
    }
}
