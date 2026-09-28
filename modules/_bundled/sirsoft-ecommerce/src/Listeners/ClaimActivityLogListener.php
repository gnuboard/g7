<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Ecommerce\Enums\CancelOptionStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
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
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimReasonRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingCarrierRepositoryInterface;

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
 * ## 모델의 "지금" 값을 읽는 기록은 발행 시점에 남긴다 (동기 구독)
 *
 * 활동 로그 구독은 기본이 큐다. 워커가 신청서를 다시 읽는 시점에는 신청서가 이미 다음 상태로
 * 옮겨 갔을 수 있다 — 접수 줄에 「완료」가 찍히고(자동 전이), 분할 전 수량은 잔여 행이 또
 * 갈린 뒤의 값이 된다. 그래서 **모델에서 값을 읽는** 구독(접수 · 상품 단위 전이 · 추가결제
 * 수납/환급)만 `'sync' => true` 로 두어 서비스가 훅을 쏜 그 자리(커밋 직후)에서 기록한다.
 * 인자로 받은 스칼라(`$previous`/`$target`, 금액 전/후)만 쓰는 구독은 큐 그대로다.
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

    /**
     * @param  ShippingCarrierRepositoryInterface  $carrierRepository  배송사 Repository (송장 전·후 택배사 이름)
     * @param  ClaimReasonRepositoryInterface  $claimReasonRepository  클레임 사유 Repository (신청 내용 수정 전·후 사유 이름)
     */
    public function __construct(
        protected ShippingCarrierRepositoryInterface $carrierRepository,
        protected ClaimReasonRepositoryInterface $claimReasonRepository,
    ) {}

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
     * 모델에서 값을 읽는 구독만 `'sync' => true` 다(클래스 docblock 「발행 시점에 남긴다」).
     *
     * @return array<string, array{method: string, priority: int, sync?: bool}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // ─── 신청 접수 (OrderExchangeService / OrderReturnService / OrderRefundRequestService) ───
            'sirsoft-ecommerce.claim.exchange.requested' => ['method' => 'handleExchangeRequested', 'priority' => 20, 'sync' => true],
            'sirsoft-ecommerce.claim.return.requested' => ['method' => 'handleReturnRequested', 'priority' => 20, 'sync' => true],
            'sirsoft-ecommerce.claim.refund.requested' => ['method' => 'handleRefundRequested', 'priority' => 20, 'sync' => true],
            // 취소 축은 알림 리스너에 등록하지 않는다 — 환불 알림이 이미 그 사건을 대표하므로
            // 등록하면 한 건에 알림이 두 번 간다.
            'sirsoft-ecommerce.claim.cancel.requested' => ['method' => 'handleCancelRequested', 'priority' => 20, 'sync' => true],

            // ─── 상태 전이 (전 상태 전수) ───
            'sirsoft-ecommerce.claim.exchange.status_changed' => ['method' => 'handleStatusChanged', 'priority' => 20],
            'sirsoft-ecommerce.claim.return.status_changed' => ['method' => 'handleStatusChanged', 'priority' => 20],
            'sirsoft-ecommerce.claim.refund.status_changed' => ['method' => 'handleStatusChanged', 'priority' => 20],
            'sirsoft-ecommerce.claim.cancel.status_changed' => ['method' => 'handleStatusChanged', 'priority' => 20],

            // ─── 상품 단위 전이 (D25 — 헤더가 따라 움직이지 않는 조합이 있다) ───
            'sirsoft-ecommerce.claim.exchange.option_status_changed' => ['method' => 'handleOptionStatusChanged', 'priority' => 20, 'sync' => true],
            'sirsoft-ecommerce.claim.return.option_status_changed' => ['method' => 'handleOptionStatusChanged', 'priority' => 20, 'sync' => true],
            // 환불·취소 축의 상품 단위 전이 — 예전에는 발행·구독 양쪽에 모두 없어, 환불
            // 신청상품이 반려·철회·완료로 옮겨 가도 어떤 줄도 남지 않았다(C5·C6·C7·C8).
            'sirsoft-ecommerce.claim.refund.option_status_changed' => ['method' => 'handleOptionStatusChanged', 'priority' => 20, 'sync' => true],
            'sirsoft-ecommerce.claim.cancel.option_status_changed' => ['method' => 'handleOptionStatusChanged', 'priority' => 20, 'sync' => true],

            // ─── 금액 수정 (AdminClaimService — D18) ───
            // ─── 상품 행 송장 · 운송장 단위 도착 · 진행 방식 (#599 후속 — 상품별 송장·선교환) ───
            'sirsoft-ecommerce.claim.exchange.after_tracking_update' => ['method' => 'handleTrackingUpdated', 'priority' => 20],
            'sirsoft-ecommerce.claim.return.after_tracking_update' => ['method' => 'handleTrackingUpdated', 'priority' => 20],
            'sirsoft-ecommerce.claim.exchange.after_tracking_deliver' => ['method' => 'handleTrackingDelivered', 'priority' => 20],
            'sirsoft-ecommerce.claim.return.after_tracking_deliver' => ['method' => 'handleTrackingDelivered', 'priority' => 20],
            'sirsoft-ecommerce.claim.exchange.after_mode_change' => ['method' => 'handleExchangeModeChanged', 'priority' => 20],
            // 뒤늦은 재고 복구 (S13)
            'sirsoft-ecommerce.claim.exchange.after_stock_restore' => ['method' => 'handleStockRestored', 'priority' => 20],
            'sirsoft-ecommerce.claim.return.after_stock_restore' => ['method' => 'handleStockRestored', 'priority' => 20],

            'sirsoft-ecommerce.claim.exchange.amounts_updated' => ['method' => 'handleAmountsUpdated', 'priority' => 20],
            'sirsoft-ecommerce.claim.return.amounts_updated' => ['method' => 'handleAmountsUpdated', 'priority' => 20],
            'sirsoft-ecommerce.claim.refund.amounts_updated' => ['method' => 'handleAmountsUpdated', 'priority' => 20],

            // ─── 신청 내용 수정 (D13 고객 수정 · S15 운영자 대리 수정) ───
            // 전/후는 훅 인자로 받으므로 큐 그대로다(모델의 "지금" 값을 읽지 않는다).
            'sirsoft-ecommerce.claim.exchange.request_updated' => ['method' => 'handleRequestUpdated', 'priority' => 20],
            'sirsoft-ecommerce.claim.return.request_updated' => ['method' => 'handleRequestUpdated', 'priority' => 20],
            'sirsoft-ecommerce.claim.refund.request_updated' => ['method' => 'handleRequestUpdated', 'priority' => 20],

            // ─── 환불 실행 (OrderRefundRequestService) ───
            'sirsoft-ecommerce.claim.refund.completed' => ['method' => 'handleRefundExecuted', 'priority' => 20],

            // ─── 클레임 추가결제 (ClaimPaymentService) ───
            'sirsoft-ecommerce.claim.exchange.payment_completed' => ['method' => 'handlePaymentCompleted', 'priority' => 20, 'sync' => true],
            'sirsoft-ecommerce.claim.return.payment_completed' => ['method' => 'handlePaymentCompleted', 'priority' => 20, 'sync' => true],
            'sirsoft-ecommerce.claim.exchange.payment_refunded' => ['method' => 'handlePaymentRefunded', 'priority' => 20, 'sync' => true],
            'sirsoft-ecommerce.claim.return.payment_refunded' => ['method' => 'handlePaymentRefunded', 'priority' => 20, 'sync' => true],
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
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 교환 신청 접수를 기록합니다.
     *
     * @param  OrderExchange  $exchange  접수된 교환 신청서
     * @return void
     */
    public function handleExchangeRequested(OrderExchange $exchange): void
    {
        $this->logClaimRequested($exchange);
    }

    /**
     * 반품 신청 접수를 기록합니다.
     *
     * @param  OrderReturn  $return  접수된 반품 신청서
     * @return void
     */
    public function handleReturnRequested(OrderReturn $return): void
    {
        $this->logClaimRequested($return);
    }

    /**
     * 환불 신청 접수를 기록합니다.
     *
     * @param  OrderRefund  $refund  접수된 환불 신청서
     * @return void
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
     * @return void
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
     * @return void
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
            $rows = [
                $this->enumFieldChange(
                    'option_status',
                    self::FIELD_LABEL_PREFIX.'option_status',
                    $enumClass,
                    $change['previous'] ?? null,
                    $change['new'] ?? null,
                ),
            ];

            // 수량 분할이 일어난 회차는 "3 → 2" 한 줄을 더 남긴다. 분할 사실을 계보 컬럼으로
            // 두지 않기로 했으므로(계보는 주문옵션 + 신청서 FK 로 이미 닫혀 있다) 이 줄이
            // 유일한 흔적이다 — 없으면 운영자는 표가 한 줄 늘어난 것을 스스로 해석해야 한다.
            if (isset($change['quantity'], $change['remainder_option_id'])) {
                // 잔여 수량은 발행 측이 실어 준 값을 우선한다. 없으면(그 값을 싣지 않는 발행자)
                // 잔여 행을 읽는다 — 이 구독은 동기라 그 값도 발행 시점의 것이다.
                $remainder = isset($change['remainder_quantity'])
                    ? (int) $change['remainder_quantity']
                    : $this->remainderQuantityOf($claim, (int) $change['remainder_option_id']);

                $rows[] = [
                    'field' => 'quantity',
                    'label_key' => self::FIELD_LABEL_PREFIX.'quantity',
                    'old' => (string) ((int) $change['quantity'] + $remainder),
                    'new' => (string) (int) $change['quantity'],
                    'type' => 'number',
                ];
            }

            // 선교환 「미회수로 완료」 는 사유를 한 줄 더 남긴다(D3) — 재고가 늘지 않은 근거다.
            if (! empty($change['unreturned'])) {
                $rows[] = [
                    'field' => 'unreturned_reason',
                    'label_key' => self::FIELD_LABEL_PREFIX.'unreturned_reason',
                    'old' => null,
                    'new' => (string) ($change['unreturned_reason'] ?? ''),
                    'type' => 'text',
                ];
            }

            $bulkChanges[(int) ($change['option_id'] ?? 0)] = $rows;
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
     * 분할로 생긴 잔여 행의 수량을 읽습니다. (전/후 표의 "전" 을 복원하기 위해)
     *
     * 잔여 행을 못 찾으면 0 을 돌려준다 — 그러면 전/후가 같아져 그 줄이 그려지지 않을 뿐,
     * 상태 전이 줄은 그대로 남는다(로그 전체가 사라지지 않게 하는 것이 여기서의 우선순위다).
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @param  int  $remainderId  잔여 행 ID
     * @return int 잔여 수량
     */
    private function remainderQuantityOf(
        OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim,
        int $remainderId,
    ): int {
        $relation = match (true) {
            $claim instanceof OrderExchange => 'exchangeOptions',
            $claim instanceof OrderReturn => 'returnOptions',
            $claim instanceof OrderRefund => 'refundOptions',
            default => null,
        };

        if ($relation === null) {
            return 0;
        }

        $claim->loadMissing($relation);

        $row = $claim->{$relation}->firstWhere('id', $remainderId);

        if ($row === null) {
            return 0;
        }

        return (int) ($row->exchange_quantity ?? $row->return_quantity ?? $row->quantity ?? 0);
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
            default => RefundStatusEnum::class,
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
     * @return void
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
     * @return void
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
     * 신청 내용 수정(사유·상세 사유·환불 계좌·교환 재발송지)을 기록합니다. (D13 고객 · S15 운영자 대리)
     *
     * 사유가 바뀌면 귀책과 배송비(누가 내는가)가 함께 바뀐다 — 한 요청을 한 줄로 남기고 바뀐 항목마다
     * 전/후를 `changes` 에 싣는다. 운영자가 고객 대신 고친 수정은 문구부터 다르게 남겨, 목록에서 줄만 봐도
     * 고객이 고친 것인지 운영자가 고친 것인지 가려진다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  수정된 신청서
     * @param  array<string, array{before: mixed, after: mixed, masked?: bool}>  $changes  바뀐 항목 (서비스가 실제로 바뀐 것만 싣는다)
     * @param  array{operator?: bool, processed_by?: int|null}  $context  부가 정보
     * @return void
     */
    public function handleRequestUpdated(OrderExchange|OrderReturn|OrderRefund $claim, array $changes = [], array $context = []): void
    {
        if ($changes === []) {
            return;
        }

        $type = $this->claimTypeOf($claim);
        $operator = (bool) ($context['operator'] ?? false);

        $this->logActivity($this->actionFor($type, 'request_update'), [
            'loggable' => $claim,
            'description_key' => self::DESCRIPTION_PREFIX.$this->descriptionFor($type, $operator ? 'request_update_on_behalf' : 'request_update'),
            'description_params' => $this->identityParams($claim),
            'properties' => [
                'claim_type' => $type->value,
                'operator' => $operator,
                'processed_by' => $context['processed_by'] ?? null,
                'fields' => array_keys($changes),
            ],
            'changes' => $this->buildRequestChanges($type, $changes),
        ]);
    }

    /**
     * 환불 실행(돈이 나간 사건)을 기록합니다.
     *
     * 같은 순간의 `approved → completed` 전이는 `status_changed` 가 따로 남긴다 — 이
     * 항목은 전이가 아니라 **실행 결과**(실제 환불액·PG 거래번호)를 남기는 자리다.
     *
     * @param  OrderRefund  $refund  실행된 환불신청서
     * @return void
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
     * @return void
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
     * @return void
     */
    public function handlePaymentRefunded(
        OrderExchange|OrderReturn $claim,
        ClaimPayment $payment,
        float $refundAmount = 0.0,
    ): void {
        $this->logActivity('claim_payment.refund', [
            'loggable' => $payment,
            'description_key' => self::DESCRIPTION_PREFIX.'claim_payment_refund_amount',
            // 이번에 되돌린 금액을 문구에 싣는다 — 부분 환급이면 결제 줄 금액과 달라 번호만으로는 얼마를 돌려줬는지 알 수 없다.
            'description_params' => [
                'payment_number' => (string) ($payment->payment_number ?? ''),
                'amount' => ecommerce_format_price($refundAmount, $payment->currency_code),
            ],
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
     * @return void
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
     * @return void
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
            // 개별 삭제는 파일까지 즉시 지우고 되돌릴 수 없다 — 지워진 사진이 고객이 낸
            // 증빙이었는지 운영자가 대리로 올린 것이었는지를 여기 남기지 않으면 분쟁 판정의
            // 근거가 사후에 사라진다(행이 이미 없어 loggable 로도 되찾지 못한다).
            'uploader_type' => $image->uploader_type?->value,
        ];
    }

    /**
     * 상품 행 송장 변경을 기록합니다. (「송장만 저장」 · 전이와 함께 쓴 송장)
     *
     * 로그 한 줄이 한 요청이다. 행마다 「택배사 · 운송장」 전/후를 `changes` 에 싣는다 — 같은 운송장을
     * 가진 다른 행은 바뀌지 않으므로(R3) 바뀐 행만 남는다. 행 수량과 분할 내역은 properties 에 남긴다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @param  string  $direction  방향 값 (collection|redelivery)
     * @param  array<int, array<string, mixed>>  $changes  바뀐 행 (item_id · quantity · previous · new)
     * @param  array<string, mixed>  $context  부가 정보 (source · split)
     * @return void
     */
    public function handleTrackingUpdated(OrderExchange|OrderReturn $claim, string $direction, array $changes = [], array $context = []): void
    {
        if ($changes === []) {
            return;
        }

        $type = $this->claimTypeOf($claim);
        $carrierNames = $this->carrierNamesFor($changes);
        $bulkChanges = [];

        foreach ($changes as $change) {
            $bulkChanges[(int) ($change['item_id'] ?? 0)] = [[
                'field' => $direction.'_tracking',
                'label_key' => self::FIELD_LABEL_PREFIX.$direction.'_tracking',
                'old' => $this->trackingText($change['previous'] ?? [], $carrierNames),
                'new' => $this->trackingText($change['new'] ?? [], $carrierNames),
                'type' => 'text',
            ]];
        }

        $this->logActivity($this->actionFor($type, 'tracking_update'), [
            'loggable' => $claim,
            'description_key' => self::DESCRIPTION_PREFIX.'claim_'.$type->value.'_'.$direction.'_tracking_update',
            'description_params' => $this->identityParams($claim) + ['count' => (string) count($changes)],
            'properties' => [
                'claim_type' => $type->value,
                'direction' => $direction,
                'source' => $context['source'] ?? null,
                'items' => $changes,
                'split' => $context['split'] ?? [],
            ],
            'changes' => $bulkChanges,
        ]);
    }

    /**
     * 운송장 단위 도착 처리를 기록합니다. (배송 정보 탭 [도착 처리] — R9)
     *
     * 도착으로 옮겨진 상품 행의 상태 변화는 `option_status_changed` 가 따로 남긴다 — 이 줄은 「어느
     * 운송장을 도착 처리했는가」 라는 사실을 남긴다(선교환 회수는 상태가 바뀌지 않아 이 줄뿐이다).
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @param  string  $direction  방향 값
     * @param  int  $carrierId  택배사 ID
     * @param  string  $trackingNumber  운송장 번호
     * @param  array{succeeded?: array<int, int>, skipped?: array<int, mixed>}  $result  처리 결과
     * @return void
     */
    public function handleTrackingDelivered(OrderExchange|OrderReturn $claim, string $direction, int $carrierId, string $trackingNumber, array $result = []): void
    {
        $type = $this->claimTypeOf($claim);

        $this->logActivity($this->actionFor($type, 'tracking_deliver'), [
            'loggable' => $claim,
            'description_key' => self::DESCRIPTION_PREFIX.'claim_'.$type->value.'_'.$direction.'_tracking_deliver',
            'description_params' => $this->identityParams($claim) + [
                'tracking_number' => $trackingNumber,
                'count' => (string) count($result['succeeded'] ?? []),
            ],
            'properties' => [
                'claim_type' => $type->value,
                'direction' => $direction,
                'carrier_id' => $carrierId,
                'tracking_number' => $trackingNumber,
                'succeeded' => $result['succeeded'] ?? [],
                'skipped' => $result['skipped'] ?? [],
            ],
        ]);
    }

    /**
     * 교환 진행 방식(일반·선교환) 변경을 기록합니다. (D1)
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  string  $from  이전 방식 값
     * @param  string  $to  새 방식 값
     * @param  int|null  $by  바꾼 관리자 ID
     * @return void
     */
    public function handleExchangeModeChanged(OrderExchange $exchange, string $from, string $to, ?int $by = null): void
    {
        $this->logActivity('claim_exchange.mode_change', [
            'loggable' => $exchange,
            'description_key' => self::DESCRIPTION_PREFIX.'claim_exchange_mode_change',
            'description_params' => $this->identityParams($exchange),
            'properties' => [
                'claim_type' => ClaimTypeEnum::EXCHANGE->value,
                'from' => $from,
                'to' => $to,
                'processed_by' => $by,
            ],
            'changes' => [
                $this->enumFieldChange(
                    'exchange_mode',
                    self::FIELD_LABEL_PREFIX.'exchange_mode',
                    ExchangeModeEnum::class,
                    $from,
                    $to,
                ),
            ],
        ]);
    }

    /**
     * 뒤늦은 재고 복구를 기록합니다. (S13)
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @param  array<int, array{id: int, quantity: int}>  $restored  복구한 상품 행
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return void
     */
    public function handleStockRestored(OrderExchange|OrderReturn $claim, array $restored, ?int $processedBy = null): void
    {
        $type = $this->claimTypeOf($claim);

        $this->logActivity($this->actionFor($type, 'stock_restore'), [
            'loggable' => $claim,
            'description_key' => self::DESCRIPTION_PREFIX.'claim_'.$type->value.'_stock_restore',
            'description_params' => $this->identityParams($claim) + [
                'count' => (string) count($restored),
                'quantity' => (string) array_sum(array_column($restored, 'quantity')),
            ],
            'properties' => [
                'claim_type' => $type->value,
                'restored' => $restored,
                'processed_by' => $processedBy,
            ],
        ]);
    }

    /**
     * 송장 변경 내역의 택배사 이름을 한 번에 읽습니다. (행마다 조회하지 않는다)
     *
     * @param  array<int, array<string, mixed>>  $changes  바뀐 행
     * @return array<int, string> 택배사 ID => 이름
     */
    private function carrierNamesFor(array $changes): array
    {
        $ids = [];

        foreach ($changes as $change) {
            foreach (['previous', 'new'] as $side) {
                if (($change[$side]['carrier_id'] ?? null) !== null) {
                    $ids[] = (int) $change[$side]['carrier_id'];
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        $names = [];

        foreach ($this->carrierRepository->findByIds($ids) as $carrier) {
            $names[(int) $carrier->id] = (string) $carrier->getLocalizedName();
        }

        return $names;
    }

    /**
     * 송장 한쪽(전 또는 후)을 「택배사 · 운송장」 문자열로 만듭니다. (없으면 빈 문자열)
     *
     * @param  array{carrier_id?: int|null, tracking_number?: string|null}  $tracking  송장
     * @param  array<int, string>  $carrierNames  택배사 이름 맵
     * @return string 표시 문자열
     */
    private function trackingText(array $tracking, array $carrierNames): string
    {
        $number = $tracking['tracking_number'] ?? null;

        if ($number === null || $number === '') {
            return '';
        }

        $carrierId = $tracking['carrier_id'] ?? null;
        $carrier = $carrierId !== null ? ($carrierNames[(int) $carrierId] ?? (string) $carrierId) : '';

        return trim($carrier.' · '.$number, ' ·');
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

    /**
     * 신청 내용 수정의 전/후를 코어 changes 형식으로 변환합니다.
     *
     * - 사유는 코드가 아니라 **다국어 이름 원본**(`json`)을 싣는다 — 화면이 표시 시점 로케일로 고르므로
     *   라벨을 박제하지 않으면서 운영자가 코드가 아닌 이름으로 읽는다. 사유가 카탈로그에서 지워졌으면 코드를 싣는다.
     * - 귀책·청구 방식은 enum 라벨 키, 배송비·청구액은 통화로 싣는다(금액 수정 기록과 같은 모양).
     * - 환불 계좌·재발송지는 전/후 값 없이 「변경됨」 라벨 키만 싣는다 — 서비스가 값을 떼어 보낸다.
     *
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @param  array<string, array{before: mixed, after: mixed, masked?: bool}>  $changes  바뀐 항목
     * @return array<int, array<string, mixed>>|null 변경 항목 (없으면 null)
     */
    private function buildRequestChanges(ClaimTypeEnum $type, array $changes): ?array
    {
        $rows = [];

        if (isset($changes['reason_code'])) {
            $names = $this->reasonNamesFor($type, [$changes['reason_code']['before'], $changes['reason_code']['after']]);
            $old = $changes['reason_code']['before'];
            $new = $changes['reason_code']['after'];

            $rows[] = [
                'field' => 'reason',
                'label_key' => self::FIELD_LABEL_PREFIX.'claim_reason',
                'old' => $old !== null ? ($names[$old] ?? $old) : null,
                'new' => $new !== null ? ($names[$new] ?? $new) : null,
                'type' => 'json',
            ];
        }

        foreach ([
            'fault_type' => ['type' => 'enum', 'enum' => ClaimReasonFaultTypeEnum::class],
            'reason_detail' => ['type' => 'text'],
            'claim_shipping_fee' => ['type' => 'currency'],
            'total_charge_amount' => ['type' => 'currency'],
            'charge_method' => ['type' => 'enum', 'enum' => ClaimChargeMethodEnum::class],
        ] as $field => $meta) {
            if (! isset($changes[$field])) {
                continue;
            }

            $old = $changes[$field]['before'];
            $new = $changes[$field]['after'];
            $row = [
                'field' => $field,
                'label_key' => self::FIELD_LABEL_PREFIX.$field,
                'old' => $old,
                'new' => $new,
                'type' => $meta['type'],
            ];

            if ($meta['type'] === 'enum') {
                $enumClass = $meta['enum'];
                $row['old_label_key'] = $old !== null ? $enumClass::tryFrom($old)?->labelKey() : null;
                $row['new_label_key'] = $new !== null ? $enumClass::tryFrom($new)?->labelKey() : null;
            }

            $rows[] = $row;
        }

        foreach (['refund_bank', 'redelivery_address'] as $group) {
            if (! isset($changes[$group])) {
                continue;
            }

            // 값은 싣지 않는다 — enum 모양을 빌려 「변경됨」 라벨만 표시 시점 로케일로 번역되게 한다.
            $rows[] = [
                'field' => $group,
                'label_key' => self::FIELD_LABEL_PREFIX.$group,
                'old' => null,
                'new' => null,
                'type' => 'enum',
                'old_label_key' => null,
                'new_label_key' => 'sirsoft-ecommerce::activity_log.values.masked_change',
            ];
        }

        return $rows !== [] ? $rows : null;
    }

    /**
     * 사유 코드들의 다국어 이름 원본을 한 번에 읽습니다.
     *
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @param  array<int, mixed>  $codes  사유 코드 (null 포함 가능)
     * @return array<string, array<string, string>> 코드 => 다국어 이름
     */
    private function reasonNamesFor(ClaimTypeEnum $type, array $codes): array
    {
        $codes = array_values(array_filter($codes, fn ($code) => is_string($code) && $code !== ''));

        if ($codes === []) {
            return [];
        }

        $names = [];

        foreach ($this->claimReasonRepository->findByCodes($type->value, $codes) as $reason) {
            if (is_array($reason->name) && $reason->name !== []) {
                $names[(string) $reason->code] = $reason->name;
            }
        }

        return $names;
    }
}
