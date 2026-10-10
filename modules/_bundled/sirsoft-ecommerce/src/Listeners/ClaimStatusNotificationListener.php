<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookManager;
use App\Helpers\TimezoneHelper;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTrackingDirectionEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Support\ClaimItemCollectionState;
use Modules\Sirsoft\Ecommerce\Support\ClaimNotificationHooks;
use Modules\Sirsoft\Ecommerce\Support\ClaimTrackingGroups;

/**
 * 클레임 상태전이 알림 매핑 리스너 (§4-8 — 고객 가시 상태 전수 29종)
 *
 * 교환·반품·환불 서비스가 발화하는 도메인 훅(`claim.{type}.requested`,
 * `claim.{type}.status_changed`)을 받아 알림 전용 훅(`claim.{type}.notify_{status}`)
 * 으로 매핑 발화한다. 주문 축의 `OrderStatusNotificationListener` 와 같은 구조다.
 *
 * ## 왜 도메인 훅에 알림 정의를 직접 붙이지 않는가
 *
 * 알림 정의 한 건은 훅 이름 하나에 묶인다(`NotificationHookListener::registerDynamicHooks`).
 * `status_changed` 는 모든 전이에서 발화하므로 여기에 13종 정의를 묶으면 어떤 전이에서든
 * 13통이 한꺼번에 나간다. 그래서 상태마다 고유한 알림 훅이 하나씩 필요하다.
 * `claim.{type}.approved` 같은 기존 구체 훅은 일부 상태에만 있고(승인·반려·철회·완료)
 * 활동 로그도 함께 구독하는 도메인 훅이라, 알림 축은 `notify_` 접두 훅으로 분리한다.
 *
 * ## 매핑 기준은 전이 시점에 캡처된 목표 상태다
 *
 * 매핑은 발화 측이 넘긴 스칼라 목표 상태로만 판정한다(주문 축 N1 과 동일) — 모델의 현재
 * 상태는 "지금" 값이라, 그 사이 신청서가 또 전이되면 엉뚱한 알림이 나간다.
 *
 * ## 알림 본문 값은 발행 시점에 스칼라로 싣는다 (동기 구독)
 *
 * 알림 발송(코어 `NotificationHookListener`)은 큐에서 돈다. 그 시점에 신청서를 다시 읽어
 * 상태 라벨·미수납액·부분 처리 건수를 만들면 **과거 사건의 알림에 지금 숫자가 실린다**
 * (오류도 로그도 없다). 그래서 이 리스너는 `'sync' => true` 로 구독해 서비스가 훅을 쏜
 * 그 자리(커밋 직후)에서 값을 계산하고, 알림 훅의 두 번째 인자로 스칼라 스냅샷을 넘긴다.
 * 데이터 필터(`ClaimNotificationDataListener`)는 그 스냅샷을 우선한다.
 *
 * 동기로 돌기 때문에 스냅샷 계산이 실패해도 요청을 깨뜨리지 않는다 — 경고만 남기고 스냅샷
 * 없이 알림을 넘긴다(데이터 필터가 종전처럼 발송 시점 값으로 채운다).
 *
 * ## 철회는 관리자 수신 1종으로 모은다
 *
 * 신청자가 스스로 철회한 사실을 그 신청자에게 되돌려 보낼 이유가 없다 — 알아야 하는 쪽은
 * 운영자다. 유형 3종이 같은 알림(`claim_withdrawn`)을 쓰며 유형은 본문 변수로 실린다.
 *
 * 예외는 **운영자가 고객 요청으로 대신 철회한 경우**(S14 — `withdrawn_by_admin`)다. 고객이 한 철회가
 * 아니므로 고객은 자기 신청이 왜 사라졌는지 알 길이 없다 — 이때만 고객 수신 알림
 * (`claim_withdrawn_on_behalf`)을 관리자 수신 알림과 함께 보낸다.
 */
class ClaimStatusNotificationListener implements HookListenerInterface
{
    /** 알림 전용 훅 접두 (도메인 훅과 구분) — 이름의 출처는 `ClaimNotificationHooks` 다 */
    public const NOTIFY_HOOK_PREFIX = ClaimNotificationHooks::PREFIX;

    /** 철회 알림(관리자 수신) 훅 — 유형 구분 없이 한 종 */
    public const WITHDRAWN_HOOK = ClaimNotificationHooks::WITHDRAWN;

