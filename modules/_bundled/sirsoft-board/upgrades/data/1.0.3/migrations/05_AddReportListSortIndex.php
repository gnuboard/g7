<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftBoard\V1_0_3\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Schema;

/**
 * 신고 목록의 정렬 색인을 추가합니다.
 *
 * 신고 관리 목록은 삭제되지 않은 신고를 접수일 내림차순으로 보여주는데, 그 순서를 덮는
 * 색인이 없어 뒤쪽 페이지가 테이블 전체를 훑고 정렬했습니다.
 *
 * 신규 설치는 마이그레이션이 처리하지만 기존 사이트에는 반영되지 않으므로 업그레이드
 * 시점에 같은 색인을 추가합니다.
 *
 * 신고가 많이 쌓인 사이트에서는 수 분 걸릴 수 있고 그동안 신고 접수가 대기합니다.
 *
 * idempotent: 이미 존재하는 색인은 건너뜁니다. V-1 안전: Facades\Schema 만 사용합니다.
 */
class AddReportListSortIndex implements DataMigration
{
    private const TABLE = 'boards_reports';

    private const INDEX_NAME = 'idx_boards_reports_deleted_created_id';

    /** @var array<int, string> */
    private const INDEX_COLUMNS = ['deleted_at', 'created_at', 'id'];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'AddReportListSortIndex';
    }

    /**
     * 신고 목록 정렬 색인을 추가합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $context->logger->info('[board:1.0.3] 신고 테이블 부재 — 정렬 색인 추가 스킵');

            return;
        }

        $existing = array_column(Schema::getIndexes(self::TABLE), 'name');

        if (in_array(self::INDEX_NAME, $existing, true)) {
            $context->logger->info('[board:1.0.3] 이미 존재하는 색인 — 스킵: '.self::INDEX_NAME);

            return;
        }

        $context->logger->info('[board:1.0.3] 신고 목록 정렬 색인 추가 시작 (신고가 많으면 수 분 걸릴 수 있고 그동안 신고 접수가 대기합니다)');

        Schema::table(self::TABLE, function ($table) {
            $table->index(self::INDEX_COLUMNS, self::INDEX_NAME);
        });

        $context->logger->info('[board:1.0.3] 신고 목록 정렬 색인 추가 완료: '.self::INDEX_NAME);
    }
}
