<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;

/**
 * 결제사 플러그인 공개 계약(PaymentGatewayBridge) 구현 전용 저장소
 *
 * 결제 행 조회 조건(criteria) 키:
 * - order_number(string), order_id(int), transaction_id(string)
 * - escrow(bool), has_transaction_id(bool, null 아님), non_empty_transaction_id(bool, 빈 문자열도 제외)
 * - status(string 결제 상태 값), owner_user_id(int 주문 회원), guest_only(bool 비회원 주문만), order_key(int 주문 id 일치)
 * - created_since(DateTimeInterface 결제 행 생성 하한), require_meta(bool 결제 meta 있음)
 *
 * 결제 행 조회 결과는 주문번호를 `joined_order_number` 속성으로 함께 싣는다(삭제된 주문 행 포함 — 결제사 기존 조인 조회와 같다).
 */
interface PaymentGatewayRepositoryInterface
{
    /**
     * 결제사 범위에서 거래번호가 결제 완료 행에 쓰였는지 확인합니다.
     *
     * @param  array<int, string>  $providers  결제사 id 목록
     * @param  string  $transactionId  거래번호
     * @return bool 결제 완료 행이 있으면 true
     */
    public function isTransactionPaid(array $providers, string $transactionId): bool;

    /**
     * 조건에 맞는 첫 결제 행을 찾습니다.
     *
     * @param  array<int, string>|null  $providers  결제사 id 목록(null 이면 결제사 조건 없음)
     * @param  array<string, mixed>  $criteria  조건
     * @return OrderPayment|null 없으면 null
     */
    public function firstPayment(?array $providers, array $criteria): ?OrderPayment;

    /**
     * 조건에 맞는 결제 행 목록을 돌려줍니다.
     *
     * @param  array<int, string>|null  $providers  결제사 id 목록(null 이면 결제사 조건 없음)
     * @param  array<string, mixed>  $criteria  조건
     * @return Collection<int, OrderPayment> 결제 행 목록
     */
    public function listPayments(?array $providers, array $criteria): Collection;

    /**
     * 조건에 맞는 결제 행을 주문번호(`joined_order_number`), 거래번호, 결제 meta 세 칸만 골라 돌려줍니다.
     *
     * @param  array<int, string>|null  $providers  결제사 id 목록(null 이면 결제사 조건 없음)
     * @param  array<string, mixed>  $criteria  조건(listPayments() 와 같다)
     * @return Collection<int, OrderPayment> 세 칸만 채운 결제 행 목록
     */
    public function listPaymentBriefs(?array $providers, array $criteria): Collection;

    /**
     * 주문의 가장 최근 환불을 찾습니다.
     *
     * @param  int  $orderId  주문 id
     * @return OrderRefund|null 없으면 null
     */
    public function latestRefundForOrder(int $orderId): ?OrderRefund;

    /**
     * 주문번호로 주문을 찾습니다(관계 적재 없음).
     *
     * @param  string  $orderNumber  주문번호
     * @param  array<int, string>  $columns  읽을 컬럼
     * @return Order|null 없으면 null
     */
    public function findOrderByNumber(string $orderNumber, array $columns = ['*']): ?Order;

    /**
     * 주문 id 의 주문번호를 읽습니다.
     *
     * @param  int  $orderId  주문 id
     * @return string|null 주문이 없으면 null
     */
    public function orderNumberById(int $orderId): ?string;

    /**
     * 주문에 취소되지 않은 주문 상품이 있는지 판정합니다.
     *
     * @param  int  $orderId  주문 id
     * @return bool 있으면 true
     */
    public function hasActiveOrderOptions(int $orderId): bool;

    /**
     * 결제사 범위 거래번호의 주문을 찾습니다.
     *
     * @param  array<int, string>  $providers  결제사 id 목록
     * @param  string  $transactionId  거래번호
     * @return Order|null 없으면 null
     */
    public function findOrderByProviderTransaction(array $providers, string $transactionId): ?Order;