    /** 대리 철회 안내(고객 수신) 훅 — 운영자가 고객 요청으로 대신 철회했을 때만. 유형 구분 없이 한 종 */
    public const WITHDRAWN_ON_BEHALF_HOOK = ClaimNotificationHooks::WITHDRAWN_ON_BEHALF;

    /**
     * 부분 처리 안내(고객 수신) 훅 — 유형 구분 없이 한 종.
     *
     * 운영자가 신청 상품 중 일부만 처리하면 헤더 상태가 그대로라 기존 상태 알림이 발화하지
     * 않는다. 그때 고객이 자기 신청이 어떻게 갈렸는지 알 수 있는 유일한 통로다.
     */
    public const PARTIALLY_PROCESSED_HOOK = ClaimNotificationHooks::PARTIALLY_PROCESSED;

    /**
     * 부분 처리 안내를 보낼 심사 결정 (S11 — 물류 단계는 제외)
     *
     * @var array<int, string>
     */
    private const REVIEW_DECISIONS = ['approved', 'rejected', 'on_hold', 'payment_pending'];

    /**
     * 「아직 검토 중」 으로 셀 심사 단계 상태 (S11)
     *
     * @var array<int, string>
     */
    private const REVIEW_STAGES = ['requested', 'payment_pending', 'paid', 'on_hold'];

    /**
     * 구독할 훅 목록을 반환합니다.
     *
     * 전부 `'sync' => true` 다 — 알림 본문 값을 발행 시점에 계산하기 위해서다(클래스 docblock).
     *
     * @return array<string, array{method: string, priority: int, sync: bool}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.claim.exchange.requested' => ['method' => 'handleExchangeRequested', 'priority' => 10, 'sync' => true],
            'sirsoft-ecommerce.claim.return.requested' => ['method' => 'handleReturnRequested', 'priority' => 10, 'sync' => true],
            'sirsoft-ecommerce.claim.refund.requested' => ['method' => 'handleRefundRequested', 'priority' => 10, 'sync' => true],
            'sirsoft-ecommerce.claim.exchange.status_changed' => ['method' => 'handleStatusChange', 'priority' => 10, 'sync' => true],
            'sirsoft-ecommerce.claim.return.status_changed' => ['method' => 'handleStatusChange', 'priority' => 10, 'sync' => true],
            'sirsoft-ecommerce.claim.refund.status_changed' => ['method' => 'handleStatusChange', 'priority' => 10, 'sync' => true],
            // 상품 단위 전이의 **사실**을 받아, 「부분 처리인가 · 고객에게 알릴 것인가」를
            // 이 축이 단독으로 판정한다. 서비스는 판정하지 않는다. 건수는 발행 시점에 센다.
            ClaimNotificationHooks::ITEMS_TRANSITIONED => ['method' => 'handleItemsTransitioned', 'priority' => 10, 'sync' => true],
            // 상품 행 송장이 새로 적히거나 바뀐 사실 — 신청서 상태가 그대로면 상태 알림이 나가지 않으므로
            // 송장 등록 알림이 운송장 줄을 전한다(§3-10).
            'sirsoft-ecommerce.claim.exchange.after_tracking_update' => ['method' => 'handleTrackingUpdated', 'priority' => 10, 'sync' => true],
            'sirsoft-ecommerce.claim.return.after_tracking_update' => ['method' => 'handleTrackingUpdated', 'priority' => 10, 'sync' => true],
            // 선교환 회수 지연(배치 발화) → 관리자 알림 1회(D2).
            'sirsoft-ecommerce.claim.exchange.collection_overdue' => ['method' => 'handleCollectionOverdue', 'priority' => 10, 'sync' => true],
            // 결제완료(`paid`)를 경유하지 않은 결제 확정 — 보류에서 승인해 낸 추가결제 등. 경유한 건은
            // 상태 알림이 이미 알렸으므로 이 축은 경유하지 않은 건만 받는다.
            'sirsoft-ecommerce.claim.exchange.payment_completed' => ['method' => 'handlePaymentCompleted', 'priority' => 10, 'sync' => true],
            'sirsoft-ecommerce.claim.return.payment_completed' => ['method' => 'handlePaymentCompleted', 'priority' => 10, 'sync' => true],
        ];
    }

    /**
     * 기본 핸들러 (getSubscribedHooks 의 method 매핑을 사용하므로 미사용).
     *
     * @param  mixed  ...$args  훅 인수
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 알림을 보낼 상태 목록을 유형별로 반환합니다. (§4-8 전수 29종의 정본)
     *
     * 값은 `notify_{상태}` 훅과 알림 정의 `{유형}_{알림키}` 의 접미가 된다. 상태 값과
     * 알림 키가 다른 둘(`payment_pending` → `payment_requested`, `paid` →
     * `payment_completed`)은 화면 라벨이 아니라 고객이 받는 안내의 성격을 따랐다.
     *
     * @return array<string, array<string, string>> 유형 값 => (상태 값 => 알림 키 접미)
     */
    public static function notifiableStatuses(): array
    {
        return [
            ClaimTypeEnum::EXCHANGE->value => [
                ExchangeStatusEnum::REQUESTED->value => 'requested',
                ExchangeStatusEnum::PAYMENT_PENDING->value => 'payment_requested',
                ExchangeStatusEnum::PAID->value => 'payment_completed',
                ExchangeStatusEnum::APPROVED->value => 'approved',
                ExchangeStatusEnum::ON_HOLD->value => 'on_hold',
                ExchangeStatusEnum::REJECTED->value => 'rejected',
                ExchangeStatusEnum::COLLECTION_READY->value => 'collection_ready',
                ExchangeStatusEnum::COLLECTING->value => 'collecting',
                ExchangeStatusEnum::COLLECTED->value => 'collected',
                ExchangeStatusEnum::REDELIVERY_READY->value => 'redelivery_ready',
                ExchangeStatusEnum::REDELIVERING->value => 'redelivering',
                ExchangeStatusEnum::REDELIVERED->value => 'redelivered',
                ExchangeStatusEnum::COMPLETED->value => 'completed',
            ],
            ClaimTypeEnum::RETURN->value => [
                ReturnStatusEnum::REQUESTED->value => 'requested',
                ReturnStatusEnum::PAYMENT_PENDING->value => 'payment_requested',
                ReturnStatusEnum::PAID->value => 'payment_completed',
                ReturnStatusEnum::APPROVED->value => 'approved',
                ReturnStatusEnum::ON_HOLD->value => 'on_hold',
                ReturnStatusEnum::REJECTED->value => 'rejected',
                ReturnStatusEnum::COLLECTION_READY->value => 'collection_ready',
                ReturnStatusEnum::COLLECTING->value => 'collecting',
                ReturnStatusEnum::COLLECTED->value => 'collected',
                ReturnStatusEnum::COMPLETED->value => 'completed',
            ],
            // 환불신청서의 `processing`(PG 요청 중)은 고객에게 알릴 사건이 아니다 —
            // 몇 초 뒤 `completed` 나 `on_hold` 로 갈리며 그 결과만 통지한다.
            ClaimTypeEnum::REFUND->value => [
                RefundStatusEnum::REQUESTED->value => 'requested',
                RefundStatusEnum::APPROVED->value => 'approved',
                RefundStatusEnum::ON_HOLD->value => 'on_hold',
                RefundStatusEnum::REJECTED->value => 'rejected',
                RefundStatusEnum::COMPLETED->value => 'completed',
            ],
        ];
    }

