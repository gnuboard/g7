<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Repositories;

use Modules\Sirsoft\Ecommerce\Contracts\Payment\OrderAccess;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentLifecycle;

/**
 * CBT 대사 레코드 저장소 — 주문 meta 의 이 결제사 키(`kginicis_cbt_reconciliation`)만 읽고 쓴다.
 *
 * 저장은 이커머스 공개 계약에 맡긴다(#916): 읽기는 잠금 없는 {@see OrderAccess::getOrderMeta()},
 * 쓰기는 주문 행 잠금 아래의 {@see PaymentLifecycle::mutateOrderMetaWithLock()} (저장 때 주문 `updated_at` 갱신).
 */
class CbtReconciliationRepository implements CbtReconciliationRepositoryInterface
{
    private const PG_PROVIDER_ID = 'kginicis';

    /**
     * @param  PaymentGatewayBridge  $bridge  이커머스 결제 공개 계약
     */
    public function __construct(
        private readonly PaymentGatewayBridge $bridge,
    ) {}

    /**
     * 주문 메타에 저장된 조정 레코드를 조회합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $key  주문 메타 키
     * @return array<string, mixed>|null 조정 레코드
     */
    public function findRecord(string $orderNumber, string $key): ?array
    {
        return $this->bridge->getOrderMeta($orderNumber, self::PG_PROVIDER_ID, $key);
    }

    /**
     * 주문 메타에 조정 레코드를 저장합니다.
     *
     * 종전에는 잠금 없이 덮어썼다 — 지금은 주문 행 잠금 아래에서 같은 값으로 덮어쓴다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $key  주문 메타 키
     * @param  array<string, mixed>  $record  저장할 조정 레코드
     * @return array<string, mixed>|null 저장된 조정 레코드 (주문이 없으면 null)
     */
    public function saveRecord(string $orderNumber, string $key, array $record): ?array
    {
        return $this->bridge->mutateOrderMetaWithLock($orderNumber, self::PG_PROVIDER_ID, $key, fn (): array => $record);
    }

    /**
     * 주문 row 를 잠근 상태로 조정 레코드를 원자적으로 변경합니다.
     *
     * 주문이 없으면 null, 기존 레코드가 없으면 빈 배열을 콜백에 넘기고, 콜백이 배열이 아닌 값을 돌려주면 저장하지 않고 null 이다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $key  주문 메타 키
     * @param  callable(array<string, mixed>): (array<string, mixed>|null)  $mutator  레코드 변경 콜백
     * @return array<string, mixed>|null 변경된 조정 레코드
     */
    public function mutateRecordWithLock(string $orderNumber, string $key, callable $mutator): ?array
    {
        return $this->bridge->mutateOrderMetaWithLock(
            $orderNumber,
            self::PG_PROVIDER_ID,
            $key,
            function (?array $existing) use ($mutator): ?array {
                $record = $mutator($existing ?? []);

                return is_array($record) ? $record : null;
            },
        );
    }
}