    /**
     * 주문번호 목록 가운데 권한 범위 안의 것만 돌려줍니다.
     *
     * @param  array<int, string>  $orderNumbers  주문번호 목록
     * @param  string  $permission  권한 식별자
     * @return array<int, string> 범위 안 주문번호
     */
    public function filterOrderNumbersByScope(array $orderNumbers, string $permission): array;

    /**
     * 결제 행을 모델 저장(변경분만, 모델 이벤트 발생)으로 갱신합니다.
     *
     * @param  OrderPayment  $payment  결제 행
     * @param  array<string, mixed>  $attributes  컬럼 => 값
     * @return void 반환값 없음
     */
    public function updatePayment(OrderPayment $payment, array $attributes): void;

    /**
     * 결제 행 속성을 그대로 채워 저장합니다(null 도 기록).
     *
     * @param  OrderPayment  $payment  결제 행
     * @param  array<string, mixed>  $attributes  컬럼 => 값
     * @return void 반환값 없음
     */
    public function forceSavePayment(OrderPayment $payment, array $attributes): void;

    /**
     * 결제 행을 질의 갱신(모델 이벤트 없음, 배열은 모델 캐스트와 같은 JSON)으로 바꿉니다 — 관계 빌더 갱신과 같은 경로.
     *
     * @param  int  $paymentId  결제 행 id
     * @param  array<string, mixed>  $attributes  컬럼 => 값
     * @return void 반환값 없음
     */
    public function updatePaymentColumns(int $paymentId, array $attributes): void;

    /**
     * 결제 meta 를 유니코드 비이스케이프 JSON 원문으로 씁니다.
     *
     * @param  int  $paymentId  결제 행 id
     * @param  array<string, mixed>  $meta  meta
     * @param  bool  $touch  수정 시각을 갱신할지
     * @return void 반환값 없음
     */
    public function writeUnescapedPaymentMeta(int $paymentId, array $meta, bool $touch): void;

    /**
     * 주문의 결제사 결제 행들을 잠그고 돌려줍니다.
     *
     * @param  int  $orderId  주문 id
     * @param  string  $provider  결제사 id
     * @return Collection<int, OrderPayment> 결제 행 목록
     */
    public function lockPaymentsForProvider(int $orderId, string $provider): Collection;

    /**
     * 주문번호로 주문을 잠그고 meta 와 함께 돌려줍니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return Order|null 없으면 null
     */
    public function lockOrderMetaByNumber(string $orderNumber): ?Order;

    /**
     * 주문 meta 를 저장하고 수정 시각을 갱신합니다.
     *
     * @param  Order  $order  주문
     * @param  array<string, mixed>  $meta  주문 meta 전체
     * @return void 반환값 없음
     */
    public function saveOrderMeta(Order $order, array $meta): void;

    /**
     * 샘플 주문을 주문번호로 찾고 없으면 팩토리로 만듭니다(개발 환경 전용).
     *
     * @param  string  $orderNumber  주문번호
     * @param  User  $owner  소유 회원
     * @return Order 주문
     */
    public function firstOrCreateSampleOrder(string $orderNumber, User $owner): Order;

    /**
     * 주문에 결제사 샘플 결제 행이 없으면 팩토리로 만듭니다(개발 환경 전용).
     *
     * @param  Order  $order  주문
     * @param  string  $provider  결제사 id
     * @param  array<string, mixed>  $attributes  결제 행 값
     * @return void 반환값 없음
     */
    public function ensureSamplePayment(Order $order, string $provider, array $attributes): void;

    /**
     * 주문에 배송지 행이 없으면 팩토리로 만듭니다(개발 환경 전용).
     *
     * @param  Order  $order  주문
     * @param  array<string, mixed>  $attributes  배송지 값
     * @return void 반환값 없음
     */
    public function ensureSampleShippingAddress(Order $order, array $attributes): void;
}