    /**
     * 상태 전이가 아닌 **사건** 알림의 발화 훅을 반환합니다. (송장 등록 3종 · 선교환 회수 지연)
     *
     * 상태 알림(`notifiableStatuses()`)과 달리 상태가 바뀌지 않아도 나간다 — 「송장만 저장」 으로 운송장이
     * 새로 적혔을 때, 반송 기한이 지났을 때. 정의(`module.php`)와 1:1 이어야 한다(구조 테스트가 대조한다).
     *
     * @return array<string, string> 발화 훅 => 알림 정의 type
     */
    public static function eventNotifyHooks(): array
    {
        $hooks = [];

        foreach ([
            [ClaimTypeEnum::EXCHANGE, 'collection_tracking_registered'],
            [ClaimTypeEnum::EXCHANGE, 'redelivery_tracking_registered'],
            [ClaimTypeEnum::RETURN, 'collection_tracking_registered'],
            [ClaimTypeEnum::EXCHANGE, 'collection_overdue'],
        ] as [$type, $event]) {
            $hooks[ClaimNotificationHooks::forStatus($type->value, $event)] = $type->value.'_'.$event;
        }

        return $hooks;
    }

    /**
     * 유형·상태에 대응하는 알림 전용 훅 이름을 반환합니다.
     *
     * @param  string  $claimType  클레임 유형 값 (exchange|return|refund)
     * @param  string  $status  전이 목표 상태 값
     * @return string|null 알림 훅 이름 (알림 대상이 아니면 null)
     */
    public static function notifyHookFor(string $claimType, string $status): ?string
    {
        $suffix = self::notifiableStatuses()[$claimType][$status] ?? null;

        return $suffix === null ? null : self::NOTIFY_HOOK_PREFIX.$claimType.'.notify_'.$suffix;
    }

