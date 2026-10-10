<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;

/**
 * 교환 신청 상품 리포지토리 인터페이스
 */
interface OrderExchangeOptionRepositoryInterface
{
    /**
     * 교환 신청 상품을 생성합니다.
     *
     * @param  array  $data  신청 상품 데이터
     * @return OrderExchangeOption 생성된 신청 상품
     */
    public function create(array $data): OrderExchangeOption;

    /**
     * 교환 신청 상품을 수정합니다.
     *
     * @param  OrderExchangeOption  $option  신청 상품
     * @param  array  $data  수정 데이터
     * @return OrderExchangeOption 수정된 신청 상품
     */
    public function update(OrderExchangeOption $option, array $data): OrderExchangeOption;

    /**
     * ID로 교환 신청 상품을 조회합니다.
     *
     * @param  int  $id  신청 상품 ID
     * @param  array<int, string>  $relations  eager load 할 관계
     * @return OrderExchangeOption|null 신청 상품 (없으면 null)
     */
    public function findById(int $id, array $relations = []): ?OrderExchangeOption;

    /**
     * 신청 상품 행을 저장합니다. (수량 분할이 만든 잔여 행)
     *
     * 잔여 행은 원본을 `replicate()` 한 **모델**이라 캐스트가 이미 적용된 상태다 —
     * 원시 속성 배열로 다시 만들면 JSON 캐스트 컬럼이 두 번 인코딩된다.
     *
     * @param  OrderExchangeOption  $option  신청 상품 행
     * @return OrderExchangeOption 저장된 신청 상품 행
     */
    public function save(OrderExchangeOption $option): OrderExchangeOption;

    /**
     * 교환 신청서의 신청 상품 목록을 조회합니다.
     *
     * @param  int  $exchangeId  교환 신청서 ID
     * @param  array<int, string>  $relations  eager load 할 관계
     * @return Collection 신청 상품 목록
     */
    public function listByExchangeId(int $exchangeId, array $relations = []): Collection;

    /**
     * 후속 주문옵션 id 목록으로 교환 신청 상품 행을 조회합니다. (재계산의 후속 행 회계 단가용)
     *
     * @param  array<int, int>  $orderOptionIds  후속 주문옵션 id
     * @param  array<int, string>  $relations  함께 로드할 관계
     * @return Collection<int, OrderExchangeOption>
     */
    public function listByNewOrderOptionIds(array $orderOptionIds, array $relations = []): Collection;

    /**
     * 여러 교환 신청서의 상품 행 ID 를 신청서별로 묶어 한 번에 조회합니다.
     *
     * 다건 요청(완료 미리보기)의 소속 검증용이다. 행마다 `exists` 를 걸면 신청서 수 ×
     * 상품 수만큼 쿼리가 나가므로 한 번에 읽어 메모리에서 판정한다.
     *
     * @param  array<int, int>  $exchangeIds  교환 신청서 ID 목록
     * @return array<int, array<int, int>> 신청서 ID => 상품 행 ID 목록
     */
    public function listOptionIdsByExchangeIds(array $exchangeIds): array;

    /**
     * 교환 신청서의 신청 상품 상태를 일괄 변경합니다.
     *
     * @param  int  $exchangeId  교환 신청서 ID
     * @param  string  $status  변경할 상태 값
     * @param  array<int, int>  $optionIds  대상 신청 상품 ID (빈 배열이면 전건)
     * @return int 변경된 행 수
     */
    public function bulkUpdateStatus(int $exchangeId, string $status, array $optionIds = []): int;
}
