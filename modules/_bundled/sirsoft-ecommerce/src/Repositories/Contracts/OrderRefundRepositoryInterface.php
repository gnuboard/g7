<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Modules\Sirsoft\Ecommerce\Models\OrderRefund;

/**
 * 주문 환불 리포지토리 인터페이스
 *
 * 주문 환불 이력의 데이터 접근을 위한 인터페이스입니다.
 */
interface OrderRefundRepositoryInterface
{
    /**
     * 주문 환불 이력을 생성합니다.
     *
     * @param  array  $data  환불 이력 데이터
     * @return OrderRefund 생성된 환불 이력
     */
    public function create(array $data): OrderRefund;
}
