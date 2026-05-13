<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;

/**
 * 주문 배송 리포지토리 인터페이스
 *
 * 주문 배송의 데이터 접근을 위한 인터페이스입니다.
 */
interface OrderShippingRepositoryInterface
{
    /**
     * ID로 주문 배송을 조회합니다.
     *
     * @param  int  $id  주문 배송 ID
     * @return OrderShipping|null 조회된 주문 배송 (없으면 null)
     */
    public function findById(int $id): ?OrderShipping;

    /**
     * 주문 배송 레코드를 업데이트합니다.
     *
     * @param  int  $id  주문 배송 ID
     * @param  array  $data  업데이트 데이터
     * @return bool 성공 여부
     */
    public function update(int $id, array $data): bool;

    /**
     * 주문 옵션 ID로 배송 레코드를 삭제합니다.
     *
     * @param  int  $orderOptionId  주문 옵션 ID
     * @return int 삭제된 레코드 수
     */
    public function deleteByOrderOptionId(int $orderOptionId): int;

    /**
     * 캐리어 ID로 사용 중인 배송 레코드 수를 조회합니다.
     *
     * @param  int  $carrierId  캐리어 ID
     * @return int 사용 중인 레코드 수
     */
    public function countByCarrierId(int $carrierId): int;

    /**
     * 주문 옵션 ID에 해당하는 배송 레코드의 소유권을 이전합니다.
     *
     * @param  int  $fromOrderOptionId  기존 주문 옵션 ID
     * @param  int  $toOrderOptionId  이전 대상 주문 옵션 ID
     * @return int 업데이트된 레코드 수
     */
    public function transferByOrderOptionId(int $fromOrderOptionId, int $toOrderOptionId): int;

    /**
     * 배송유형 코드로 사용 중인 배송 레코드 수를 조회합니다.
     *
     * @param  string  $shippingType  배송유형 코드
     * @return int 사용 중인 레코드 수
     */
    public function countByShippingType(string $shippingType): int;
}
