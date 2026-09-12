<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Ecommerce\Enums\CancelOptionStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundOptionStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\Concerns\BuildsEnumFieldChange;
use Modules\Sirsoft\Ecommerce\Listeners\Concerns\ResolvesClaimIdentity;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;

/**
 * 클레임 활동 로그 리스너 (§4-8 — 교환·반품·환불·클레임 추가결제 전 상태 변경 전수)
 *
 * 클레임 서비스가 발화하는 도메인 훅을 구독해 `Log::channel('activity')` 로 기록한다.
 * "누가 언제 무엇을 바꿨는가" 를 남기는 것이 이 리스너의 책임이며, 행위자는 코어
 * `ActivityLogProcessor` 가 `user_id ??= Auth::id()` 로 자동 주입한다 — 그래서 신청서
 * 테이블에 `withdrawn_by` 같은 컬럼을 새로 만들지 않는다(§8-1 판정 8).
 *
 * ## 구체 전이 훅을 함께 구독하지 않는 이유
 *
 * 승인·반려·철회·완료는 `claim.{type}.status_changed` 와 구체 훅
 * (`claim.{type}.approved` 등)이 **둘 다** 발화한다. 양쪽을 구독하면 한 번의 전이가 두
 * 줄로 남아 이력이 부풀고, 상태 목록이 늘 때마다 어느 상태가 두 줄인지 달라진다.
 * 전이는 `status_changed` 한 축으로만 기록하고 목표 상태는 properties 로 싣는다.
 *
 * ## 상태 값은 properties 로, 식별자만 description_params 로
 *
 * 상태 라벨은 표시 시점 로케일로 해석해야 하므로 문구에 박제하지 않는다(코어 규정 —
 * description_params 에 표시용 이름 저장 금지). 로그 문구는 클레임 번호까지만 담고,
 * 전이 전/후 상태 값은 properties 에 원값으로 남긴다.
 *
 * ## 전/후는 `changes` 에도 싣는다
 *
 * 활동 로그 화면은 `changes` 에 실린 항목만 "변경 전 → 변경 후" 표로 그린다. properties 에만
 * 두면 값은 남는데 관리자 활동 로그·클레임 상세 활동 로그 어디에도 나타나지 않아, 이력이
 * "상태 변경" 한 줄로만 보인다. 그래서 헤더 전이는 `status` 한 항목, 상품 단위 전이는
 * 상품별(`option_id` 키) 항목 묶음으로 코어 형식(`ChangeDetector` 와 동형)을 함께 남긴다.
 */
class ClaimActivityLogListener implements HookListenerInterface
{
    use BuildsEnumFieldChange;
    use ResolvesActivityLogType;
    use ResolvesClaimIdentity;

    /** 다국어 description 키 접두 */
    private const DESCRIPTION_PREFIX = 'sirsoft-ecommerce::activity_log.description.';

    /** 변경 항목 라벨 키 접두 */
    private const FIELD_LABEL_PREFIX = 'sirsoft-ecommerce::activity_log.fields.';

    /**
     * 금액 수정 스냅샷 필드의 변경 이력 메타데이터.
     *
     * `AdminClaimService::amountSnapshot()` 가 만드는 키 집합과 1:1 이다 — 그쪽에 필드가
     * 늘면 여기에도 넣어야 그 변경이 이력에 보인다(빠지면 값은 바뀌는데 이력에는 없다).
     *
     * @var array<string, array{type: string, enum?: class-string}>
     */
    private const AMOUNT_FIELD_META = [
        'claim_shipping_fee' => ['type' => 'currency'],
        'total_charge_amount' => ['type' => 'currency'],
        'charge_method' => ['type' => 'enum', 'enum' => ClaimChargeMethodEnum::class],
        'admin_memo' => ['type' => 'text'],
        'refund_amount' => ['type' => 'currency'],
        'refund_points_amount' => ['type' => 'currency'],
        'refund_method' => ['type' => 'enum', 'enum' => RefundMethodEnum::class],
    ];

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array{method: string, priority: int}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // ─── 신청 접수 (OrderExchangeService / OrderReturnService / OrderRefundRequestService) ───
            'sirsoft-ecommerce.claim.exchange.requested' => ['method' => 'handleExchangeRequested', 'priority' => 20],
            'sirsoft-ecommerce.claim.return.requested' => ['method' => 'handleReturnRequested', 'priority' => 20],
            'sirsoft-ecommerce.claim.refund.requested' => ['method' => 'handleRefundRequested', 'priority' => 20],
            // 취소 축은 알림 리스너에 등록하지 않는다 — 환불 알림이 이미 그 사건을 대표하므로
            // 등록하면 한 건에 알림이 두 번 간다.
            'sirsoft-ecommerce.claim.cancel.requested' => ['method' => 'handleCancelRequested', 'priority' => 20],

