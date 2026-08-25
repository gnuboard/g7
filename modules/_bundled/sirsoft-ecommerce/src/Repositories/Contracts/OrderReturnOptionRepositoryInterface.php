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
     * 반품 신청서의 신청 상품 상태를 일괄 변경합니다.
     *
     * @param  int  $returnId  반품 신청서 ID
     * @param  string  $status  변경할 상태 값
     * @param  array<int, int>  $optionIds  대상 신청 상품 ID (빈 배열이면 전건)
     * @return int 변경된 행 수
     */
    public function bulkUpdateStatus(int $returnId, string $status, array $optionIds = []): int;

    /**
     * 반품 신청서의 신청 상품을 모두 삭제합니다.
     *
     * @param  int  $returnId  반품 신청서 ID
     * @return int 삭제된 행 수
     */
    public function deleteByReturnId(int $returnId): int;
}
