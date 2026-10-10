<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Repositories;

interface EscrowDeliveryRepositoryInterface
{
    /**
     * 한 주문의 KG 이니시스 결제 행에서 에스크로 배송 등록 기록의 지정 칸을 NULL 로 지웁니다.
     *
     * 에스크로 배송 등록이 없는 결제 행은 건드리지 않고, 결제 행의 수정 시각은 바꾸지 않는다.
     *
     * @param  int  $orderId  주문 ID
     * @param  string  $pgProvider  결제 대행사 식별자
     * @param  array<int, string>  $keys  `payment_meta.escrow_delivery` 안에서 지울 칸
     * @return int 지운 결제 행 수
     */
    public function scrubDeliveryKeys(int $orderId, string $pgProvider, array $keys): int;
}
