<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;

/**
 * 클레임(교환/반품) 라이프사이클 예외
 *
 * 신청 자격·수량 잠금·사유·청구 방식·상태 전이·완료 처리에서 발생하는 도메인 실패를
 * 표현합니다. 교환과 반품이 같은 실패 모드를 공유하므로 유형별로 클래스를 나누지 않고
 * 클레임 유형을 치환 파라미터(:type)로 받는다 — 한쪽만 고쳐지는 형제 불균형을 막는다.
 *
 * 컨트롤러는 예외 메시지 원문(이미 번역된 문자열)을 응답 메시지 **키** 자리에 넘기면 안 되며,
 * getMessageKey() / getMessageParams() 로 응답을 구성한다.
 */
class OrderClaimException extends Exception
{
    /**
     * @param  string  $messageKey  다국어 메시지 키 (sirsoft-ecommerce::exceptions.*)
     * @param  array<string, mixed>  $replace  메시지 치환 파라미터
     */
    private function __construct(
        private string $messageKey,
        private array $replace = []
    ) {
        parent::__construct(trans_count($messageKey, $replace));
    }

    /**
     * 신청 대상 상품이 하나도 없을 때
     *
     * @return self 예외 인스턴스
     */
    public static function itemsRequired(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_items_required');
    }

    /**
     * 신청 대상 주문 옵션을 찾을 수 없을 때
     *
     * @return self 예외 인스턴스
     */
    public static function optionNotFound(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_option_not_found');
    }

