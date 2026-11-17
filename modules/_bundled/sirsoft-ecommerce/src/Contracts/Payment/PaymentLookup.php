<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment;

use DateTimeInterface;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\ChargeAmount;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentBrief;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentFilters;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\ReceiptRequester;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\RefundRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\TaxSplit;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\ChargeNotResolvable;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\PaymentNotFound;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;

/**
 * 결제 행 조회 공개 계약 (결제사 플러그인용)
 *
 * `$pg` 는 호출 결제사 id 다. 목록으로 넘기면 첫 값이 자기 id, 나머지는 자기 옛 id 다. 결제 행 조회는 그 결제사 행만 돌려준다.
 *
 * @since 1.4.0
 */
interface PaymentLookup
{
    /** 계약 판 — 메서드 추가는 판을 올리지 않는다(구현은 이커머스만) */
    public const VERSION = 1;

    /**
     * 거래번호가 이미 결제 완료된 자기 결제 행에 쓰였는지 확인합니다(콜백 재전송 방지).
     *
     * @param  string|array<int, string>  $pg  결제사 id(목록이면 옛 id 포함)
     * @param  string|null  $transactionId  거래번호(비었으면 false)
     * @return bool 결제 완료 행이 있으면 true
     */
    public function isTransactionPaid(string|array $pg, ?string $transactionId): bool;

    /**
     * 거래번호로 자기 결제 행을 찾습니다.
     *
     * @param  string|array<int, string>  $pg  결제사 id
     * @param  string  $transactionId  거래번호
     * @param  PaymentFilters|null  $filters  추가 조건
     * @return PaymentRecord|null 없으면 null
     */
    public function findPaymentByTransactionId(string|array $pg, string $transactionId, ?PaymentFilters $filters = null): ?PaymentRecord;

    /**
     * 주문번호로 결제 행을 찾습니다. 거래번호 조건이 없으면 아직 결제 전인 행(다른 결제사 id 로 만들어진 행 포함)도 돌려준다.
     *
     * @param  string|array<int, string>  $pg  결제사 id
     * @param  string  $orderNumber  주문번호
     * @param  PaymentFilters|null  $filters  추가 조건
     * @return PaymentRecord|null 없으면 null
     */
    public function findPaymentByOrderNumber(string|array $pg, string $orderNumber, ?PaymentFilters $filters = null): ?PaymentRecord;

    /**
     * 주문의 에스크로 결제 행 목록을 돌려줍니다.
     *
     * @param  string|array<int, string>  $pg  결제사 id
     * @param  string  $orderNumber  주문번호
     * @return array<int, PaymentRecord> 결제 행 목록
     */
    public function listEscrowPayments(string|array $pg, string $orderNumber): array;

    /**
     * 기준 시각 이후 만들어진 자기 결제 행 목록을 돌려줍니다(주문번호 포함, 건수 제한 없음).
     *
     * @param  string|array<int, string>  $pg  결제사 id
     * @param  DateTimeInterface  $since  결제 행 생성 시각 하한
     * @param  bool  $requireMeta  true 면 결제 meta 가 있는 행만
     * @return array<int, PaymentRecord> 결제 행 목록
     */
    public function listPaymentsByProvider(string|array $pg, DateTimeInterface $since, bool $requireMeta = false): array;

    /**
     * 기준 시각 이후 만들어진 자기 결제 행을 주문번호, 거래번호, 결제 meta 세 칸으로만 돌려줍니다(건수 제한 없음).
     *
     * 범위와 조건은 listPaymentsByProvider() 와 같다. 결제 행 전체 칸이 필요 없는 목록 소비자(테스트 결제 배지 등)는 이 조회를 쓴다 —
     * 결제 행 전체를 Model 과 DTO 로 올리지 않아 결제 행이 많아도 메모리가 칸 수만큼만 는다.
     *
     * @param  string|array<int, string>  $pg  결제사 id
     * @param  DateTimeInterface  $since  결제 행 생성 시각 하한
     * @param  bool  $requireMeta  true 면 결제 meta 가 있는 행만
     * @return array<int, PaymentBrief> 결제 행 요약 목록
     */
    public function listPaymentBriefsByProvider(string|array $pg, DateTimeInterface $since, bool $requireMeta = false): array;

    /**
     * 영수증을 보여 줄 결제 행을 요청자 소유 조건으로 찾습니다.
     *
     * @param  string|array<int, string>  $pg  결제사 id
     * @param  string  $orderNumber  주문번호
     * @param  ReceiptRequester  $requester  요청자(회원, 비회원 토큰, 결제사가 확인한 비회원)
     * @param  PaymentFilters|null  $filters  추가 조건
     * @return PaymentRecord|null 없거나 소유 조건을 못 넘으면 null
     */
    public function findReceiptPayment(string|array $pg, string $orderNumber, ReceiptRequester $requester, ?PaymentFilters $filters = null): ?PaymentRecord;

    /**
     * 주문의 가장 최근 환불을 돌려줍니다(주문 결제 행이 자기 결제사일 때만).
     *
     * @param  string|array<int, string>  $pg  결제사 id
     * @param  string  $orderNumber  주문번호
     * @return RefundRecord|null 없으면 null
     */
    public function latestRefund(string|array $pg, string $orderNumber): ?RefundRecord;

    /**
     * 주문 결제 통화로 청구액을 계산합니다. 기준액을 주면 그 금액을 같은 통화 스냅샷으로 환산한다.
     *
     * @param  Order|string  $order  주문(훅으로 받은 주문 또는 주문번호)
     * @param  float|null  $baseAmount  기준 통화 금액(null 이면 결제 예정액)
     * @return ChargeAmount 청구액
     *
     * @throws ChargeNotResolvable 결제 통화 환율이 없을 때
     * @throws PaymentNotFound 주문번호로 주문을 찾지 못했을 때
     */
    public function resolveChargeAmount(Order|string $order, ?float $baseAmount = null): ChargeAmount;

    /**
     * 결제사에 넘길 과세 구분 금액을 돌려줍니다(기준 통화). 주문을 넘기면 그 값으로 계산한다(조회 없음).
     *
     * @param  Order|string  $order  주문(훅으로 받은 주문 또는 주문번호)
     * @return TaxSplit 면세, 과세, 부가세
     *
     * @throws PaymentNotFound 주문번호로 주문을 찾지 못했을 때
     */
    public function pgTaxSplit(Order|string $order): TaxSplit;

    /**
     * 훅으로 받은 결제 행을 DTO 로 바꿉니다(조회 없음). 판정 값(가상계좌 여부, 취소 가능액 등)은 이 결과로 읽는다.
     *
     * @param  OrderPayment  $payment  훅 인자 결제 행
     * @return PaymentRecord DTO
     */
    public function describePayment(OrderPayment $payment): PaymentRecord;

    /**
     * 쇼핑몰 기본 결제사 id 를 돌려줍니다.
     *
     * @return string|null 설정이 없으면 null
     */
    public function defaultPgProvider(): ?string;
}
