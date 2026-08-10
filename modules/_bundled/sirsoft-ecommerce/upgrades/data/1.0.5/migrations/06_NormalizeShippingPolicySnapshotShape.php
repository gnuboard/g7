<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_0_5\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 주문의 배송정책 적용 스냅샷을 `{items: [...], address: {...}}` 구조로 정규화합니다.
 *
 * 종전 쓰기 지점은 항목 리스트(정수 키)에 배송지 메타(`'address'` 문자열 키)를 덧붙여
 * 한 배열에 섞어 저장했습니다. PHP 배열이 non-sequential 이 되면 `json_encode` 가 리스트가
 * 아니라 객체 `{"0": {...}, "address": {...}}` 로 직렬화합니다. 서버는 키 타입을 보고
 * 관용 처리해 문제가 없었지만, 배열을 전제하는 화면에서는 배송정책 표시 블록이 오류 표시
 * 없이 통째로 사라졌습니다(마이페이지·비회원 주문 상세).
 *
 * 소스 수정만으로는 이미 저장된 주문이 낫지 않으므로 기존 행을 함께 옮깁니다.
 *
 * idempotent: 이미 `items` 키를 가진 행은 건드리지 않습니다.
 * V-1 안전: Facades\DB/Schema 만 사용하며 모델·서비스에 의존하지 않습니다.
 */
class NormalizeShippingPolicySnapshotShape implements DataMigration
{
    private const ORDERS_TABLE = 'ecommerce_orders';

    private const COLUMN = 'shipping_policy_applied_snapshot';

    /** @var int 청크 크기 */
    private const CHUNK = 200;

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'NormalizeShippingPolicySnapshotShape';
    }

    /**
     * 구형 혼합 배열 스냅샷을 신형 구조로 옮깁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::ORDERS_TABLE)
            || ! Schema::hasColumn(self::ORDERS_TABLE, self::COLUMN)) {
            $context->logger->info('[ecommerce:1.0.5] 배송정책 스냅샷 정규화 — 대상 스키마 부재로 스킵');

            return;
        }

        $updated = 0;
        $skipped = 0;
        $lastId = 0;

        // 갱신값이 필터(whereNotNull) 소속을 유지하므로 키셋 순회로 누락 없이 처리한다.
        while (true) {
            $orders = DB::table(self::ORDERS_TABLE)
                ->where('id', '>', $lastId)
                ->whereNotNull(self::COLUMN)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get(['id', self::COLUMN]);

            if ($orders->isEmpty()) {
                break;
            }

            foreach ($orders as $order) {
                $lastId = (int) $order->id;

                $decoded = json_decode((string) $order->{self::COLUMN}, true);
                if (! is_array($decoded) || $decoded === []) {
                    $skipped++;

                    continue;
                }

                // 이미 신형이면 건드리지 않는다 (멱등)
                if (array_key_exists('items', $decoded)) {
                    $skipped++;

                    continue;
                }

                $items = [];
                $address = [];

                foreach ($decoded as $key => $entry) {
                    if ($key === 'address') {
                        $address = is_array($entry) ? $entry : [];

                        continue;
                    }

                    if (is_array($entry)) {
                        $items[] = $entry;
                    }
                }

                DB::table(self::ORDERS_TABLE)
                    ->where('id', $order->id)
                    ->update([
                        self::COLUMN => json_encode(
                            ['items' => array_values($items), 'address' => $address],
                            JSON_UNESCAPED_UNICODE
                        ),
                    ]);

                $updated++;
            }
        }

        $context->logger->info(
            "[ecommerce:1.0.5] 배송정책 스냅샷 정규화 — 갱신 {$updated}건, 유지 {$skipped}건"
        );
    }
}