    /**
     * 교환 신청 접수 훅을 알림 훅으로 옮깁니다.
     *
     * @param  OrderExchange  $exchange  접수된 교환 신청서
     * @return void
     */
    public function handleExchangeRequested(OrderExchange $exchange): void
    {
        $this->fireNotify(ClaimTypeEnum::EXCHANGE->value, ExchangeStatusEnum::REQUESTED->value, $exchange);
    }

    /**
     * 반품 신청 접수 훅을 알림 훅으로 옮깁니다.
     *
     * @param  OrderReturn  $return  접수된 반품 신청서
     * @return void
     */
    public function handleReturnRequested(OrderReturn $return): void
    {
        $this->fireNotify(ClaimTypeEnum::RETURN->value, ReturnStatusEnum::REQUESTED->value, $return);
    }

    /**
     * 환불 신청 접수 훅을 알림 훅으로 옮깁니다.
     *
     * ## 자동 전이로 이어지는 접수는 알리지 않는다 (#599 후속 §4-2)
     *
     * 접수 직후 자동 전이가 승인·완료까지 밟는 건은 그 **마지막 상태에서 1건**만 보낸다.
     * 여기서 억제하지 않으면 한 번의 취소로 접수 + 마지막 상태 2통이 나간다.
     * 반품·교환 완료가 만든 환불신청서는 플래그가 없어 그대로 알린다 — 그 접수는
     * 자동 전이로 이어지지 않고 운영자의 환불 실행을 기다리는 별개 사건이다.
     *
     * @param  OrderRefund  $refund  접수된 환불 신청서
     * @param  Order|null  $order  주문 (훅 2번째 인수 — 이 리스너는 쓰지 않는다)
     * @param  array<string, mixed>  $context  접수 컨텍스트 (suppress_notification)
     * @return void
     */
    public function handleRefundRequested(OrderRefund $refund, ?Order $order = null, array $context = []): void
    {
        if ((bool) ($context['suppress_notification'] ?? false)) {
            return;
        }

        $this->fireNotify(ClaimTypeEnum::REFUND->value, RefundStatusEnum::REQUESTED->value, $refund);
    }

    /**
     * 클레임 상태전이를 알림 훅으로 매핑 발화합니다.
     *
     * ## 자동 전이의 중간 단계는 알리지 않는다 (#599 후속 §4-2)
     *
     * 자동 전이(`OrderRefundRequestService::autoAdvance()`)는 한 번의 취소로
     * `접수 → 승인 → 완료` 세 단계를 실제로 밟는다 — 그래야 활동로그가 단계별로 남는다
     * (D16). 그런데 그 단계마다 알림을 보내면 고객은 취소 한 번에 **문자 3통**을 받는다.
     * D16 이 요구한 것은 활동로그이지 알림이 아니므로, 중간 전이는 컨텍스트에 실린
     * `suppress_notification` 로 끄고 **마지막 상태에서 1건**만 보낸다.
     *
     * 이 판정을 알림 리스너에만 두는 것이 요점이다 — 활동로그 리스너는 같은 훅을 우선순위만
     * 달리해 구독하므로(priority 10 / 20) 플래그를 읽지 않아 세 줄이 그대로 남는다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  전이된 신청서
     * @param  string|null  $previous  전이 전 상태 값
     * @param  string|null  $target  전이 시점에 캡처한 목표 상태 값 (매핑 기준)
     * @param  array<string, mixed>  $context  전이 컨텍스트 (suppress_notification)
     * @return void
     */
    public function handleStatusChange(
        OrderExchange|OrderReturn|OrderRefund $claim,
        ?string $previous = null,
        ?string $target = null,
        array $context = [],
    ): void {
        if ($target === null || $target === $previous) {
            return;
        }

        if ((bool) ($context['suppress_notification'] ?? false)) {
            return;
        }

        $type = $claim->claimType()->value;

        // 철회는 유형 구분 없이 관리자 수신 1종으로 모은다.
        if ($this->isWithdrawnStatus($type, $target)) {
            $snapshot = $this->publishSnapshot($claim, $target);
            HookManager::doAction(self::WITHDRAWN_HOOK, $claim, $snapshot);

            // 운영자가 고객 요청으로 대신 철회했으면 고객에게도 알린다 — 고객이 한 철회가 아니라서
            // 이 알림이 없으면 고객은 신청이 사라진 이유를 모른다. 철회는 종착 상태라 모델의 철회자
            // 기록이 발행 뒤에 바뀌지 않는다(상태처럼 캡처할 필요가 없다).
            if ($claim->withdrawn_by_admin !== null) {
                HookManager::doAction(self::WITHDRAWN_ON_BEHALF_HOOK, $claim, $snapshot);
            }

            return;
        }

        $this->fireNotify($type, $target, $claim);
    }

