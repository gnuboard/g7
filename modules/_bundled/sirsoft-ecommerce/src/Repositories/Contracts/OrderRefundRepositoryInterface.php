<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
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

    /**
     * 주문의 진행 중(비터미널) 환불신청서를 조회합니다.
     *
     * 취소 가능 금액 산정(D19)의 선점액 축이 쓰는 조회다 — 아직 실행되지 않은 환불이
     * 잡아 둔 금액을 한도에서 빼기 위한 것이므로, 완료·반려·철회된 건은 제외한다.
     *
     * @param  int  $orderId  주문 ID
     * @param  array<int>  $excludeIds  집계에서 제외할 환불신청서 ID (실행 대상 자기 자신)
     * @return Collection 진행 중 환불신청서 목록
     */
    public function listPendingByOrderId(int $orderId, array $excludeIds = []): Collection;
}
