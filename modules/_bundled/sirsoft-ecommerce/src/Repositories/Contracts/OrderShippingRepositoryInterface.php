<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

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

    /**
     * 주문 옵션의 기존 배송 레코드에 택배사·송장번호를 기록합니다.
     *
     * 배송 관련 상태 전이(배송준비완료/배송중/배송완료) 시 호출되어, 해당 옵션의 배송 레코드에
     * 택배사·송장번호를 반영합니다. 배송 레코드는 주문 생성 시점에 옵션 단위로 만들어지므로
     * 여기서는 존재하는 레코드만 갱신하며(생성하지 않음 — shipping_type/shipping_status 등
     * 정책 파생 컬럼을 임의로 정할 수 없음), 레코드가 없으면 갱신을 건너뛰고 null 을 반환한다.
     *
     * @param  int  $orderOptionId  주문 옵션 ID
     * @param  array  $tracking  ['carrier_id' => int, 'tracking_number' => string] 부분 배열
     * @return OrderShipping|null 갱신된 배송 레코드 (레코드가 없으면 null)
     */
    public function updateTrackingByOrderOptionId(int $orderOptionId, array $tracking): ?OrderShipping;
}