    /**
     * 결제 확정 훅을 결제 완료 알림으로 옮깁니다 — `paid` 를 경유하지 않은 건만.
     *
     * 결제 확정은 결제대기 건을 `paid` 로 옮긴 뒤 훅을 쏜다. 그 전이가 상태 알림
     * (`notify_payment_completed`)을 이미 보냈으므로 신청서가 `paid` 면 여기서는 보내지 않는다.
     * 보류(`on_hold`)에서 [승인]으로 요청한 추가결제는 결제대기를 거치지 않아 `paid` 전이가 없다 —
     * 그대로 두면 결제가 끝났는데도 고객·운영자 어느 쪽에도 결제 완료 알림이 가지 않는다.
     *
     * @param  OrderExchange|OrderReturn  $claim  결제가 확정된 신청서
     * @param  mixed  $payment  결제 건 (훅 2번째 인수 — 이 리스너는 쓰지 않는다)
     * @return void
     */
    public function handlePaymentCompleted(OrderExchange|OrderReturn $claim, mixed $payment = null): void
    {
        $status = $this->headerStatusValue($claim);

        if ($status === 'paid') {
            return;
        }

        $hook = self::notifyHookFor($claim->claimType()->value, 'paid');

        if ($hook !== null) {
            // 본문 상태는 발행 시점의 실제 헤더 상태다(이 건은 결제완료 상태를 거치지 않았다).
            HookManager::doAction($hook, $claim, $this->publishSnapshot($claim, $status));
        }
    }

    /**
     * 목표 상태가 철회인지 판정합니다.
     *
     * @param  string  $claimType  클레임 유형 값
     * @param  string  $target  목표 상태 값
     * @return bool 철회면 true
     */
    private function isWithdrawnStatus(string $claimType, string $target): bool
    {
        return $target === match ($claimType) {
            ClaimTypeEnum::EXCHANGE->value => ExchangeStatusEnum::WITHDRAWN->value,
            ClaimTypeEnum::RETURN->value => ReturnStatusEnum::WITHDRAWN->value,
            default => RefundStatusEnum::WITHDRAWN->value,
        };
    }

    /**
     * 매핑된 알림 훅이 있으면 발화합니다.
     *
     * @param  string  $claimType  클레임 유형 값
     * @param  string  $status  상태 값
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @return void
     */
    private function fireNotify(string $claimType, string $status, OrderExchange|OrderReturn|OrderRefund $claim): void
    {
        $hook = self::notifyHookFor($claimType, $status);

        if ($hook !== null) {
            HookManager::doAction($hook, $claim, $this->publishSnapshot($claim, $status));
        }
    }