    /**
     * 주문 옵션 상태가 신청 가능 상태 목록에 없을 때
     *
     * @param  ClaimTypeEnum  $claimType  클레임 유형
     * @param  string  $statusLabel  현재 상품 상태 라벨
     * @return self 예외 인스턴스
     */
    public static function optionNotClaimable(ClaimTypeEnum $claimType, string $statusLabel): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_option_not_claimable', [
            'type' => $claimType->label(),
            'status' => $statusLabel,
        ]);
    }

    /**
     * 신청 수량이 유효 범위를 벗어났을 때
     *
     * @return self 예외 인스턴스
     */
    public static function quantityInvalid(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_quantity_invalid');
    }

    /**
     * 신청 상품 행을 수량으로 나눌 수 없을 때 (상품·수량 단위 전이)
     *
     * 두 경우다. ⓐ 교환이고 후속 주문옵션(`new_order_option_id`)이 이미 만들어진 행 —
     * 그 옵션은 `quantity = exchange_quantity` 로 1:1 산출된 것이라, 두 조각이 같은 후속
     * 옵션을 가리키면 **재발송 수량이 오류 없이 어긋난다.** ⓑ 이미 종결된 행 — 나눌 수량이
     * 더는 움직이지 않으므로 조각만 늘어난다.
     *
     * @return self 예외 인스턴스
     */
    public static function claimItemNotSplittable(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_item_not_splittable');
    }

    /**
     * 진행 중인 다른 클레임이 그 수량을 이미 잡고 있을 때 (D15 수량 잠금)
     *
     * @param  int  $requested  요청 수량
     * @param  int  $available  신청 가능 잔여 수량
     * @return self 예외 인스턴스
     */
    public static function quantityLocked(int $requested, int $available): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_quantity_locked', [
            'requested' => $requested,
            'available' => $available,
            // 「1 item」/「2 items」 를 고르는 수
            'count' => $requested,
        ]);
    }

    /**
     * 사유 코드가 없거나 비활성일 때
     *
     * @return self 예외 인스턴스
     */
    public static function reasonUnavailable(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_reason_unavailable');
    }

    /**
     * 청구액이 있는데 청구 방식이 없을 때
     *
     * @return self 예외 인스턴스
     */
    public static function chargeMethodRequired(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_charge_method_required');
    }

    /**
     * 운영자가 허용하지 않은 청구 방식일 때
     *
     * @return self 예외 인스턴스
     */
    public static function chargeMethodNotAllowed(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_charge_method_not_allowed');
    }

    /**
     * 신청 가능 기간이 지났을 때
     *
     * @param  int  $days  신청 가능 일수
     * @return self 예외 인스턴스
     */
    public static function requestPeriodExpired(int $days): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_request_period_expired', [
            'days' => $days,
            'count' => $days,
        ]);
    }

    /**
     * 허용되지 않은 상태 전이일 때
     *
     * @param  string  $from  현재 상태 라벨
     * @param  string  $to  목표 상태 라벨
     * @return self 예외 인스턴스
     */
    public static function transitionNotAllowed(string $from, string $to): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_transition_not_allowed', [
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * 신청자가 철회할 수 없는 상태일 때
     *
     * @return self 예외 인스턴스
     */
    public static function notWithdrawable(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_not_withdrawable');
    }

    /**
     * 이미 결제한 청구를 환불금 차감 방식으로 바꾸려 할 때 (이중 징수 방지)
     *
     * @return self 예외 인스턴스
     */
    public static function chargeMethodDeductAfterPayment(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_charge_method_deduct_after_payment');
    }

    /**
     * 환불 우선순위 입력이 알 수 없는 값일 때
     *
     * @return self 예외 인스턴스
     */
    public static function refundPriorityInvalid(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_refund_priority_invalid');
    }

    /**
     * 환불 계좌 세 필드(은행·계좌번호·예금주) 중 일부만 입력됐을 때
     *
     * @return self 예외 인스턴스
     */
    public static function refundBankIncomplete(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_refund_bank_incomplete');
    }

    /**
     * 고객이 자기가 올리지 않은 첨부(운영자·주체 미상)를 지우려 할 때
     *
     * @return self 예외 인스턴스
     */
    public static function imageNotRemovable(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_image_not_removable');
    }

    /**
     * 완료 전이를 일반 상태 변경으로 시도했을 때 (D26 — 완료는 완료 처리 경로 전용)
     *
     * @return self 예외 인스턴스
     */
    public static function completionRequiresCompletionFlow(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_completion_flow_required');
    }

    /**
     * 완료 대상 상품이 이 신청서 소속이 아니거나 완료 가능 상태가 아닐 때
     *
     * @return self 예외 인스턴스
     */
    public static function completionItemsInvalid(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_completion_items_invalid');
    }

    /**
     * 이미 환불신청서가 접수된 신청서에 다시 접수를 시도했을 때
     *
     * @param  string  $refundNumber  기존 환불신청서 번호
     * @return self 예외 인스턴스
     */
    public static function refundRequestAlreadyExists(string $refundNumber): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_refund_request_exists', [
            'number' => $refundNumber,
        ]);
    }

    /**
     * 금액 수정값이 유효하지 않을 때 (음수)
     *
     * @return self 예외 인스턴스
     */
    public static function amountInvalid(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_amount_invalid');
    }

    /**
     * 종결된 신청서의 금액 수정을 시도했을 때 (완료·반려·철회 이후)
     *
     * @return self 예외 인스턴스
     */
    public static function amountsNotEditable(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_amounts_not_editable');
    }

    /**
     * 취소 파이프라인이 만든 환불은 이 화면에서 실행·수정할 수 없습니다.
     *
     * **더는 발생시키지 않는다**(#599 후속 §4-5). 취소 발(`refund_source_type=cancel`) 환불도
     * 운영자가 완료 처리할 수 있어야 한다 — 그 행들은 `approved` 에서 멈춰 있고, 막아 두면
     * 완료로 옮길 API 가 저장소 어디에도 없어 영구 정지한다(P1). 팩토리와 문구는 남긴다:
     * 확장이 같은 뜻으로 쓸 수 있는 공개 표면이고, 지우면 그쪽이 조용히 깨진다.
     *
     * @return self 예외
     */
    public static function refundNotExecutable(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_refund_not_executable');
    }

    /**
     * 이미 실행됐거나 실행 중인 환불의 재실행을 시도했을 때 (더블클릭 등 — 409)
     *
     * @return self 예외 인스턴스
     */
    public static function refundAlreadyExecuted(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_refund_already_executed');
    }

    /**
     * 부분 취소·환불로 오히려 **고객이 더 내야 하는** 상황일 때
     *
     * 쿠폰 최소 주문금액처럼 조건이 걸린 할인은 일부 상품이 빠지면 소멸한다. 그러면 남는
     * 상품의 재계산 결제금액이 원 결제금액보다 커져 환불액이 음수가 된다 — 돌려줄 돈이 아니라
     * 받아야 할 돈이다. 청구 수단이 없으므로 접수 자체를 거절한다.
     *
     * 구형 취소 파이프라인에만 있던 가드다(그 경로는 클레임 접수로 일원화되며 사라졌다).
     * 관리자 [주문취소]와 고객 취소·환불 신청이 모두 클레임 접수로 합류한 뒤로는 이 자리에도
     * 있어야 한다 — 없으면 같은 주문이 창구에 따라 거절되기도 하고 통과하기도 한다.
     *
     * @return self 예외 인스턴스
     */
    public static function refundRequiresAdditionalPayment(): self
    {
        return new self('sirsoft-ecommerce::exceptions.cancel_refund_negative');
    }

    /**
     * 이 결제가 **부분취소를 지원하지 않을** 때 (P6)
     *
     * PG 마다 제약이 다르고 어떤 조합은 원천적으로 전액취소만 된다 — NICE 가상계좌는 코어 취소가
     * 항상 거절되고 우회 API 는 전액취소 전용이다. 코어가 미리 막지 않으면 운영자는 수량을 줄여
     * [취소]를 누르고 **원인 없는 PG 거절**을 받는다. 화면은 이미 경고 배너를 띄우지만 화면만
     * 막으면 API 로 들어온 요청이 그대로 통과한다.
     *
     * 판정 근거는 PG 플러그인의 `payment.supports_partial_cancel` 선언이다.
     *
     * @return self 예외 인스턴스
     */
    public static function partialCancelNotSupported(): self
    {
        return new self('sirsoft-ecommerce::exceptions.partial_cancel_not_supported');
    }

    /**
     * 입금 전 가상계좌 주문을 부분취소하려 할 때 (Q17)
     *
     * 발급된 가상계좌의 입금액은 바꿀 수 없어 일부만 취소하면 고객 입금을 확인할 수 없다.
     *
     * @return self 예외
     */
    public static function partialCancelNotSupportedBeforeVbankDeposit(): self
    {
        return new self('sirsoft-ecommerce::exceptions.partial_cancel_not_supported_vbank_awaiting_deposit');
    }

    /**
     * 환불액이 **그 수단의** 잔여를 초과할 때 (S3-D21 — 저장·실행 공통 422)
     *
     * 합계 한도(`refundExceedsCancellable`)와 다른 축이다. 합계로는 통과하지만 **실제로 나갈
     * 수단**의 잔여가 모자란 경우를 가른다 — 현금 잔여가 말랐는데 마일리지 여유가 그것을
     * 가려 주면, 결제행의 취소 누적이 결제액을 넘는다(실측 주문 753: 현금 345,000 결제에
     * 350,000 환불).
     *
     * 문구에 **수단과 두 숫자를 함께 싣는다** — 합계만 알려 주면 운영자가 어느 수단을 얼마나
     * 줄여야 하는지 알 수 없어 화면에서 고칠 수 없다.
     *
     * @param  string  $method  수단 이름 (다국어 라벨)
     * @param  string  $requested  요청액 (통화 포맷 문자열)
     * @param  string  $remaining  그 수단의 잔여 (통화 포맷 문자열)
     * @return self 예외 인스턴스
     */
    public static function refundExceedsMethodRemaining(string $method, string $requested, string $remaining): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_refund_exceeds_method_remaining', [
            'method' => $method,
            'requested' => $requested,
            'remaining' => $remaining,
        ]);
    }

    /**
     * 환불액이 취소 가능 금액(D19)을 초과할 때 (저장·실행 공통 422)
     *
     * @param  string  $requested  요청 환불액 (통화 포맷 문자열)
     * @param  string  $cancellable  취소 가능 금액 (통화 포맷 문자열)
     * @return self 예외 인스턴스
     */
    public static function refundExceedsCancellable(string $requested, string $cancellable): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_refund_exceeds_cancellable', [
            'requested' => $requested,
            'cancellable' => $cancellable,
        ]);
    }

    /**
     * 비회원 주문의 환불액에 마일리지 환불을 실었을 때 (마일리지 원장은 회원 전용)
     *
     * @return self 예외 인스턴스
     */
    public static function refundPointsRequireMember(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_refund_points_requires_member');
    }

    /**
     * 이 주문의 결제수단이 지원하지 않는 환불수단을 지정했을 때 (S1-D15)
     *
     * 무통장·마일리지·예치금·무료 결제는 PG 거래가 없어 `pg` 로 돌려줄 대상이 없다.
     * 그런데도 저장을 허용하면 화면은 「PG사 환불」이라고 말하지만 실행은 PG 훅을 건너뛰어
     * (`RefundExecutionService` 가 결제수단으로 진입을 가른다) 장부와 표시가 어긋난다.
     *
     * @return self 예외 인스턴스
     */
    public static function refundMethodUnavailable(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_refund_method_unavailable');
    }

    /**
     * PG 결제 주문인데 그 결제 플러그인이 꺼져 PG 환불을 지정할 수 없을 때 (S3-D25)
     *
     * 실행 경로(`RefundExecutionService`)가 같은 사유에 내는 문장과 같다 — 플러그인을 켜거나 환불 수단을
     * 계좌 환불로 바꾸라는 해결책을 함께 말한다.
     *
     * @param  string  $provider  결제 플러그인(제공자) 식별자
     * @return self 예외 인스턴스
     */
    public static function pgProviderInactive(string $provider): self
    {
        return new self('sirsoft-ecommerce::exceptions.pg_provider_inactive', ['provider' => $provider]);
    }

    /**
     * 회수 송장 정보가 불완전할 때 (택배사/송장번호 중 한쪽만 입력)
     *
     * @return self 예외 인스턴스
     */
    public static function trackingIncomplete(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_tracking_incomplete');
    }

    /**
     * 회수중·발송중으로 옮기는데 택배사·송장번호가 없을 때
     *
     * @return self 예외 인스턴스
     */
    public static function trackingRequired(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_tracking_required');
    }

    /**
     * 취소 대상 주문 옵션이 진행 중인 클레임에 묶여 있을 때
     *
     * @param  string  $statusLabel  현재 상품 상태 라벨
     * @return self 예외 인스턴스
     */
    public static function optionInClaimCourse(string $statusLabel): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_option_in_course', [
            'status' => $statusLabel,
        ]);
    }

    /**
     * 클레임 진행 중인 상품의 상태를 관리자가 직접 변경하려 할 때의 예외를 생성합니다. (D15 후단)
     *
     * 취소 차단(`optionInClaimCourse`)과 사유는 같지만 운영자가 하려던 행위가 달라 안내가 갈린다 —
     * 같은 문구를 쓰면 "취소할 수 없습니다" 가 상태 변경 화면에 뜬다.
     *
     * @param  string  $statusLabel  현재 코스 상태 라벨
     * @return self 예외 인스턴스
     */
    public static function optionInClaimCourseStatusChange(string $statusLabel): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_option_in_course_status_change', [
            'status' => $statusLabel,
        ]);
    }

    /**
     * 클레임 코스 상태를 목표 상태로 직접 지정하려 할 때 (D15 후단 — 목표 축)
     *
     * 코스 상태는 교환·반품 서비스만 기록한다. 화면은 지정 가능 상태 목록으로 막지만,
     * 그 목록을 거치지 않는 내부·훅 호출까지 같은 규칙을 적용한다.
     *
     * @param  string  $statusLabel  지정하려던 코스 상태 라벨
     * @return self 예외 인스턴스
     */
    public static function courseStatusNotAssignable(string $statusLabel): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_course_status_not_assignable', [
            'status' => $statusLabel,
        ]);
    }

    /**
     * 교환 대상 신규 상품옵션이 유효하지 않을 때 (부재·비활성·다른 상품의 옵션)
     *
     * 교환은 **같은 상품의 다른 옵션**으로만 바꿀 수 있다(D29 "동일상품/옵션변경"). 다른 상품의
     * 옵션을 받으면 배송정책·과세·적립 정책이 원 상품과 달라 차액 하나로 정산할 수 없다.
     *
     * @return self 예외 인스턴스
     */
    public static function newOptionInvalid(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_new_option_invalid');
    }

    /**
     * 교환 대상 신규 상품옵션의 재고가 신청 수량에 못 미칠 때
     *
     * 신청 시점과 승인 시점(재고 차감) 양쪽에서 쓴다. 승인 시점의 부족은 재고 서비스가
     * `InsufficientStockException` 으로 올리는 것을 이 사유로 바꿔 일괄 전이 보고에 싣는다.
     *
     * @return self 예외 인스턴스
     */
    public static function newOptionOutOfStock(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_new_option_out_of_stock');
    }

    /**
     * 교환 재발송지의 국가를 주문 배송지와 다르게 지정했을 때 (교환 해외배송 미지원)
     *
     * 재발송지는 우편번호·상세주소만 바꿀 수 있다. 국가가 바뀌면 배송정책 자체가 달라져
     * 왕복 배송비를 원 정책으로 재산정할 근거가 없다.
     *
     * @return self 예외 인스턴스
     */
    public static function redeliveryCountryChangeNotAllowed(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_redelivery_country_change_not_allowed');
    }

    /**
     * 환불이 발생하지 않는 클레임에 환불신청서 접수를 요구했을 때 (동가·고가 교환)
     *
     * 조용히 건너뛰면 운영자는 "접수했다" 고 알고 있는데 신청서가 없는 상태가 된다 —
     * 접수할 것이 없음을 명시적으로 알린다.
     *
     * @return self 예외 인스턴스
     */
    public static function refundNotApplicable(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_refund_not_applicable');
    }

    /**
     * 신청서를 찾지 못했습니다. (번호 해석 실패 / 소유권 위반 — 존재 여부를 구분해 알리지 않는다)
     *
     * @return self 예외 인스턴스
     */
    public static function claimNotFound(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_not_found');
    }

    /**
     * 승인 이후라 신청 내용을 수정할 수 없습니다. (D13)
     *
     * @return self 예외 인스턴스
     */
    public static function notEditable(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_not_editable');
    }

    /**
     * 선택 상품의 상태가 즉시취소·환불신청 두 분기에 걸쳐 있어 한 신청서로 접수할 수 없습니다. (D2)
     *
     * @return self 예외 인스턴스
     */
    public static function refundBranchMixed(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_refund_branch_mixed');
    }

    /**
     * 취소·환불 대상이 아닌 상품입니다. (즉시취소 가능 상태도, 환불신청 가능 상태도 아님)
     *
     * @param  string  $statusLabel  현재 상품 상태 라벨
     * @return self 예외 인스턴스
     */
    public static function refundNotRequestable(string $statusLabel): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_refund_not_requestable', [
            'status' => $statusLabel,
        ]);
    }

    /**
     * 결제 건을 찾지 못했습니다.
     *
     * @return self 예외 인스턴스
     */
    public static function paymentNotFound(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_payment_not_found');
    }

    /**
     * 결제 대기 상태가 아니라 결제를 진행할 수 없습니다.
     *
     * @return self 예외 인스턴스
     */
    public static function paymentNotPending(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_payment_not_pending');
    }

    /**
     * PG 승인 중에 결제 건이 철회·정리로 닫혔을 때 (F5 — 승인은 망취소된다)
     *
     * @return self 예외 인스턴스
     */
    public static function paymentWithdrawnDuringApproval(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_payment_withdrawn_during_approval');
    }

    /**
     * 이 신청서에는 결제할 청구액이 없습니다.
     *
     * 소비처는 관리자 결제 요청 경로(`AdminClaimService::requestPayment()`)다 — 청구액이 없는
     * 신청서에 결제대기 전이를 요구하는 호출을 이 예외로 거절한다.
     *
     * @return self 예외 인스턴스
     */
    public static function paymentNotRequired(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_payment_not_required');
    }

    /**
     * 미수납 청구액이 남아 있어 승인할 수 없습니다.
     *
     * 소비처는 관리자 승인 경로(`AdminClaimService::transition()`)다 — 미수납 건을 승인하면
     * 결제 확정 경로(`confirmPaid()`)가 다시 옮길 상태가 없어 청구액이 회수되지 않은 채
     * 신청이 진행된다. 승인 시점 결제(`pay_on_approval`)는 승인 시도가 결제 요청으로
     * 우회되므로 이 예외에 도달하지 않고, 신청 시점 결제(`pay_on_request`)의 미수납 건만
     * 여기서 거절된다.
     *
     * @return self 예외 인스턴스
     */
    public static function paymentOutstanding(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_payment_outstanding');
    }

    /**
     * 「환불금에서 차감」 인데 환불액이 청구액보다 적을 때 (S4)
     *
     * 차감할 환불금이 모자라면 부족분이 어디에도 청구되지 않는다 — 청구 방식을 추가결제로 바꾸게 한다.
     *
     * @return self 예외 인스턴스
     */
    public static function chargeDeductInsufficient(): self
    {
        return new self('sirsoft-ecommerce::exceptions.charge_deduct_insufficient');
    }

    /**
     * 선택한 결제 수단은 클레임 추가결제에 허용되지 않습니다. (`claim.charge_payment_methods`)
     *
     * @return self 예외 인스턴스
     */
    public static function paymentMethodNotAllowed(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_payment_method_not_allowed');
    }

    /**
     * 결제 승인을 처리할 PG 연동이 없습니다. (클레임 결제 승인 훅을 구현한 결제 플러그인 부재)
     *
     * @return self 예외 인스턴스
     */
    public static function paymentProviderUnavailable(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_payment_provider_unavailable');
    }

    /**
     * 거래 시점 통화 스냅샷을 읽을 수 없어 청구액을 확정하지 못했습니다.
     *
     * 스냅샷이 없으면 소수 자릿수·절사 규칙·환산 분모가 현재 상점 설정으로 미끄러져 청구액
     * 자체가 달라진다(0 자리 통화가 2 자리로 읽히면 100 배가 된다). 승인 대조가 그 상태를
     * 거절하므로, 결제창 진입도 같은 판정으로 닫는다 — 열어 두면 PG 승인이 확정된 **뒤**에
     * 금액 불일치로 거절되어 망취소 보상이 필요해진다.
     *
     * @return self 예외 인스턴스
     */
    public static function paymentCurrencyUnavailable(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_payment_currency_unavailable');
    }

    /**
     * PG 결제 승인에 실패했습니다.
     *
     * @param  string  $error  PG 가 돌려준 사유
     * @return self 예외 인스턴스
     */
    public static function paymentFailed(string $error): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_payment_failed', [
            'error' => $error,
        ]);
    }

    /**
     * PG 가 승인한 금액이 청구액과 다릅니다.
     *
     * @return self 예외 인스턴스
     */
    public static function paymentAmountMismatch(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_payment_amount_mismatch');
    }

    /**
     * 결제 환급에 실패했습니다.
     *
     * @param  string  $error  PG 가 돌려준 사유
     * @return self 예외 인스턴스
     */
    public static function paymentRefundFailed(string $error): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_payment_refund_failed', [
            'error' => $error,
        ]);
    }

    /**
     * 마일리지 결제는 회원만 할 수 있습니다.
     *
     * @return self 예외 인스턴스
     */
    public static function pointPaymentRequiresMember(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_point_payment_requires_member');
    }

    /**
     * 첨부 이미지가 최대 장수를 넘었습니다.
     *
     * @param  int  $max  최대 장수
     * @return self 예외 인스턴스
     */
    public static function imageLimitExceeded(int $max): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_image_limit_exceeded', [
            'max' => $max,
            'count' => $max,
        ]);
    }

    /**
     * 첨부 이미지를 찾지 못했습니다. (이 주문·신청서 소속이 아닌 첨부 포함)
     *
     * @return self 예외 인스턴스
     */
    public static function imageNotFound(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_image_not_found');
    }

    /**
     * 이 상품 상태에서는 그 방향 송장을 적을 수 없을 때 (R5)
     *
     * @return self 예외 인스턴스
     */
    public static function trackingItemNotEligible(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_tracking_item_not_eligible');
    }

    /**
     * 회수지가 다른 상품을 같은 회수 송장으로 묶으려 할 때 (R8 — 한 상자는 한 회수지로 간다)
     *
     * @return self 예외 인스턴스
     */
    public static function trackingMixedReturnAddress(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_tracking_mixed_return_address');
    }

    /**
     * 반품에 재발송 송장을 실었을 때 (R1)
     *
     * @return self 예외 인스턴스
     */
    public static function trackingDirectionNotAllowed(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_tracking_direction_not_allowed');
    }

    /**
     * 운송장 단위 도착 처리에서 그 운송장을 가진 상품이 없을 때
     *
     * @return self 예외 인스턴스
     */
    public static function trackingNotFound(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_tracking_not_found');
    }

    /**
     * 지시한 상품 행이 이 신청서에 없을 때
     *
     * @return self 예외 인스턴스
     */
    public static function claimItemNotFound(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_item_not_found');
    }

    /**
     * 진행 방식(일반·선교환)을 지금 바꿀 수 없을 때
     *
     * @return self 예외 인스턴스
     */
    public static function exchangeModeNotChangeable(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_exchange_mode_not_changeable');
    }

    /**
     * 회수 송장이 있는 선교환을 일반 교환으로 되돌리려 할 때
     *
     * @return self 예외 인스턴스
     */
    public static function exchangeModeLockedByCollection(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_exchange_mode_locked_by_collection');
    }

    /**
     * 쇼핑몰 설정에서 선교환을 끈 상태에서 선교환으로 바꾸려 할 때
     *
     * @return self 예외 인스턴스
     */
    public static function advanceExchangeDisabled(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_advance_exchange_disabled');
    }

    /**
     * 미회수 완료에 사유가 없을 때
     *
     * @return self 예외 인스턴스
     */
    public static function unreturnedReasonRequired(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_unreturned_reason_required');
    }

    /**
     * 미회수로 완료할 수 없는 상품을 지정했을 때 (선교환 발송 후 회수 대기 상품만)
     *
     * @return self 예외 인스턴스
     */
    public static function unreturnedNotEligible(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_unreturned_not_eligible');
    }

    /**
     * 판매자가 접수한 신청서를 고객이 철회·수정하려 할 때
     *
     * @return self 예외 인스턴스
     */
    public static function adminRequestedNotEditable(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_admin_requested_not_editable');
    }

    /**
     * 재고 복구할 수 있는 상품이 없을 때 (완료·미복구·미회수 아님)
     *
     * @return self 예외 인스턴스
     */
    public static function stockRestoreNotEligible(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_stock_restore_not_eligible');
    }

    /**
     * 관리자 대리 철회에 사유가 없을 때
     *
     * @return self 예외 인스턴스
     */
    public static function withdrawReasonRequired(): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_withdraw_reason_required');
    }

    /**
     * 관리자 직권 접수가 상품 상태(물리 게이트)에 맞지 않을 때
     *
     * @param  string  $statusLabel  현재 상품 상태 라벨
     * @return self 예외 인스턴스
     */
    public static function adminRequestNotAllowed(string $statusLabel): self
    {
        return new self('sirsoft-ecommerce::exceptions.claim_admin_request_not_allowed', ['status' => $statusLabel]);
    }

    /**
     * 확장이 클레임 before 훅(`claim.{type}.before_*`)에서 동작을 막을 때 던집니다.
     *
     * 생성자가 닫혀 있어 확장은 이 팩토리로만 클레임 도메인 거절을 만들 수 있다. 도메인 거절로
     * 던져야 화면이 422 와 그 문구를 받고, 일괄 전이는 그 건만 skip 으로 보고하며, 접수 직후의
     * 0원 자동 승인은 접수를 살린 채 신청 상태로 남긴다 — 다른 예외는 그 경로들에서 500·일반
     * 실패 문구가 된다. 메시지 키는 확장 자신의 다국어 키(`vendor-name::...`)를 쓴다.
     *
     * @param  string  $messageKey  다국어 메시지 키
     * @param  array<string, mixed>  $replace  메시지 치환 파라미터
     * @return self 예외 인스턴스
     */
    public static function blockedByExtension(string $messageKey, array $replace = []): self
    {
        return new self($messageKey, $replace);
    }

    /**
     * 다국어 메시지 키를 반환합니다.
     *
     * @return string 다국어 메시지 키
     */
    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    /**
     * 메시지 치환 파라미터를 반환합니다.
     *
     * @return array<string, mixed> 치환 파라미터
     */
    public function getMessageParams(): array
    {
        return $this->replace;
    }
}
