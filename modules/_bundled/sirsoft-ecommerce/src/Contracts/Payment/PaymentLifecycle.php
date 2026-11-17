<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment;

use Closure;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\MetaEncoding;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\OrderRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentCompletion;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentFailure;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentOutcome;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\RetryRestoreRequest;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\RetryRestoreResult;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\VbankIssuance;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\WindowClosure;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\ChargeNotResolvable;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\InvalidPaymentTransition;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\PaymentNotFound;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\ProviderMismatch;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;

/**
 * 결제 상태 쓰기 공개 계약 (결제사 플러그인용)
 *
 * 공통 규칙:
 * - 주문이 없으면 PaymentNotFound.
 * - 결제 행이 아직 결제 전(대기, 진행 중, 실패, 결제 흔적 없는 취소)이면 결제사 id 가 달라도 진행하고,
 *   `takeOverProvider` 를 켠 호출만 결제사 id 를 호출 결제사로 기록한다. 결제(발급) 뒤의 행이 다른 결제사 것이면 ProviderMismatch.
 * - 잠금 순서는 주문 → 결제 행이다.
 * - 판정 클로저(`$guard`)를 넘기면 잠금 안 판정과 쓰기가 한 트랜잭션이 된다. 넘기지 않으면 기존 처리 단위를 그대로 따른다
 *   (결제 완료 훅이 커밋 뒤에 발화하는 현행 동작 보존).
 *
 * @since 1.4.0
 */
interface PaymentLifecycle
{
    /** 계약 판 — 메서드 추가는 판을 올리지 않는다(구현은 이커머스만) */
    public const VERSION = 1;

    /**
     * 결제를 완료 처리합니다(주문 결제완료 전이, 금액 검증, 재고, 마일리지, 결제완료 훅).
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  PaymentCompletion  $completion  결제사 응답 값
     * @param  int|null  $pgAmount  결제사 승인 금액(최소 화폐 단위, null 이면 금액 검증 생략)
     * @param  Closure|null  $guard  Closure(OrderRecord $locked, ?PaymentRecord $locked): ?string — 문자열을 돌려주면 그 사유로 처리하지 않음
     * @return PaymentOutcome 결과(이미 결제완료나 취소된 주문이면 applied=false, reason=ignored)
     *
     * @throws PaymentAmountMismatchException 금액이 맞지 않을 때
     * @throws ChargeNotResolvable 결제 통화 환율이 없을 때
     * @throws PaymentNotFound|ProviderMismatch 공통 규칙
     */
    public function completePayment(string $orderNumber, string $pg, PaymentCompletion $completion, ?int $pgAmount = null, ?Closure $guard = null): PaymentOutcome;

    /**
     * 결제 실패를 처리합니다(결제 전 주문만 취소 상태로, 선차감 마일리지 복원, 주문 상품 상태 동기화, 결제 실패 훅).
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  string  $errorCode  실패 코드
     * @param  string  $errorMessage  실패 메시지
     * @return OrderRecord 처리 뒤 주문
     *
     * @throws PaymentNotFound|ProviderMismatch 공통 규칙
     */
    public function failPayment(string $orderNumber, string $pg, string $errorCode, string $errorMessage): OrderRecord;

    /**
     * 결제 행을 취소 상태로 바꾸고 취소 이력을 남깁니다(주문 상태는 바꾸지 않음).
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  string|null  $cancelCode  취소 코드
     * @param  string|null  $cancelMessage  취소 메시지
     * @return OrderRecord 처리 뒤 주문
     *
     * @throws PaymentNotFound|ProviderMismatch 공통 규칙
     */
    public function recordPaymentCancellation(string $orderNumber, string $pg, ?string $cancelCode = null, ?string $cancelMessage = null): OrderRecord;

    /**
     * 결제 행에 실패 상태와 결제사가 만든 실패 meta 를 기록합니다(결제 행이 없으면 아무것도 하지 않음).
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  PaymentFailure  $failure  실패 기록
     * @return OrderRecord 처리 뒤 주문
     *
     * @throws \InvalidArgumentException 상태가 FAILED, CANCELLED 가 아닐 때
     * @throws PaymentNotFound|ProviderMismatch 공통 규칙
     */
    public function recordPaymentFailure(string $orderNumber, string $pg, PaymentFailure $failure): OrderRecord;

    /**
     * 결제창 닫힘을 기록합니다(결제 실패 처리 → 결제 취소 이력 → 결제 실패 기록).
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  WindowClosure  $closure  닫힘 정보
     * @param  Closure|null  $guard  Closure(OrderRecord, ?PaymentRecord): ?string — 잠금 안 판정
     * @return PaymentOutcome 결과(결제 전 주문이 아니면 applied=false, reason=order_not_payable)
     *
     * @throws PaymentNotFound|ProviderMismatch 공통 규칙
     */
    public function markPaymentWindowClosed(string $orderNumber, string $pg, WindowClosure $closure, ?Closure $guard = null): PaymentOutcome;

    /**
     * 가상계좌(편의점 결제) 발급을 기록합니다(입금 대기 전이, 발급 정보 저장, 선택적으로 장바구니 정리).
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  VbankIssuance  $issuance  발급 정보
     * @param  Closure|null  $guard  Closure(OrderRecord, ?PaymentRecord): ?string — 잠금 안 판정
     * @return PaymentOutcome 결과(결제 행이 없으면 applied=false, reason=payment_not_found)
     *
     * @throws PaymentNotFound|ProviderMismatch 공통 규칙
     */
    public function onVbankAccountIssued(string $orderNumber, string $pg, VbankIssuance $issuance, ?Closure $guard = null): PaymentOutcome;