    /**
     * 알림 본문에 쓸 값을 발행 시점에 스칼라로 캡처합니다.
     *
     * 알림 발송은 큐에서 돌아 그때 신청서를 다시 읽으면 "지금" 상태가 실린다. 상태 값과
     * 미수납 청구액을 여기서 고정해 알림 훅의 두 번째 인자로 넘긴다.
     *
     * 계산이 실패하면 상태 값만 싣는다 — 이 리스너는 동기라 예외를 올리면 이미 커밋된 요청이
     * 오류 응답이 된다. 빠진 값은 데이터 필터가 발송 시점 값으로 채운다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @param  string  $status  발행 시점의 상태 값
     * @return array{status: string, outstanding_amount?: float} 스냅샷
     */
    private function publishSnapshot(OrderExchange|OrderReturn|OrderRefund $claim, string $status): array
    {
        $snapshot = ['status' => $status];

        if ($claim instanceof OrderRefund) {
            return $snapshot;
        }

        // 운송장 줄도 발행 시점 값으로 고정한다(S10) — 큐가 알림을 잡을 때 송장이 바뀌었으면
        // 다른 번호가 나간다. 한 신청서에 운송장이 여럿이면 줄마다 하나씩 싣는다.
        try {
            $direction = match ($status) {
                'collecting' => ClaimTrackingDirectionEnum::COLLECTION,
                'redelivering', 'redelivered' => ClaimTrackingDirectionEnum::REDELIVERY,
                default => null,
            };

            if ($direction !== null) {
                // 유형마다 상품 행 관계가 하나뿐이다 — 두 관계를 함께 즉시 로드하면 없는 관계에서 예외가 나
                // 운송장 줄이 스냅샷에서 조용히 빠진다(발송 시점 값으로 대체되어 S10 이 무너진다).
                $relation = $claim instanceof OrderExchange ? 'exchangeOptions' : 'returnOptions';
                $snapshot['tracking_lines'] = ClaimTrackingGroups::lines($claim->fresh([$relation]) ?? $claim, $direction);
            }

            if ($claim instanceof OrderExchange && $status === 'redelivering') {
                $snapshot['advance_exchange_notice'] = $this->advanceExchangeNotice($claim->fresh() ?? $claim);
            }
        } catch (\Throwable $e) {
            Log::warning('[claim.notification] 발행 시점 운송장 줄 캡처 실패 — 발송 시점 값으로 대체', [
                'claim_type' => $claim->claimType()->value,
                'claim_id' => $claim->getKey(),
                'error' => $e->getMessage(),
            ]);
        }

        try {
            // 미수납 산식의 단일 출처는 ClaimPaymentService 다. 생성자 주입 대신 여기서 해석하는 것은
            // 이 리스너가 청구액이 필요 없는 환불 축에서도 만들어지기 때문이다(그 경로엔 비용 0).
            $snapshot['outstanding_amount'] = (float) app(ClaimPaymentService::class)->outstandingAmount($claim);
        } catch (\Throwable $e) {
            Log::warning('[claim.notification] 발행 시점 미수납액 캡처 실패 — 발송 시점 값으로 대체', [
                'claim_type' => $claim->claimType()->value,
                'claim_id' => $claim->getKey(),
                'error' => $e->getMessage(),
            ]);
        }

        return $snapshot;
    }

    /**
     * 상품 단위 전이 결과를 받아 **부분 처리 안내를 보낼지 판정**합니다.
     *
     * 헤더 상태가 그대로면 기존 상태 알림이 발화하지 않는다. 그래서 「일부만 처리됐다」는
     * 사실은 이 알림이 아니면 고객에게 닿지 않는다.
     *
     * 판정 규칙 — 단순하게 간다(시간 묶음·지연 발송 구조를 만들지 않는다):
     * - 세는 단위는 **수량**이다. 분할이 일어나면 행 수가 늘어나므로 행으로 세면
     *   "1개 중 1개 처리, 1개 검토 중" 같은 문장이 나간다 — 고객이 신청한 단위는 수량이다.
     * - 검토 중인 수량이 남지 않았으면 「일부만」이 아니다 → 보내지 않는다. 그때는 헤더가
     *   움직여 기존 `{type}_approved`/`_rejected` 가 이미 나간다.
     * - [일괄 적용] 1회 = 1통. 상품마다 쏘지 않는다(3건 처리 = 메일 3통이 된다).
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @param  array{succeeded?: array<int, int>, skipped?: array<int, mixed>}  $result  전이 결과
     * @param  string  $target  이번 라운드의 목표 상태
     * @return void
     */
    public function handleItemsTransitioned(
        OrderExchange|OrderReturn|OrderRefund $claim,
        array $result,
        string $target,
    ): void {
        $succeeded = $result['succeeded'] ?? [];

        if ($succeeded === []) {
            return;
        }

        // 부분 처리 안내는 **심사 결정**(승인·반려·보류·결제대기)일 때만 보낸다(S11). 회수·발송 같은
        // 물류 단계는 송장 알림과 상태 알림이 맡는다 — 여기서 보내면 이미 승인된 상품을 두고
        // 「남은 상품은 계속 검토 중」 이라 안내하게 된다.
        if (! in_array($target, self::REVIEW_DECISIONS, true)) {
            return;
        }

        $reloaded = $this->reloadWithItems($claim);
        $rows = $this->claimItemRows($reloaded);

        if ($rows === []) {
            return;
        }

        $succeededIds = array_map('intval', $succeeded);
        $total = 0;
        $pending = 0;
        $processed = 0;

        foreach ($rows as $row) {
            $status = $row->option_status;
            $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
            $isFinal = $status instanceof \BackedEnum && method_exists($status, 'isFinal') && $status->isFinal();
            $quantity = (int) ($row->exchange_quantity ?? $row->return_quantity ?? $row->quantity ?? 0);

            $total += $quantity;

            if (in_array((int) $row->id, $succeededIds, true)) {
                $processed += $quantity;
            }

            // 「검토 중」 은 아직 심사 단계에 있는 수량만 센다(S11) — 이미 승인·회수 등으로 **앞선**
            // 상품을 검토 중으로 세면 끝난 결정을 다시 기다리라고 안내하게 된다.
            if ($value !== $target && ! $isFinal && in_array($value, self::REVIEW_STAGES, true)) {
                $pending += $quantity;
            }
        }

        if ($pending === 0) {
            return;
        }

        HookManager::doAction(
            ClaimNotificationHooks::PARTIALLY_PROCESSED,
            $claim,
            [
                'total_count' => (string) $total,
                'processed_count' => (string) $processed,
                'remaining_count' => (string) $pending,
                'approved_count' => (string) ($target === 'approved' ? $processed : 0),
                'rejected_count' => (string) ($target === 'rejected' ? $processed : 0),
                'decision_label' => $this->optionStatusLabel($claim, $target),
                'item_lines' => $this->claimItemLines($rows, $succeeded),
                // 본문의 상태 라벨도 이 시점의 헤더 상태로 고정한다(발송 시점 재조회 금지).
                'status' => $this->headerStatusValue($reloaded),
            ],
        );
    }

