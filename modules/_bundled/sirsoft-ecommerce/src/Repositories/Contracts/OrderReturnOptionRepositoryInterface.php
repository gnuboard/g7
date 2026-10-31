<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;

/**
 * 반품 신청 상품 리포지토리 인터페이스
 */
interface OrderReturnOptionRepositoryInterface
{
    /**
     * 반품 신청 상품을 생성합니다.
     *
     * @param  array  $data  신청 상품 데이터
     * @return OrderReturnOption 생성된 신청 상품
     */
    public function create(array $data): OrderReturnOption;

    /**
     * 반품 신청 상품을 수정합니다.
     *
     * @param  OrderReturnOption  $option  신청 상품
     * @param  array  $data  수정 데이터
     * @return OrderReturnOption 수정된 신청 상품
     */
    public function update(OrderReturnOption $option, array $data): OrderReturnOption;

    /**
     * ID로 반품 신청 상품을 조회합니다.
     *
     * @param  int  $id  신청 상품 ID
     * @param  array<int, string>  $relations  eager load 할 관계
     * @return OrderReturnOption|null 신청 상품 (없으면 null)
     */
    public function findById(int $id, array $relations = []): ?OrderReturnOption;

    /**
     * 신청 상품 행을 저장합니다. (수량 분할이 만든 잔여 행)
     *
     * 잔여 행은 원본을 `replicate()` 한 **모델**이라 캐스트가 이미 적용된 상태다 —
     * 원시 속성 배열로 다시 만들면 JSON 캐스트 컬럼이 두 번 인코딩된다.
     *
     * @param  OrderReturnOption  $option  신청 상품 행
     * @return OrderReturnOption 저장된 신청 상품 행
     */
    public function save(OrderReturnOption $option): OrderReturnOption;

    /**
     * 반품 신청서의 신청 상품 목록을 조회합니다.
     *
     * @param  int  $returnId  반품 신청서 ID
     * @param  array<int, string>  $relations  eager load 할 관계
     * @return Collection 신청 상품 목록
     */
    public function listByReturnId(int $returnId, array $relations = []): Collection;

    /**
     * 여러 반품 신청서의 상품 행 ID 를 신청서별로 묶어 한 번에 조회합니다.
     *
     * 다건 요청(완료 미리보기)의 소속 검증용이다. 행마다 `exists` 를 걸면 신청서 수 ×
     * 상품 수만큼 쿼리가 나가므로 한 번에 읽어 메모리에서 판정한다.
     *
     * @param  array<int, int>  $returnIds  반품 신청서 ID 목록
     * @return array<int, array<int, int>> 신청서 ID => 상품 행 ID 목록
     */
    public function listOptionIdsByReturnIds(array $returnIds): array;

    /**
     * 한 주문에서 **완료된** 반품 신청 상품 행을 조회합니다. (S2-D5 — 누적 재계산)
     *
     * 재계산은 반품완료 행을 잔여로 세므로, 순차 부분반품에서 「이번 회차 몫」만 넘기면
     * 앞 회차 상품이 여전히 남아 있다고 계산돼 쿠폰 조건이 깨지지 않는다.
     * 완료 처리가 이 목록으로 **누적 수량**을 만들어 넘긴다.
     *
     * @param  int  $orderId  주문 ID
     * @param  int|null  $excludeReturnId  제외할 반품 신청서 ID (보통 호출자 자신)
     * @return Collection 완료된 신청 상품 행
     */
    public function listCompletedByOrderId(int $orderId, ?int $excludeReturnId = null): Collection;

    /**
     * 반품 신청서의 신청 상품 상태를 일괄 변경합니다.
     *
     * @param  int  $returnId  반품 신청서 ID
     * @param  string  $status  변경할 상태 값
     * @param  array<int, int>  $optionIds  대상 신청 상품 ID (빈 배열이면 전건)
     * @return int 변경된 행 수
     */
    public function bulkUpdateStatus(int $returnId, string $status, array $optionIds = []): int;
}
