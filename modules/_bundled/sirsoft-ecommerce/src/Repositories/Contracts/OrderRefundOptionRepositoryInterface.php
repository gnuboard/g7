<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;

/**
 * 주문 환불 옵션 리포지토리 인터페이스
 *
 * 주문 환불 옵션 상세의 데이터 접근을 위한 인터페이스입니다.
 */
interface OrderRefundOptionRepositoryInterface
{
    /**
     * 주문 환불 옵션 상세를 생성합니다.
     *
     * @param  array  $data  환불 옵션 데이터
     * @return OrderRefundOption 생성된 환불 옵션
     */
    public function create(array $data): OrderRefundOption;
}
