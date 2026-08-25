<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Carbon\Carbon;
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

    /**
     * 취소 건의 옵션 상세 상태를 일괄 갱신합니다. (취소 확정 시 completed 전이)
     *
     * @param  int  $cancelId  주문 취소 ID
     * @param  string  $status  옵션 상태 값
     * @param  int|null  $processedBy  처리 관리자 ID
     * @param  Carbon|null  $completedAt  완료 시각 (완료 전이일 때만)
     * @return int 갱신된 행 수
     */
    public function bulkUpdateStatus(int $cancelId, string $status, ?int $processedBy = null, ?Carbon $completedAt = null): int;
}