    /**
     * 상품 행 송장이 새로 적히거나 바뀐 사실을 송장 등록 알림으로 옮깁니다. (§3-10)
     *
     * 요청 1건당 1통이다. 같은 요청에서 신청서가 그 방향의 이동 상태(회수중·발송중)로 옮겨졌으면
     * 상태 알림이 운송장 줄을 싣고 나가므로 이 알림은 보내지 않는다(억제 규칙). 송장을 지운 행만
     * 있으면 알릴 것이 없다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @param  string  $direction  방향 값 (collection|redelivery)
     * @param  array<int, array<string, mixed>>  $changes  바뀐 행 (item_id · previous · new)
     * @param  array<string, mixed>  $context  부가 정보 (source · header_transitioned_to)
     * @return void
     */
    public function handleTrackingUpdated(OrderExchange|OrderReturn $claim, string $direction, array $changes, array $context = []): void
    {
        $directionEnum = ClaimTrackingDirectionEnum::tryFrom($direction);

        if ($directionEnum === null) {
            return;
        }

        $movingStatus = $directionEnum === ClaimTrackingDirectionEnum::COLLECTION ? 'collecting' : 'redelivering';

        if (($context['header_transitioned_to'] ?? null) === $movingStatus) {
            return;
        }

        $registeredIds = [];

        foreach ($changes as $change) {
            if (($change['new']['tracking_number'] ?? null) !== null) {
                $registeredIds[] = (int) ($change['item_id'] ?? 0);
            }
        }

        if ($registeredIds === []) {
            return;
        }

        $type = $claim->claimType()->value;
        $hook = ClaimNotificationHooks::forStatus($type, $directionEnum->value.'_tracking_registered');

        $snapshot = [
            'status' => $this->headerStatusValue($claim),
            'tracking_lines' => ClaimTrackingGroups::lines($claim->fresh() ?? $claim, $directionEnum, $registeredIds),
        ];

        if ($claim instanceof OrderExchange && $directionEnum === ClaimTrackingDirectionEnum::REDELIVERY) {
            $snapshot['advance_exchange_notice'] = $this->advanceExchangeNotice($claim->fresh() ?? $claim);
        }

        HookManager::doAction($hook, $claim, $snapshot);
    }

    /**
     * 선교환 회수 지연(배치 발화)을 관리자 알림으로 옮깁니다. (D2)
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  int  $overdueDays  지연 일수
     * @return void
     */
    public function handleCollectionOverdue(OrderExchange $exchange, int $overdueDays = 0): void
    {
        $fresh = $exchange->fresh(['exchangeOptions.orderOption']) ?? $exchange;
        $lines = [];

        foreach ($fresh->exchangeOptions as $item) {
            if ($item->option_status->isFinal() || $item->collected_at !== null || $item->unreturned_at !== null
                || ClaimItemCollectionState::hasCollectionTracking($item)) {
                continue;
            }

            $name = $item->orderOption?->product_name ?? '';

            if (is_array($name)) {
                $name = $name[app()->getLocale()] ?? $name[(string) config('app.fallback_locale', 'ko')] ?? (reset($name) ?: '');
            }

            $lines[] = trim((string) $name).' × '.(int) $item->exchange_quantity;
        }

        HookManager::doAction(
            ClaimNotificationHooks::forStatus(ClaimTypeEnum::EXCHANGE->value, 'collection_overdue'),
            $fresh,
            [
                'status' => $this->headerStatusValue($fresh),
                'overdue_days' => (string) $overdueDays,
                'collection_due_date' => TimezoneHelper::toUserDateString($fresh->collection_due_at) ?? '',
                'unreturned_item_lines' => implode("\n", $lines),
            ],
        );
    }

