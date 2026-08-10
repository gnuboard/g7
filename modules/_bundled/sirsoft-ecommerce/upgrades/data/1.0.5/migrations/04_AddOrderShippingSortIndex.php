<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_0_5\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Schema;

/**
 * 주문 목록 "발송일" 정렬용 복합 인덱스를 추가합니다.
 *
 * 주문 목록은 배송 테이블의 `shipped_at` 을 상관 서브쿼리로 읽어 정렬합니다. 주문 1건마다
 * 서브쿼리가 한 번씩 실행되므로 `(order_id, shipped_at)` 복합 인덱스가 없으면 주문 수만큼
 * 배송 테이블을 스캔합니다. 기존 단일 인덱스 `order_id` 만으로는 정렬 컬럼이 인덱스에 없어
 * 매번 행을 읽고 정렬해야 합니다.
 *
 * 신규 설치는 마이그레이션이 처리하지만 기존 사이트에는 반영되지 않으므로 업그레이드 시점에
 * 동일 인덱스를 추가합니다.
 *
 * 주문이 많은 쇼핑몰에서는 ALTER TABLE 이 수 분 걸릴 수 있고 그동안 배송 정보 쓰기가
 * 대기합니다. 진행 상황을 로그로 남깁니다.
 *
 * idempotent: 이미 존재하는 인덱스는 건너뜁니다. V-1 안전: Facades\Schema 만 사용합니다.
 */
class AddOrderShippingSortIndex implements DataMigration
{
    private const TABLE = 'ecommerce_order_shippings';

    private const INDEX_NAME = 'ecommerce_order_shippings_order_id_shipped_at_index';

    /** @var array<int, string> */
    private const INDEX_COLUMNS = ['order_id', 'shipped_at'];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'AddOrderShippingSortIndex';
    }

    /**
     * 발송일 정렬 인덱스를 추가합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $context->logger->info('[ecommerce:1.0.5] 주문 배송 테이블 부재 — 발송일 정렬 인덱스 추가 스킵');

            return;
        }

        $existing = array_column(Schema::getIndexes(self::TABLE), 'name');

        if (in_array(self::INDEX_NAME, $existing, true)) {
            $context->logger->info('[ecommerce:1.0.5] 이미 존재하는 인덱스 — 스킵: '.self::INDEX_NAME);

            return;
        }

        $context->logger->info('[ecommerce:1.0.5] 발송일 정렬 인덱스 추가 시작: '.self::INDEX_NAME.' (주문이 많으면 수 분 걸릴 수 있고 그동안 배송 정보 쓰기가 대기합니다)');

        Schema::table(self::TABLE, function ($table) {
            $table->index(self::INDEX_COLUMNS, self::INDEX_NAME);
        });

        $context->logger->info('[ecommerce:1.0.5] 발송일 정렬 인덱스 추가 완료: '.self::INDEX_NAME);
    }
}
