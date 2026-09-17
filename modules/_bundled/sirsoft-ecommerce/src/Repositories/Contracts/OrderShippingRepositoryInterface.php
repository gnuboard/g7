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
     * 배송 관련 상태 전이(배송준비중/배송중/배송완료) 시 호출되어, 해당 옵션의 배송 레코드에
     * 택배사·송장번호를 반영합니다. 배송 레코드는 주문 생성 시점에 옵션 단위로 만들어지므로
     * 여기서는 존재하는 레코드만 갱신하며(생성하지 않음 — shipping_type/shipping_status 등
     * 정책 파생 컬럼을 임의로 정할 수 없음), 레코드가 없으면 갱신을 건너뛰고 null 을 반환한다.
     *
     * 배송 진행 상태(`shipping_status`)와 발생 시각(`shipped_at`/`delivered_at`)을 함께 넘기면
     * 그것도 반영한다 — 회원 주문상세 「배송 현황」 카드가 이 레코드를 읽으므로, 송장만 쓰고
     * 상태를 두면 배송완료 주문이 고객에게 「배송대기」로 보인다. 시각은 **비어 있을 때만**
     * 기록해 같은 단계를 다시 지나도 최초 시각을 덮지 않는다. 송장만 정정하는 호출은 이 키들을
     * 넘기지 않으므로 상태가 보존된다.
     *
     * @param  int  $orderOptionId  주문 옵션 ID
     * @param  array  $tracking  ['carrier_id' => int, 'tracking_number' => string,
     *                           'shipping_status' => string, 'shipped_at' => mixed,
     *                           'delivered_at' => mixed] 부분 배열
     * @return OrderShipping|null 갱신된 배송 레코드 (레코드가 없으면 null)
     */
    public function updateTrackingByOrderOptionId(int $orderOptionId, array $tracking): ?OrderShipping;

    /**
     * 주문 옵션의 첫 배송 레코드를 조회합니다.
     *
     * @param  int  $orderOptionId  주문 옵션 ID
     * @return OrderShipping|null 배송 레코드 (없으면 null)
     */
    public function findFirstByOrderOptionId(int $orderOptionId): ?OrderShipping;

    /**
     * 기존 배송 레코드를 다른 주문 옵션용으로 복제합니다.
     *
     * 교환 재발송으로 생성된 후속 주문옵션이 원 옵션의 배송정책·국가 설정을 그대로 승계하기 위한
     * 경로다 — 배송 레코드의 정책 파생 컬럼(shipping_type/정책 스냅샷/금액)은 임의로 만들 수 없고
     * 원 레코드를 복사하는 것이 유일한 출처다. 시각 컬럼(shipped_at/estimated_arrival_at/
     * delivered_at/confirmed_at)은 복사하지 않는다.
     *
     * @param  OrderShipping  $source  원 배송 레코드
     * @param  int  $orderOptionId  후속 주문 옵션 ID
     * @param  array<string, mixed>  $overrides  덮어쓸 컬럼 (shipping_status / carrier_id / tracking_number / shipped_at 등)
     * @return OrderShipping 생성된 배송 레코드
     */
    public function replicateForOrderOption(OrderShipping $source, int $orderOptionId, array $overrides = []): OrderShipping;
}
