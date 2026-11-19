<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Repositories;

use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentLifecycle;

/**
 * 에스크로 배송 등록 기록 저장소 — 결제 meta `escrow_delivery` 의 수령인 칸을 지운다.
 *
 * 쓰기는 이커머스 공개 계약 {@see PaymentLifecycle::scrubPaymentMetaKeys()} 에 맡긴다(#916). 주문 id 로 주소를 잡고
 * 이 결제사 결제 행만 바꾸며 `updated_at` 은 건드리지 않는다 — 파기는 결제 변경이 아니다.
 */
class EscrowDeliveryRepository implements EscrowDeliveryRepositoryInterface
{
    /**
     * @param  PaymentGatewayBridge  $bridge  이커머스 결제 공개 계약
     */
    public function __construct(
        private readonly PaymentGatewayBridge $bridge,
    ) {}

    /**
     * 주문의 결제사 결제 행에서 `escrow_delivery` 의 지정 칸을 NULL 로 바꿉니다.
     *
     * @param  int  $orderId  주문 ID
     * @param  string  $pgProvider  결제사 ID
     * @param  array<int, string>  $keys  지울 칸
     * @return int 바꾼 결제 행 수
     */
    public function scrubDeliveryKeys(int $orderId, string $pgProvider, array $keys): int
    {
        return $this->bridge->scrubPaymentMetaKeys($orderId, $pgProvider, 'escrow_delivery', $keys);
    }
}