            // ─── 상태 전이 (전 상태 전수) ───
            'sirsoft-ecommerce.claim.exchange.status_changed' => ['method' => 'handleStatusChanged', 'priority' => 20],
            'sirsoft-ecommerce.claim.return.status_changed' => ['method' => 'handleStatusChanged', 'priority' => 20],
            'sirsoft-ecommerce.claim.refund.status_changed' => ['method' => 'handleStatusChanged', 'priority' => 20],
            'sirsoft-ecommerce.claim.cancel.status_changed' => ['method' => 'handleStatusChanged', 'priority' => 20],

            // ─── 상품 단위 전이 (D25 — 헤더가 따라 움직이지 않는 조합이 있다) ───
            'sirsoft-ecommerce.claim.exchange.option_status_changed' => ['method' => 'handleOptionStatusChanged', 'priority' => 20],
            'sirsoft-ecommerce.claim.return.option_status_changed' => ['method' => 'handleOptionStatusChanged', 'priority' => 20],
            // 환불·취소 축의 상품 단위 전이 — 예전에는 발행·구독 양쪽에 모두 없어, 환불
            // 신청상품이 반려·철회·완료로 옮겨 가도 어떤 줄도 남지 않았다(C5·C6·C7·C8).
            'sirsoft-ecommerce.claim.refund.option_status_changed' => ['method' => 'handleOptionStatusChanged', 'priority' => 20],
            'sirsoft-ecommerce.claim.cancel.option_status_changed' => ['method' => 'handleOptionStatusChanged', 'priority' => 20],

            // ─── 금액 수정 (AdminClaimService — D18) ───
            'sirsoft-ecommerce.claim.exchange.amounts_updated' => ['method' => 'handleAmountsUpdated', 'priority' => 20],
            'sirsoft-ecommerce.claim.return.amounts_updated' => ['method' => 'handleAmountsUpdated', 'priority' => 20],
            'sirsoft-ecommerce.claim.refund.amounts_updated' => ['method' => 'handleAmountsUpdated', 'priority' => 20],

            // ─── 환불 실행 (OrderRefundRequestService) ───
            'sirsoft-ecommerce.claim.refund.completed' => ['method' => 'handleRefundExecuted', 'priority' => 20],

            // ─── 클레임 추가결제 (ClaimPaymentService) ───
            'sirsoft-ecommerce.claim.exchange.payment_completed' => ['method' => 'handlePaymentCompleted', 'priority' => 20],
            'sirsoft-ecommerce.claim.return.payment_completed' => ['method' => 'handlePaymentCompleted', 'priority' => 20],
            'sirsoft-ecommerce.claim.exchange.payment_refunded' => ['method' => 'handlePaymentRefunded', 'priority' => 20],
            'sirsoft-ecommerce.claim.return.payment_refunded' => ['method' => 'handlePaymentRefunded', 'priority' => 20],
            'sirsoft-ecommerce.claim.payment_cancelled' => ['method' => 'handlePaymentCancelled', 'priority' => 20],

