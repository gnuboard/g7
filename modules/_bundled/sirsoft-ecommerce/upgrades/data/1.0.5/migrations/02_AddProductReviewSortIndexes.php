<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_0_5\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Schema;

/**
 * 상품 리뷰 목록의 정렬 인덱스를 추가합니다.
 *
 * 리뷰 목록은 상품 상세와 관리자 화면 모두 작성일 내림차순이 기본 정렬인데, created_at 이
 * 후행하는 복합 인덱스가 없어 정렬마다 filesort 가 발생했습니다. 신규 설치는 마이그레이션이
 * 처리하지만 기존 사이트에는 반영되지 않으므로 업그레이드 시점에 동일 인덱스를 추가합니다.
 *
 * 리뷰가 많은 쇼핑몰에서는 ALTER TABLE 이 수 분 걸릴 수 있고 그동안 해당 테이블 쓰기가
 * 대기합니다. 진행 상황을 로그로 남깁니다.
 *
 * idempotent: 이미 존재하는 인덱스는 건너뜁니다. V-1 안전: Facades\Schema 만 사용합니다.
 */
class AddProductReviewSortIndexes implements DataMigration
{
    private const TABLE = 'ecommerce_product_reviews';

    /**
     * 추가할 인덱스 정의 (이름 => 컬럼 목록)
     *
     * @var array<string, array<int, string>>
     */
    private const INDEXES = [
        'idx_reviews_product_status_deleted_created' => ['product_id', 'status', 'deleted_at', 'created_at'],
        // 관리자 목록: status 는 선택적 필터라 선행 컬럼으로 두면 필터 없는 기본 진입에서
        // created_at 정렬을 못 덮는다. 항상 IS NULL 로 붙는 deleted_at 을 선행에 둔다.
        'idx_reviews_deleted_created' => ['deleted_at', 'created_at'],
    ];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'AddProductReviewSortIndexes';
    }

    /**
     * 정렬 인덱스를 추가합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $context->logger->info('[ecommerce:1.0.5] 상품 리뷰 테이블 부재 — 정렬 인덱스 추가 스킵');

            return;
        }

        $existing = array_column(Schema::getIndexes(self::TABLE), 'name');

        foreach (self::INDEXES as $name => $columns) {
            if (in_array($name, $existing, true)) {
                $context->logger->info("[ecommerce:1.0.5] 이미 존재하는 인덱스 — 스킵: {$name}");

                continue;
            }

            $context->logger->info("[ecommerce:1.0.5] 정렬 인덱스 추가 시작: {$name} (리뷰가 많으면 수 분 걸릴 수 있고 그동안 리뷰 쓰기가 대기합니다)");

            Schema::table(self::TABLE, function ($table) use ($columns, $name) {
                $table->index($columns, $name);
            });

            $context->logger->info("[ecommerce:1.0.5] 정렬 인덱스 추가 완료: {$name}");
        }
    }
}
