<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Support\ClaimNotificationHooks;

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
 * 이 리스너는 자동발견으로 큐 디스패치되고 워커가 신청서를 PK 로 재조회한다. 큐가 밀린
 * 사이 신청서가 또 전이되면 모델의 현재 상태는 "지금" 값이라 엉뚱한 알림이 나간다.
 * 그래서 매핑은 발화 측이 넘긴 스칼라 목표 상태로만 판정한다(주문 축 N1 과 동일).
 *
 * ## 철회는 관리자 수신 1종으로 모은다
 *
 * 신청자가 스스로 철회한 사실을 그 신청자에게 되돌려 보낼 이유가 없다 — 알아야 하는 쪽은
 * 운영자다. 유형 3종이 같은 알림(`claim_withdrawn`)을 쓰며 유형은 본문 변수로 실린다.
 */
class ClaimStatusNotificationListener implements HookListenerInterface
{
    /** 알림 전용 훅 접두 (도메인 훅과 구분) — 이름의 출처는 `ClaimNotificationHooks` 다 */
    public const NOTIFY_HOOK_PREFIX = ClaimNotificationHooks::PREFIX;

    /** 철회 알림(관리자 수신) 훅 — 유형 구분 없이 한 종 */
    public const WITHDRAWN_HOOK = ClaimNotificationHooks::WITHDRAWN;

    /**
     * 부분 처리 안내(고객 수신) 훅 — 유형 구분 없이 한 종.
     *
     * 운영자가 신청 상품 중 일부만 처리하면 헤더 상태가 그대로라 기존 상태 알림이 발화하지
     * 않는다. 그때 고객이 자기 신청이 어떻게 갈렸는지 알 수 있는 유일한 통로다.
     */
    public const PARTIALLY_PROCESSED_HOOK = ClaimNotificationHooks::PARTIALLY_PROCESSED;

    /**
     * 구독할 훅 목록을 반환합니다.
     *
     * @return array<string, array{method: string, priority: int}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.claim.exchange.requested' => ['method' => 'handleExchangeRequested', 'priority' => 10],
            'sirsoft-ecommerce.claim.return.requested' => ['method' => 'handleReturnRequested', 'priority' => 10],
            'sirsoft-ecommerce.claim.refund.requested' => ['method' => 'handleRefundRequested', 'priority' => 10],
            'sirsoft-ecommerce.claim.exchange.status_changed' => ['method' => 'handleStatusChange', 'priority' => 10],
            'sirsoft-ecommerce.claim.return.status_changed' => ['method' => 'handleStatusChange', 'priority' => 10],
            'sirsoft-ecommerce.claim.refund.status_changed' => ['method' => 'handleStatusChange', 'priority' => 10],
            // 상품 단위 전이의 **사실**을 받아, 「부분 처리인가 · 고객에게 알릴 것인가」를
            // 이 축이 단독으로 판정한다. 서비스는 판정하지 않는다.
            ClaimNotificationHooks::ITEMS_TRANSITIONED => ['method' => 'handleItemsTransitioned', 'priority' => 10],
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
            HookManager::doAction(self::WITHDRAWN_HOOK, $claim);

            return;
        }

        $this->fireNotify($type, $target, $claim);
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
            HookManager::doAction($hook, $claim);
        }
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

        $rows = $this->claimItemRows($this->reloadWithItems($claim));

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

            if ($value !== $target && ! $isFinal) {
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
            ],
        );
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