            // ─── 첨부 (ClaimAttachmentService) ───
            'sirsoft-ecommerce.claim-image.after_upload' => ['method' => 'handleImageUploaded', 'priority' => 20],
            'sirsoft-ecommerce.claim-image.after_delete' => ['method' => 'handleImageDeleted', 'priority' => 20],
        ];
    }

    /**
     * 기본 핸들러 (method 매핑을 사용하므로 미사용).
     *
     * @param  mixed  ...$args  훅 인수
     */
    public function handle(...$args): void {}

    /**
     * 교환 신청 접수를 기록합니다.
     *
     * @param  OrderExchange  $exchange  접수된 교환 신청서
     */
    public function handleExchangeRequested(OrderExchange $exchange): void
    {
        $this->logClaimRequested($exchange);
    }

    /**
     * 반품 신청 접수를 기록합니다.
     *
     * @param  OrderReturn  $return  접수된 반품 신청서
     */
    public function handleReturnRequested(OrderReturn $return): void
    {
        $this->logClaimRequested($return);
    }

    /**
     * 환불 신청 접수를 기록합니다.
     *
     * @param  OrderRefund  $refund  접수된 환불 신청서
     */
    public function handleRefundRequested(OrderRefund $refund): void
    {
        $this->logClaimRequested($refund);
    }

    /**
     * 취소신청서 생성을 기록합니다. (#599 후속 §7-1 해결책 2 — B3)
     *
     * 취소신청서는 환불이 완료되는 순간에 생긴다(D3). 그래서 접수와 완료 사이에 사람이
     * 개입할 틈은 없지만, **두 줄을 모두 남긴다** — 한 줄만 남기면 운영자는 이 취소가
     * 어떤 경로로 확정됐는지(자동 전이인지 운영자 [환불하기]인지) 되짚을 수 없다.
     *
     * @param  OrderCancel  $cancel  생성된 취소신청서
     * @param  Order|null  $order  주문 (훅 계약상 함께 넘어오지만 로그 본문에는 쓰지 않는다)
     * @return void
     */
    public function handleCancelRequested(OrderCancel $cancel, ?Order $order = null): void
    {
        $this->logClaimRequested($cancel);
    }

    /**
     * 클레임 상태 전이를 기록합니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  전이된 신청서
     * @param  string|null  $previous  전이 전 상태 값
     * @param  string|null  $target  전이 시점에 캡처한 목표 상태 값
     */
    public function handleStatusChanged(
        OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim,
        ?string $previous = null,
        ?string $target = null,
    ): void {
        $type = $this->claimTypeOf($claim);
        // 전이 시점에 캡처된 목표 상태가 기준이다 — 큐 지연 중 재전이된 모델의 "지금"
        // 상태를 적으면 이력이 실제로 일어난 전이와 어긋난다.
        $new = $target ?? $this->claimStatusValue($claim);

        $this->logActivity($this->actionFor($type, 'status_change'), [
            'loggable' => $claim,
            'description_key' => self::DESCRIPTION_PREFIX.$this->descriptionFor($type, 'status_change'),
            'description_params' => $this->identityParams($claim),
            'properties' => [
                'claim_type' => $type->value,
                'previous_status' => $previous,
                'new_status' => $new,
            ],
            'changes' => $previous === $new ? null : [
                $this->enumFieldChange('status', self::FIELD_LABEL_PREFIX.'status', $this->statusEnumClassFor($type), $previous, $new),
            ],
        ]);
    }

    /**
     * 상품 단위 상태 전이를 기록합니다. (D25)
     *
     * 헤더 전이와 층이 다른 사실이라 따로 남긴다 — 상품 상태가 갈리면 헤더는 움직이지
     * 않고, 그때 이 줄이 없으면 운영자가 한 상태 변경의 흔적이 전혀 남지 않는다. 반대로
     * 헤더가 따라 움직인 회차는 이 줄과 `status_changed` 줄이 함께 남으며, 그것은 이중
     * 기록이 아니라 "상품을 옮겼고 그래서 신청서도 옮겨졌다" 는 두 사실이다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @param  array<int, array{option_id: int, previous: string, new: string}>  $changes  상품별 전이 내역
     * @param  string|null  $target  전이 목표 상태 값
     * @param  int|null  $processedBy  처리 관리자 ID
     */
    public function handleOptionStatusChanged(
        OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim,
        array $changes = [],
        ?string $target = null,
        ?int $processedBy = null,
    ): void {
        if ($changes === []) {
            return;
        }

        $type = $this->claimTypeOf($claim);
        $enumClass = $this->optionStatusEnumClassFor($type);

        // 상품별 전/후 — 코어 일괄 형식(`{모델ID: [항목…]}`)으로 실어야 화면이 상품마다 표를
        // 그린다. 신청 상품 행의 ID 가 키다.
        $bulkChanges = [];
        foreach ($changes as $change) {
            $bulkChanges[(int) ($change['option_id'] ?? 0)] = [
                $this->enumFieldChange(
                    'option_status',
                    self::FIELD_LABEL_PREFIX.'option_status',
                    $enumClass,
                    $change['previous'] ?? null,
                    $change['new'] ?? null,
                ),
            ];
        }

        $this->logActivity($this->actionFor($type, 'option_status_change'), [
            'loggable' => $claim,
            'description_key' => self::DESCRIPTION_PREFIX.$this->descriptionFor($type, 'option_status_change'),
            'description_params' => $this->identityParams($claim) + ['count' => (string) count($changes)],
            'properties' => [
                'claim_type' => $type->value,
                'new_status' => $target,
                'processed_by' => $processedBy,
                // 상태 값은 원값으로 남긴다 — 라벨은 표시 시점 로케일로 해석한다.
                'options' => $changes,
            ],
            'changes' => $bulkChanges !== [] ? $bulkChanges : null,
        ]);
    }

    /**
     * 유형별 상태 Enum 클래스를 반환합니다. (전/후 라벨 키 해석용)
     *
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @return class-string<ExchangeStatusEnum|ReturnStatusEnum|RefundStatusEnum> Enum 클래스
     */
    private function statusEnumClassFor(ClaimTypeEnum $type): string
    {
        return match ($type) {
            ClaimTypeEnum::EXCHANGE => ExchangeStatusEnum::class,
            ClaimTypeEnum::RETURN => ReturnStatusEnum::class,
            ClaimTypeEnum::CANCEL => CancelStatusEnum::class,
            default => RefundStatusEnum::class,
        };
    }

    /**
     * 유형별 **상품 행** 상태 Enum 클래스를 반환합니다. (전/후 라벨 키 해석용)
     *
     * 헤더와 상품 행은 상태 어휘가 다르다 — 헤더 Enum 으로 상품 값을 해석하면 라벨 키가
     * 맞지 않아 활동로그 표의 "변경 전 → 변경 후" 가 빈칸으로 나온다(오류는 나지 않는다).
     *
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @return class-string 상품 행 상태 Enum 클래스
     */
    private function optionStatusEnumClassFor(ClaimTypeEnum $type): string
    {
        return match ($type) {
            ClaimTypeEnum::EXCHANGE => ExchangeStatusEnum::class,
            ClaimTypeEnum::RETURN => ReturnStatusEnum::class,
            ClaimTypeEnum::CANCEL => CancelOptionStatusEnum::class,
            default => RefundOptionStatusEnum::class,
        };
    }

    /**
     * 클레임 추가결제 대기 건 취소를 기록합니다.
     *
     * 대상은 언제나 미수납 대기 행이라 돈이 오간 사건은 아니다. 그래도 남기는 이유는
     * 청구액 재산정처럼 사람이 부른 경로가 섞여 있어, "왜 결제 요청이 사라졌는가" 를
     * 운영자가 되짚을 통로가 이력 말고는 없기 때문이다. 신청서가 이미 사라진 고아 건도
     * 취소 대상이므로 `$claim` 은 null 일 수 있다.
     *
     * @param  ClaimPayment  $payment  취소된 결제 건
     * @param  OrderExchange|OrderReturn|null  $claim  신청서 (해석 불가 시 null)
     * @param  string|null  $reason  취소 사유 (`ClaimPaymentService::CANCEL_REASON_*`)
     */
    public function handlePaymentCancelled(
        ClaimPayment $payment,
        OrderExchange|OrderReturn|null $claim = null,
        ?string $reason = null,
    ): void {
        $this->logActivity('claim_payment.cancel', [
            'loggable' => $payment,
            'description_key' => self::DESCRIPTION_PREFIX.'claim_payment_cancel',
            'description_params' => ['payment_number' => (string) ($payment->payment_number ?? '')],
            'properties' => [
                'claim_type' => $claim !== null ? $this->claimTypeOf($claim)->value : $payment->claimType()?->value,
                'claim_number' => $claim !== null ? $this->claimNumberOf($claim) : null,
                'reason' => $reason,
                'amount' => (float) $payment->amount,
                'points_amount' => (float) $payment->points_amount,
                'currency_code' => $payment->currency_code,
            ],
        ]);
    }

    /**
     * 관리자 금액 수정을 기록합니다. (D18 — 수정 전/후)
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  수정된 신청서
     * @param  array<string, mixed>  $before  수정 전 금액 스냅샷
     * @param  array<string, mixed>  $after  수정 후 금액 스냅샷
     * @param  int|null  $processedBy  처리 관리자 ID
     */
    public function handleAmountsUpdated(
        OrderExchange|OrderReturn|OrderRefund $claim,
        array $before = [],
        array $after = [],
        ?int $processedBy = null,
    ): void {
        $type = $this->claimTypeOf($claim);

        $this->logActivity($this->actionFor($type, 'update_amounts'), [
            'loggable' => $claim,
            'description_key' => self::DESCRIPTION_PREFIX.$this->descriptionFor($type, 'update_amounts'),
            'description_params' => $this->identityParams($claim),
            'properties' => [
                'claim_type' => $type->value,
                'processed_by' => $processedBy,
            ],
            'changes' => $this->buildAmountChanges($before, $after),
        ]);
    }

    /**
     * 환불 실행(돈이 나간 사건)을 기록합니다.
     *
     * 같은 순간의 `approved → completed` 전이는 `status_changed` 가 따로 남긴다 — 이
     * 항목은 전이가 아니라 **실행 결과**(실제 환불액·PG 거래번호)를 남기는 자리다.
     *
     * @param  OrderRefund  $refund  실행된 환불신청서
     */
    public function handleRefundExecuted(OrderRefund $refund): void
    {
        $this->logActivity('claim_refund.execute', [
            'loggable' => $refund,
            'description_key' => self::DESCRIPTION_PREFIX.'claim_refund_execute',
            'description_params' => $this->identityParams($refund),
            'properties' => [
                'claim_type' => ClaimTypeEnum::REFUND->value,
                'refund_amount' => (float) $refund->refund_amount,
                'refund_points_amount' => (float) $refund->refund_points_amount,
                'refund_shipping_amount' => (float) $refund->refund_shipping_amount,
                'refund_method' => $refund->refund_method?->value,
                'pg_transaction_id' => $refund->pg_transaction_id,
            ],
        ]);
    }

    /**
     * 클레임 추가결제 수납 완료를 기록합니다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @param  ClaimPayment  $payment  확정된 결제 건
     */
    public function handlePaymentCompleted(OrderExchange|OrderReturn $claim, ClaimPayment $payment): void
    {
        $this->logActivity('claim_payment.complete', [
            'loggable' => $payment,
            'description_key' => self::DESCRIPTION_PREFIX.'claim_payment_complete',
            'description_params' => ['payment_number' => (string) ($payment->payment_number ?? '')],
            'properties' => [
                'claim_type' => $this->claimTypeOf($claim)->value,
                'claim_number' => $this->claimNumberOf($claim),
                // 훅은 상태 전이 뒤에 발화한다 — 여기 적히는 상태는 '결제완료' 이후 값이다.
                'claim_status' => $this->claimStatusValue($claim),
                'payment_method' => $payment->payment_method,
                'amount' => (float) $payment->amount,
                'points_amount' => (float) $payment->points_amount,
                'currency_code' => $payment->currency_code,
                'transaction_id' => $payment->transaction_id,
            ],
        ]);
    }

    /**
     * 클레임 추가결제 환급을 기록합니다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @param  ClaimPayment  $payment  환급된 결제 건
     * @param  float  $refundAmount  이번에 환급한 금액
     */
    public function handlePaymentRefunded(
        OrderExchange|OrderReturn $claim,
        ClaimPayment $payment,
        float $refundAmount = 0.0,
    ): void {
        $this->logActivity('claim_payment.refund', [
            'loggable' => $payment,
            'description_key' => self::DESCRIPTION_PREFIX.'claim_payment_refund',
            'description_params' => ['payment_number' => (string) ($payment->payment_number ?? '')],
            'properties' => [
                'claim_type' => $this->claimTypeOf($claim)->value,
                'claim_number' => $this->claimNumberOf($claim),
                'refund_amount' => $refundAmount,
                'refunded_total' => (float) $payment->refunded_amount,
                'payment_status' => $payment->payment_status?->value,
                'currency_code' => $payment->currency_code,
            ],
        ]);
    }

    /**
     * 클레임 첨부 업로드를 기록합니다.
     *
     * @param  ClaimImage  $image  업로드된 첨부
     */
    public function handleImageUploaded(ClaimImage $image): void
    {
        $this->logActivity('claim_image.upload', [
            'loggable' => $image,
            'description_key' => self::DESCRIPTION_PREFIX.'claim_image_upload',
            'description_params' => ['original_name' => (string) ($image->original_name ?? '')],
            'properties' => $this->imageProperties($image),
        ]);
    }

    /**
     * 클레임 첨부 삭제를 기록합니다.
     *
     * 행이 이미 지워졌으므로 표시에 필요한 값은 properties 스냅샷으로 함께 남긴다 —
     * 나중에 loggable 을 되찾지 못해도 무엇이 지워졌는지 읽을 수 있어야 한다.
     *
     * @param  ClaimImage  $image  삭제된 첨부
     */
    public function handleImageDeleted(ClaimImage $image): void
    {
        $this->logActivity('claim_image.delete', [
            'loggable_type' => ClaimImage::class,
            'loggable_id' => $image->id,
            'description_key' => self::DESCRIPTION_PREFIX.'claim_image_delete',
            'description_params' => ['original_name' => (string) ($image->original_name ?? '')],
            'properties' => $this->imageProperties($image),
        ]);
    }

    /**
     * 신청 접수 로그를 남깁니다. (3유형 공용)
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  접수된 신청서
     * @return void
     */
    private function logClaimRequested(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): void
    {
        $type = $this->claimTypeOf($claim);

        $this->logActivity($this->actionFor($type, 'request'), [
            'loggable' => $claim,
            'description_key' => self::DESCRIPTION_PREFIX.$this->descriptionFor($type, 'request'),
            'description_params' => $this->identityParams($claim),
            'properties' => [
                'claim_type' => $type->value,
                'status' => $this->claimStatusValue($claim),
                'reason_code' => $this->claimReasonCodeOf($claim),
                // 취소신청서에는 귀책 축이 없다 — 취소는 "돈이 빠진 사실" 의 기록이고,
                // 귀책은 그 취소를 일으킨 환불신청서가 이미 들고 있다.
                'fault_type' => $claim instanceof OrderCancel ? null : $claim->fault_type?->value,
                'is_guest_request' => $claim instanceof OrderCancel ? null : $claim->isGuestRequest(),
            ],
        ]);
    }

    /**
     * 첨부 로그의 properties 를 만듭니다.
     *
     * @param  ClaimImage  $image  첨부
     * @return array<string, mixed> properties
     */
    private function imageProperties(ClaimImage $image): array
    {
        return [
            'image_id' => $image->id,
            'claimable_type' => $image->claimable_type,
            'claimable_id' => $image->claimable_id,
            'original_name' => $image->original_name,
            'size' => $image->size,
        ];
    }

    /**
     * 클레임 식별 파라미터(문구 치환용)를 만듭니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @return array<string, string> 치환 파라미터
     */
    private function identityParams(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): array
    {
        return ['claim_number' => (string) ($this->claimNumberOf($claim) ?? '')];
    }

    /**
     * 유형별 action 키를 만듭니다.
     *
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @param  string  $verb  동작 (request|status_change|option_status_change|update_amounts)
     * @return string action 키
     */
    private function actionFor(ClaimTypeEnum $type, string $verb): string
    {
        return 'claim_'.$type->value.'.'.$verb;
    }

    /**
     * 유형별 description 키 접미를 만듭니다.
     *
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @param  string  $verb  동작 (request|status_change|option_status_change|update_amounts)
     * @return string description 키 접미
     */
    private function descriptionFor(ClaimTypeEnum $type, string $verb): string
    {
        return 'claim_'.$type->value.'_'.$verb;
    }

    /**
     * 금액 수정 전/후 스냅샷을 코어 changes 형식으로 변환합니다.
     *
     * 코어 `ChangeDetector` 와 같은 구조(field/label_key/old/new/type + enum 라벨 키)를
     * 만든다 — 화면이 두 형식을 구분하지 않으므로 여기서 어긋나면 그 항목만 빈칸이 된다.
     * 훅이 전/후 스냅샷을 직접 주므로 모델 `$activityLogFields` 경로는 쓰지 않는다.
     *
     * @param  array<string, mixed>  $before  수정 전 스냅샷
     * @param  array<string, mixed>  $after  수정 후 스냅샷
     * @return array<int, array<string, mixed>>|null 변경 항목 (없으면 null)
     */
    private function buildAmountChanges(array $before, array $after): ?array
    {
        $changes = [];

        foreach (self::AMOUNT_FIELD_META as $field => $meta) {
            if (! array_key_exists($field, $before) && ! array_key_exists($field, $after)) {
                continue;
            }

            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;

            if ($old === $new) {
                continue;
            }

            $change = [
                'field' => $field,
                'label_key' => self::FIELD_LABEL_PREFIX.$field,
                'old' => $old,
                'new' => $new,
                'type' => $meta['type'],
            ];

            if ($meta['type'] === 'enum' && isset($meta['enum'])) {
                $enumClass = $meta['enum'];
                $change['old_label_key'] = $old !== null ? $enumClass::tryFrom($old)?->labelKey() : null;
                $change['new_label_key'] = $new !== null ? $enumClass::tryFrom($new)?->labelKey() : null;
            }

            $changes[] = $change;
        }

        return $changes !== [] ? $changes : null;
    }
}
