<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Modules\Sirsoft\Ecommerce\Models\OrderCancel;

/**
 * 주문 취소 리포지토리 인터페이스
 *
 * 주문 취소 이력의 데이터 접근을 위한 인터페이스입니다.
 */
interface OrderCancelRepositoryInterface
{
    /**
     * 주문 취소 이력을 생성합니다.
     *
     * @param  array  $data  취소 이력 데이터
     * @return OrderCancel 생성된 취소 이력
     */
    public function create(array $data): OrderCancel;

    /**
     * 주문의 가장 최근 취소 이력을 조회합니다.
     *
     * @param  int  $orderId  주문 ID
     * @return OrderCancel|null 최근 취소 이력 (없으면 null)
     */
    public function latestByOrderId(int $orderId): ?OrderCancel;

    /**
     * 특정 취소 사유 코드를 사용한 취소 이력 건수를 셉니다.
     *
     * 클레임 사유를 삭제해도 되는지 판단할 때 사용합니다 (사용 중이면 삭제 불가).
     *
     * @param  string  $reasonCode  클레임 사유 코드
     * @return int 해당 사유를 사용한 취소 이력 건수
     */
    public function countByCancelReasonType(string $reasonCode): int;
}
