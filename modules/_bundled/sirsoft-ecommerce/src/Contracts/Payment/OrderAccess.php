<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment;

use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\BuyerContact;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\OrderRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\ShippingAddress;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * 주문 조회, 비회원 토큰, 권한 범위 판정 공개 계약 (결제사 플러그인용)
 *
 * @since 1.4.0
 */
interface OrderAccess
{
    /** 계약 판 — 메서드 추가는 판을 올리지 않는다(구현은 이커머스만) */
    public const VERSION = 1;

    /**
     * 주문번호로 주문을 찾습니다. 결제 행은 자기 결제사 행이거나 아직 결제 전인 행일 때만 싣는다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  string|array<int, string>  $pg  결제사 id
     * @return OrderRecord|null 없으면 null
     */
    public function findOrderByNumber(string $orderNumber, string|array $pg): ?OrderRecord;

    /**
     * 주문 Model 을 찾습니다 — 공개 훅 인자, 공개 Support 클래스 인자, 활동 로그 대상 호환용이며 읽기 전용이다.
     *
     * 이 Model 로 쓰기나 조회(save, update, fresh, load, 관계 빌더 등)를 하지 않는다. 일반 조회는 findOrderByNumber() 를 쓴다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  string|array<int, string>  $pg  결제사 id
     * @return Order|null 없으면 null
     */
    public function findOrderModel(string $orderNumber, string|array $pg): ?Order;

    /**
     * 주문 배송지 행의 주문자와 수령인 정보를 돌려줍니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return ShippingAddress|null 없으면 null
     */
    public function getShippingAddress(string $orderNumber): ?ShippingAddress;

    /**
     * 주문 구매자 연락처를 돌려줍니다 — 칸마다 결제 행 구매자 값, 비어 있으면 배송지 주문자 값.
     *
     * 결제사 범위를 걸지 않는다. 현금영수증 발급처럼 결제사와 무관하게 주문 구매자에게 하는 일에 쓴다.
     *
     * @param  string  $orderNumber  주문번호
     * @return BuyerContact|null 주문이 없으면 null
     */
    public function findBuyerContact(string $orderNumber): ?BuyerContact;

    /**
     * 자기 namespace 의 주문 meta 를 잠금 없이 읽습니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  string  $namespace  meta 키(`{$pg}_` 로 시작해야 한다)
     * @return array<string, mixed>|null 값이 배열이 아니거나 주문이 없으면 null
     *
     * @throws \InvalidArgumentException namespace 가 자기 접두로 시작하지 않을 때
     */
    public function getOrderMeta(string $orderNumber, string $pg, string $namespace): ?array;

    /**
     * 비회원 조회 토큰이 이 주문번호의 비회원 주문에 맞는지 확인합니다.
     *
     * @param  string|null  $token  `X-Guest-Order-Token` 값
     * @param  string  $orderNumber  주문번호
     * @return bool 맞으면 true
     */
    public function verifyGuestOrderToken(?string $token, string $orderNumber): bool;

    /**
     * 비회원 주문의 조회 토큰을 발급합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return array<string, mixed>|null 토큰 정보(회원 주문이거나 없는 주문이면 null)
     */
    public function issueGuestOrderToken(string $orderNumber): ?array;

    /**
     * 현재 사용자가 권한 범위 안에서 이 주문을 다룰 수 있는지 판정합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $permission  권한 식별자
     * @return bool|null 범위 안이면 true, 밖이면 false, 주문이 없으면 null(판정하지 않음)
     */
    public function isOrderWithinScope(string $orderNumber, string $permission): ?bool;

    /**
     * 거래번호의 주문을 현재 사용자가 권한 범위 안에서 다룰 수 있는지 판정합니다.
     *
     * @param  string|array<int, string>  $pg  결제사 id
     * @param  string  $transactionId  거래번호
     * @param  string  $permission  권한 식별자
     * @return bool 범위가 「전체」거나 범위 안이면 true, 주문이 없으면 false
     */
    public function isTransactionWithinScope(string|array $pg, string $transactionId, string $permission): bool;

    /**
     * 주문번호 목록에서 권한 범위 안의 것만 돌려줍니다.
     *
     * @param  array<int, string>  $orderNumbers  주문번호 목록
     * @param  string  $permission  권한 식별자
     * @return array<int, string> 범위 안 주문번호(범위 「전체」면 입력 그대로)
     */
    public function orderNumbersWithinScope(array $orderNumbers, string $permission): array;
}