    /**
     * 선교환 반송 기한 안내 문구를 만듭니다. (일반 교환이면 빈 문자열)
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @return string 안내 문구
     */
    private function advanceExchangeNotice(OrderExchange $exchange): string
    {
        if (! $exchange->isAdvanceExchange()) {
            return '';
        }

        return __('sirsoft-ecommerce::messages.claims.notifications.advance_exchange_notice', [
            // 사용자 타임존 날짜 — 화면의 반송 기한과 같은 날을 알린다.
            'date' => TimezoneHelper::toUserDateString($exchange->collection_due_at) ?? '',
        ]);
    }

    /**
     * 신청서의 상품 행 목록을 유형과 무관하게 읽습니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @return array<int, object> 상품 행 목록
     */
    private function claimItemRows(OrderExchange|OrderReturn|OrderRefund $claim): array
    {
        $relation = $this->itemRelationFor($claim);
        $claim->loadMissing($relation);

        return $claim->{$relation}->all();
    }

    /**
     * 상품 행 관계를 함께 다시 읽습니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @return OrderExchange|OrderReturn|OrderRefund 갱신된 신청서
     */
    private function reloadWithItems(
        OrderExchange|OrderReturn|OrderRefund $claim,
    ): OrderExchange|OrderReturn|OrderRefund {
        return $claim->fresh([$this->itemRelationFor($claim)]) ?? $claim;
    }

    /**
     * 신청서 헤더의 현재 상태 값을 돌려줍니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @return string 상태 값 (해석 불가면 빈 문자열)
     */
    private function headerStatusValue(OrderExchange|OrderReturn|OrderRefund $claim): string
    {
        $status = match (true) {
            $claim instanceof OrderExchange => $claim->exchange_status,
            $claim instanceof OrderReturn => $claim->return_status,
            default => $claim->refund_status,
        };

        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }

    /**
     * 유형별 상품 행 관계 이름을 돌려줍니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @return string 관계 이름
     */
    private function itemRelationFor(OrderExchange|OrderReturn|OrderRefund $claim): string
    {
        return match (true) {
            $claim instanceof OrderExchange => 'exchangeOptions',
            $claim instanceof OrderReturn => 'returnOptions',
            default => 'refundOptions',
        };
    }

    /**
     * 이번에 처리된 상품의 표기 줄을 만듭니다. (알림 본문 `item_lines`)
     *
     * @param  array<int, object>  $rows  상품 행 목록
     * @param  array<int, int>  $succeeded  이번에 처리된 행 ID
     * @return string 줄바꿈으로 이은 상품 표기
     */
    private function claimItemLines(array $rows, array $succeeded): string
    {
        $ids = array_map('intval', $succeeded);
        $lines = [];

        foreach ($rows as $row) {
            if (! in_array((int) $row->id, $ids, true)) {
                continue;
            }

            $name = $row->orderOption?->product_name ?? '';

            if (is_array($name)) {
                $name = $name[app()->getLocale()] ?? $name[(string) config('app.fallback_locale', 'ko')] ?? (reset($name) ?: '');
            }

            $quantity = (int) ($row->exchange_quantity ?? $row->return_quantity ?? $row->quantity ?? 0);
            $lines[] = trim((string) $name).' × '.$quantity;
        }

        return implode('
', $lines);
    }

    /**
     * 상품 행 상태 값을 사람이 읽는 라벨로 바꿉니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @param  string|null  $statusValue  상태 값
     * @return string 라벨 (해석 불가면 원래 값)
     */
    private function optionStatusLabel(
        OrderExchange|OrderReturn|OrderRefund $claim,
        ?string $statusValue,
    ): string {
        if ($statusValue === null) {
            return '';
        }

        $status = match (true) {
            $claim instanceof OrderExchange => ExchangeStatusEnum::tryFrom($statusValue),
            $claim instanceof OrderReturn => ReturnStatusEnum::tryFrom($statusValue),
            default => RefundStatusEnum::tryFrom($statusValue),
        };

        return $status?->label() ?? $statusValue;
    }
}