    /**
     * 입금 기한이 지난 결제 행을 기한 만료 상태로 바꿉니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  Closure  $guard  Closure(PaymentRecord $locked): bool — 거짓이면 바꾸지 않음
     * @param  Closure  $meta  Closure(array $currentMeta): array — 새 결제 meta 전체
     * @return bool 바꿨으면 true
     *
     * @throws PaymentNotFound|ProviderMismatch 공통 규칙
     */
    public function expireAwaitingDeposit(string $orderNumber, string $pg, Closure $guard, Closure $meta): bool;

    /**
     * 취소된 주문에 들어온 입금을 기록합니다(같은 거래 재전송이면 false, 새 기록이면 입금 후 취소 훅 발화).
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  float  $amount  입금액
     * @param  string|null  $transactionId  거래번호
     * @return bool 새로 기록했으면 true
     *
     * @throws PaymentNotFound|ProviderMismatch 공통 규칙
     */
    public function recordDepositAfterCancel(string $orderNumber, string $pg, float $amount, ?string $transactionId = null): bool;

    /**
     * 결제사가 이미 환불한 가상계좌 주문을 주문 취소로 확정합니다(결제사 취소는 다시 호출하지 않음).
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  string  $reason  취소 사유
     * @param  int|null  $cancelledBy  처리자 회원 id
     * @return OrderRecord 처리 뒤 주문
     *
     * @throws PaymentNotFound|ProviderMismatch 공통 규칙
     */
    public function cancelOrderForVbankRefund(string $orderNumber, string $pg, string $reason, ?int $cancelledBy = null): OrderRecord;

    /**
     * 결제사가 재시도 가능하다고 판정한 실패 주문을 결제 대기로 되돌립니다.
     *
     * 주문은 결제 취소에서 주문 대기로, 결제 행은 대기로, 결제 실패 처리가 취소로 바꾼 주문 상품은 주문 대기로 돌아간다.
     * 상태 전이 훅과 활동 로그가 남는다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  RetryRestoreRequest  $request  복구 요청
     * @return RetryRestoreResult 결과
     *
     * @throws InvalidPaymentTransition 결제 완료 이력이나 부분 취소 이력이 있을 때
     * @throws \InvalidArgumentException 요청의 metaPrefix 가 결제사 id 와 다를 때
     * @throws PaymentNotFound|ProviderMismatch 공통 규칙
     */
    public function restoreForRetry(string $orderNumber, string $pg, RetryRestoreRequest $request): RetryRestoreResult;

    /**
     * 결제사 부가 정보 컬럼(허용 목록만)과 meta 병합을 한 번에 기록합니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  array<string, mixed>  $attributes  컬럼 => 값(transaction_id, merchant_order_id, embedded_pg_provider, payment_device, card_*, is_interest_free, vbank_*, dbank_*, receipt_url, buyer_*, cash_receipt_*)
     * @param  array<string, mixed>  $metaPatch  결제 meta 에 병합할 값
     * @return PaymentRecord 처리 뒤 결제 행
     *
     * @throws \InvalidArgumentException 허용 목록 밖 컬럼이 있을 때
     * @throws PaymentNotFound|ProviderMismatch 공통 규칙(결제 행이 없어도 PaymentNotFound)
     */
    public function recordPgAttributes(string $orderNumber, string $pg, array $attributes, array $metaPatch = []): PaymentRecord;

    /**
     * 잠금 아래 결제 meta 에 값을 병합합니다(최상위 키 단위 array_merge).
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  array<string, mixed>  $patch  병합할 값
     * @param  MetaEncoding  $encoding  저장 인코딩
     * @return array<string, mixed> 저장된 meta
     *
     * @throws PaymentNotFound|ProviderMismatch 공통 규칙(결제 행이 없어도 PaymentNotFound)
     */
    public function mergePaymentMeta(string $orderNumber, string $pg, array $patch, MetaEncoding $encoding = MetaEncoding::Eloquent): array;

    /**
     * 잠금 아래 결제 meta 를 읽고 고쳐 씁니다(누적, 이력 덧붙임, 중복 차단 판정용).
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  Closure  $mutator  Closure(array $meta, PaymentRecord $payment, OrderRecord $order): ?array — null 이면 저장하지 않음
     * @param  MetaEncoding  $encoding  저장 인코딩
     * @return array<string, mixed>|null 저장된 meta(저장하지 않았으면 null)
     *
     * @throws PaymentNotFound|ProviderMismatch 공통 규칙(결제 행이 없어도 PaymentNotFound)
     */
    public function mutatePaymentMetaWithLock(string $orderNumber, string $pg, Closure $mutator, MetaEncoding $encoding = MetaEncoding::Eloquent): ?array;

    /**
     * 주문의 자기 결제 행 meta 한 구역에서 지정 키 값을 비웁니다(개인정보 익명화용, 수정 시각 유지).
     *
     * @param  int  $orderId  주문 id(익명화 훅이 주는 값)
     * @param  string  $pg  결제사 id
     * @param  string  $section  meta 최상위 키
     * @param  array<int, string>  $keys  비울 하위 키
     * @return int 바꾼 결제 행 수
     */
    public function scrubPaymentMetaKeys(int $orderId, string $pg, string $section, array $keys): int;

    /**
     * 잠금 아래 자기 namespace 의 주문 meta 를 읽고 고쳐 씁니다.
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  string  $namespace  meta 키(`{$pg}_` 로 시작해야 한다)
     * @param  Closure  $mutator  Closure(?array $existing): ?array — null 이면 저장하지 않음
     * @return array<string, mixed>|null 저장된 값(주문이 없거나 저장하지 않았으면 null)
     *
     * @throws \InvalidArgumentException namespace 가 자기 접두로 시작하지 않을 때
     */
    public function mutateOrderMetaWithLock(string $orderNumber, string $pg, string $namespace, Closure $mutator): ?array;
}
