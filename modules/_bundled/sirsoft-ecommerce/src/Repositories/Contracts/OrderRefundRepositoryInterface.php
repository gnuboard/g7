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
     * ID로 환불신청서를 조회합니다.
     *
     * @param  int  $id  환불신청서 ID
     * @param  array  $relations  로드할 관계
     * @return OrderRefund|null 환불신청서 (없으면 null)
     */
    public function findById(int $id, array $relations = []): ?OrderRefund;

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

    /**
     * 발생 출처(polymorphic 축)로 환불신청서 목록을 조회합니다.
     *
     * 교환 신청서는 반품과 달리 `refund_id` 역참조 컬럼이 없어, "이 교환에서 어떤 환불신청서가
     * 접수됐는가" 는 이 축으로만 되짚는다. 교환은 완료 라운드마다 그 라운드의 차액으로
     * 환불신청서를 접수할 수 있으므로 출처당 여러 건이 있을 수 있다(반려·철회된 건 포함).
     *
     * @param  string  $sourceType  발생 출처 유형 (RefundSourceTypeEnum 값)
     * @param  int  $sourceId  발생 출처 ID
     * @param  array<string>  $relations  함께 로드할 관계 (예: refundOptions)
     * @return Collection<int, OrderRefund> 환불신청서 목록 (ID 오름차순)
     */
    public function listBySource(string $sourceType, int $sourceId, array $relations = []): Collection;
}
