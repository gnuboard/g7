<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Modules\Sirsoft\Ecommerce\Models\OrderCancelOption;

/**
 * 주문 취소 옵션 리포지토리 인터페이스
 *
 * 주문 취소 옵션 상세의 데이터 접근을 위한 인터페이스입니다.
 */
interface OrderCancelOptionRepositoryInterface
{
    /**
     * 주문 취소 옵션 상세를 생성합니다.
     *
     * @param  array  $data  취소 옵션 데이터
     * @return OrderCancelOption 생성된 취소 옵션
     */
    public function create(array $data): OrderCancelOption;
}
