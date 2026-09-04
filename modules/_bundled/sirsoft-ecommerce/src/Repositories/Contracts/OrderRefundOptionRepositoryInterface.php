<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Carbon\Carbon;
use Illuminate\Support\Collection;
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

    /**
     * 환불신청서에 딸린 상품 행의 상태를 일괄 변경합니다.
     *
     * 반려·철회로 신청서가 닫힐 때 상품 행을 함께 내려 수량 잠금을 풀거나,
     * 환불 실행·취소 확정이 상품 행을 완료로 확정하는 용도입니다.
     *
     * @param  int  $refundId  환불신청서 ID
     * @param  string  $status  변경할 상품 행 상태
     * @param  int|null  $processedBy  처리자 사용자 ID
     * @param  Carbon|null  $completedAt  완료 시각 (완료 전이일 때만)
     * @return int 변경된 행 수
     */
    public function bulkUpdateStatus(int $refundId, string $status, ?int $processedBy = null, ?Carbon $completedAt = null): int;

    /**
     * 환불신청서에 딸린 상품 행을 조회합니다.
     *
     * @param  int  $refundId  환불신청서 ID
     * @return Collection<int, OrderRefundOption> 상품 행 목록 (ID 오름차순)
     */
    public function listByRefundId(int $refundId): Collection;

    /**
     * 상품 행의 환불 금액을 갱신합니다.
     *
     * @param  OrderRefundOption  $option  상품 행
     * @param  float  $refundAmount  갱신할 환불 금액
     * @return OrderRefundOption 갱신된 상품 행
     */
    public function updateRefundAmount(OrderRefundOption $option, float $refundAmount): OrderRefundOption;
}
