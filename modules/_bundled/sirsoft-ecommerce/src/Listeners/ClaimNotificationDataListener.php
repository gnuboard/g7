<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Helpers\TimezoneHelper;
use App\Services\NotificationRecipientCapability;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTrackingDirectionEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Listeners\Concerns\ResolvesClaimIdentity;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Support\ClaimTrackingGroups;
use Modules\Sirsoft\Ecommerce\Support\ClaimUrl;
use Modules\Sirsoft\Ecommerce\Support\RefundBreakdown;

/**
 * 클레임 알림 데이터 필터 리스너 (§4-8 — 29종 공용)
 *
 * `ClaimStatusNotificationListener` 가 발화한 알림 훅으로 코어가 알림을 보낼 때, 그 정의가
 * 쓰는 치환 변수와 수신자 컨텍스트를 만들어 준다. 주문 축의 `EcommerceNotificationDataListener`
 * 와 같은 필터(`sirsoft-ecommerce.notification.extract_data`)를 늦은 우선순위로 구독한다 —
 * 주문 축이 모르는 유형은 받은 값을 그대로 돌려주므로 두 리스너가 서로를 덮지 않는다.
 *
 * ## 변수 집합은 29종이 공유한다
 *
 * 유형·상태마다 다른 변수를 만들면 운영자가 템플릿을 고칠 때 어떤 변수가 살아 있는지 알
 * 수 없다. 그래서 모든 클레임 알림이 같은 키 집합을 받고, 그 상태에 해당 값이 없으면 빈
 * 문자열이 된다(치환 자리가 통째로 사라지는 대신 빈칸으로 남는다). 각 정의의 `variables`
 * 목록은 그 알림이 실제로 쓰는 것만 열거한다.
 *
 * ## 금액은 주문 통화 스냅샷으로 환산해 포맷한다
 *
 * 클레임 금액 컬럼은 주문의 기준 통화 정수다. 알림에 그대로 실으면 결제 통화가 다른 상점의
 * 고객이 자기가 낸 적 없는 단위의 숫자를 받는다. 주문이 동결한 스냅샷 환율로 환산하고
 * 그 통화 포맷으로 적는다(주문 알림과 같은 규칙).
 *
 * ## 비회원 링크는 상세가 아니라 조회 화면이다
 *
 * 비회원 신청서 상세는 조회 토큰을 전제한다 — 메일에서 눌러 들어온 브라우저에는 그 토큰이
 * 없어 상세 주소는 열리지 않는다. 그래서 비회원에게는 비회원 주문 조회 화면을 보낸다
 * (주문 알림이 이미 쓰는 경로 — `ClaimUrl::guestLookup()`).
 */
class ClaimNotificationDataListener implements HookListenerInterface
{
    use ResolvesClaimIdentity;

    /**
     * 클레임 링크를 관리자 주소로 올려도 되는지 가르는 권한.
     *
     * 역할 이름이 아니라 권한으로 판정한다 — 상점이 새 역할을 만들 때마다 리스너를
     * 고쳐야 하는 일이 없고, 그 화면을 못 여는 사람에게 관리자 주소를 주지 않는다.
     */
    private const ADMIN_CLAIM_PERMISSION = 'sirsoft-ecommerce.claims.read';

    /**
     * 상태 알림 키 규칙(`{유형}_{상태키}`) 밖의 클레임 알림 유형 — 철회·부분 처리·송장 등록·회수 지연
     *
     * @var array<int, string>
     */
    private const EXTRA_CLAIM_TYPES = [
        'claim_withdrawn',
        // 대리 철회 안내(고객 수신) — 빠지면 수신자 컨텍스트가 비어 고객에게 0통이 된다.
        'claim_withdrawn_on_behalf',
        'claim_partially_processed',
        'exchange_collection_tracking_registered',
        'exchange_redelivery_tracking_registered',
        'return_collection_tracking_registered',
        'exchange_collection_overdue',
    ];

    /**
     * 발행 시점 스냅샷에서 본문 변수로 그대로 싣는 키 (운송장 줄·기한·지연 일수 등)
     *
     * @var array<int, string>
     */
    private const SNAPSHOT_TEXT_KEYS = [
        'tracking_lines',
        'advance_exchange_notice',
        'overdue_days',
        'collection_due_date',
        'unreturned_item_lines',
    ];

    /**
     * @param  CurrencyConversionService  $currencyConversionService  알림 금액의 결제 통화 환산
     * @param  ClaimPaymentService  $claimPaymentService  미수납 청구액 산정 (SSoT)
     */
    public function __construct(
        protected CurrencyConversionService $currencyConversionService,
        protected ClaimPaymentService $claimPaymentService,
    ) {}

    /**
     * 구독할 훅 목록을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.notification.extract_data' => [
                'method' => 'extractData',
                // 주문 축(20) 뒤에 선다 — 서로 다른 유형만 처리하므로 순서 자체는 결과를
                // 바꾸지 않지만, 나중에 같은 유형이 겹치면 도메인 소유자가 마지막에 오는 편이
                // 진단하기 쉽다.
                'priority' => 25,
                'type' => 'filter',
            ],
            'sirsoft-ecommerce.notification.personalize_data' => [
                'method' => 'personalizeClaimData',
                'priority' => 25,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 훅 이벤트를 처리합니다. (필터 매핑을 사용하므로 미사용)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 클레임 알림 유형이면 데이터와 컨텍스트를 만들어 돌려줍니다.
     *
     * @param  array  $default  앞선 필터까지의 extract_data 구조
     * @param  string  $type  알림 정의 유형
     * @param  array  $args  훅 인수 [$claim, $snapshot] (부분 처리 안내의 두 번째 인자는 회차 요약)
     * @return array{notifiable: mixed, notifiables: mixed, data: array, context: array} 추출 결과
     */
    public function extractData(array $default, string $type, array $args): array
    {
        if (! $this->isClaimNotificationType($type)) {
            return $default;
        }

        $claim = $args[0] ?? null;

        if (! $claim instanceof OrderExchange && ! $claim instanceof OrderReturn && ! $claim instanceof OrderRefund) {
            return $default;
        }

        // 두 번째 훅 인자는 **발행 시점 스냅샷**이다(`ClaimStatusNotificationListener` 가 동기로
        // 캡처한 상태 값·미수납액, 부분 처리 안내는 이번 회차의 건수·결정 표기). 신청서에서 다시
        // 읽으면 큐가 잡을 때의 상태를 읽게 되어 **과거 사건의 알림에 지금 값이 실린다**(R24).
        $snapshot = is_array($args[1] ?? null) ? $args[1] : [];

        // 부분 처리 안내의 회차 요약은 본문 변수로 그대로 싣는다. 스냅샷 전용 키(상태 값·미수납액)는
        // 치환 변수가 아니라 라벨·금액을 만드는 재료라 빼고 싣는다.
        $summary = $type === 'claim_partially_processed'
            ? array_map(
                fn ($value) => is_scalar($value) ? (string) $value : '',
                array_diff_key($snapshot, array_flip(['status', 'outstanding_amount'])),
            )
            : [];

        // 운송장 줄·기한처럼 발행 시점에 고정한 본문 값은 그대로 싣는다(S10 — 발송 시점 재조회 금지).
        $snapshotText = [];

        foreach (self::SNAPSHOT_TEXT_KEYS as $key) {
            if (array_key_exists($key, $snapshot) && is_scalar($snapshot[$key])) {
                $snapshotText[$key] = (string) $snapshot[$key];
            }
        }

        $context = $this->buildClaimContext($claim);

        // 운영자에게 가는 「고객이 ~을(를) 신청했습니다」 는 고객이 낸 신청에만 나간다. 직권 접수·완료가 만든
        // 환불·대리 접수 환불도 같은 `notify_requested` 훅을 지나므로 그대로 두면 운영자 전원이 사실과 다른
        // 알림을 받는다(2026-10-07 실측). 고객 수신 정의(`_admin` 접미 없음)는 건드리지 않는다.
        if (str_ends_with($type, '_requested_admin') && ! $this->isCustomerFiled($claim)) {
            $context['skip'] = true;
        }

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => array_merge($this->buildClaimData($claim, $snapshot), $summary, $snapshotText),
            'context' => $context,
        ];
    }

    /**
     * 고객이 직접 낸 신청인지 판정합니다. (운영자 「신청 접수」 알림의 발송 조건)
     *
     * 교환·반품은 운영자 직권 접수 표지(`requested_by_admin`)가 없을 때, 환불은 출처가 고객 직접 접수(`direct`)일
     * 때만 고객 신청이다 — 반품·교환·취소 발 환불과 운영자 대리 접수(`admin`)는 운영자 처리의 결과다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @return bool 고객이 낸 신청이면 true
     */
    private function isCustomerFiled(OrderExchange|OrderReturn|OrderRefund $claim): bool
    {
        if ($claim instanceof OrderRefund) {
            return $claim->refund_source_type === RefundSourceTypeEnum::DIRECT;
        }

        return ! $claim->isAdminRequested();
    }

    /**
     * 수신자가 운영자면 클레임 링크를 관리자 화면 주소로 올립니다.
     *
     * **유형 이름으로 관리자 여부를 판정하지 않습니다.** 운영자는 관리자 화면에서 어떤
     * 알림의 수신자든 바꿀 수 있어(`claim_withdrawn` 을 `trigger_user` 로 바꾸는 등),
     * 유형으로 가르면 고객에게 `/admin/...` 주소를 보내게 됩니다 — 예외도 404 도 없이
     * 빈 화면이 열립니다. 그래서 **그 수신자가 실제로 그 화면을 열 수 있는지**로 가릅니다.
     *
     * > 큐 재로드 시점 오염을 막기 위해 `$context` 의 스칼라만 읽습니다 — `$args` 의
     * > 모델을 다시 읽으면 과거 사건의 알림에 "지금" 상태가 실립니다.
     *
     * @param  array  $data  앞선 필터까지의 알림 데이터
     * @param  object  $notifiable  수신자 (User 또는 GuestNotifiable)
     * @param  string  $type  알림 정의 유형
     * @param  array  $context  extract_data 가 실은 컨텍스트
     * @param  array  $args  원래 훅 인자 (읽지 않는다)
     * @return array 수신자별로 치환된 알림 데이터
     */
    public function personalizeClaimData(
        array $data,
        object $notifiable,
        string $type,
        array $context,
        array $args = [],
    ): array {
        if (! $this->isClaimNotificationType($type)) {
            return $data;
        }

        if (! app(NotificationRecipientCapability::class)->can($notifiable, self::ADMIN_CLAIM_PERMISSION)) {
            return $data;
        }

        $claimNumber = (string) ($context['claim_number'] ?? '');
        $data['claim_url'] = (string) config('app.url')
            .($claimNumber === '' ? ClaimUrl::ADMIN_LIST : ClaimUrl::adminDetail($claimNumber));

        return $data;
    }

    /**
     * 이 리스너가 담당하는 알림 유형인지 판정합니다.
     *
     * 유형 이름은 `{클레임유형}_{알림키}` 이며, 알림키 집합은
     * `ClaimStatusNotificationListener::notifiableStatuses()` 가 정본이다 — 여기서 접두만
     * 보고 통과시키면 `refund_` 로 시작하는 남의 유형까지 가로챌 수 있다.
     *
     * @param  string  $type  알림 정의 유형
     * @return bool 클레임 알림이면 true
     */
    private function isClaimNotificationType(string $type): bool
    {
        if (in_array($type, self::EXTRA_CLAIM_TYPES, true)) {
            return true;
        }

        // 운영자 수신 정의는 같은 사건에 `_admin` 접미로 나란히 선다 — 고객 본문과 관리자
        // 본문은 한 템플릿에 담기지 않기 때문이다. 변수 집합은 고객 정의와 같다.
        $base = str_ends_with($type, '_admin') ? substr($type, 0, -strlen('_admin')) : $type;

        foreach (ClaimStatusNotificationListener::notifiableStatuses() as $claimType => $keys) {
            foreach ($keys as $key) {
                if ($base === $claimType.'_'.$key) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 클레임 알림 치환 변수를 만듭니다. (29종 공용 키 집합)
     *
     * 상태 라벨과 미수납액은 발행 시점 스냅샷이 있으면 그 값을 쓴다. 스냅샷이 없는 발행자
     * (외부 확장이 알림 훅을 직접 쏘는 경우)만 발송 시점 값으로 채운다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @param  array<string, mixed>  $snapshot  발행 시점 스냅샷 (status · outstanding_amount)
     * @return array<string, string> 치환 변수
     */
    private function buildClaimData(OrderExchange|OrderReturn|OrderRefund $claim, array $snapshot = []): array
    {
        $claim->loadMissing(['order']);

        $order = $claim->order;
        $baseUrl = (string) config('app.url');
        $type = $this->claimTypeOf($claim);
        $claimNumber = (string) ($this->claimNumberOf($claim) ?? '');
        $isGuest = $claim->isGuestRequest();

        return array_merge([
            // 이름은 **언제나** 수신자 이름이다 — 코어가 수신자마다 치환한다.
            // 고객 수신 시 신청자 == 수신자이므로 종전과 같은 값이 되고, 신청자 이름이
            // 따로 필요한 본문은 `requester_name` 을 쓴다.
            'name' => '{recipient_name}',
            'app_name' => (string) config('app.name'),
            'claim_number' => $claimNumber,
            'claim_type_label' => $type->label(),
            'order_number' => (string) ($order?->order_number ?? ''),
            'status_label' => is_string($snapshot['status'] ?? null)
                ? $this->claimStatusLabelFor($claim, $snapshot['status'])
                : $this->claimStatusLabel($claim),
            'reason_label' => method_exists($claim, 'getReasonLabel') ? $claim->getReasonLabel() : '',
            'reject_reason' => (string) ($claim->reject_reason ?? ''),
            'admin_memo' => (string) ($claim->admin_memo ?? ''),
            'requester_name' => $this->resolveRequesterName($claim, $order),
            // 기본값은 **가장 안전한 쪽**(고객 링크)이다. 운영자에게 보낼 관리자 주소는
            // 수신자가 정해진 뒤 personalize 가 올린다.
            'claim_url' => $baseUrl.$this->resolveClaimPath($claimNumber, $isGuest),
            'site_url' => $baseUrl,
        ], $this->buildAmountData($claim, $order, $snapshot), $this->buildShippingData($claim));
    }

    /**
     * 신청서 상세로 이어질 앱 내 경로를 반환합니다.
     *
     * @param  string  $claimNumber  클레임 번호
     * @param  bool  $isGuest  비회원 신청 여부
     * @return string 앱 내 경로
     */
    private function resolveClaimPath(string $claimNumber, bool $isGuest): string
    {
        if ($claimNumber === '') {
            return $isGuest ? ClaimUrl::guestLookup() : ClaimUrl::MEMBER_LIST;
        }

        return $isGuest ? ClaimUrl::guestLookup() : ClaimUrl::memberDetail($claimNumber);
    }

    /**
     * 금액 축 치환 변수를 만듭니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @param  Order|null  $order  주문 (통화 스냅샷 출처)
     * @param  array<string, mixed>  $snapshot  발행 시점 스냅샷 (outstanding_amount)
     * @return array<string, string> 금액 변수
     */
    private function buildAmountData(OrderExchange|OrderReturn|OrderRefund $claim, ?Order $order, array $snapshot = []): array
    {
        if ($claim instanceof OrderRefund) {
            // 화면(`ClaimResource::refund_total`)과 같은 식이다 — 현금 + 마일리지.
            // `refund_shipping_amount` 는 가산 항목이 아니라 `refund_amount` 안에 이미 든 값의
            // **내역**이다: 배송비 재판정 차는 재계산 결제액에 포함돼 있고
            // (`OrderAdjustmentService`), 교환의 회수비는 음수로 기록되지만 그 몫 역시
            // 환불액에서 이미 빠졌다(`OrderExchangeService`). 더하면 유형마다 반대 방향으로
            // 어긋난다 — 교환은 실제보다 적게, 반품·취소는 많게 알리며 오류는 남지 않는다.
            $refundTotal = (float) $claim->refund_amount
                + (float) $claim->refund_points_amount;

            // 수단은 「실제로 돈이 간 수단」 이다(S7) — 신청서 수단(결제수단에서 정해진 PG)에 마일리지 합계를 묶으면
            // 마일리지로만 돌려준 환불이 「PG사 환불 15,000원」 으로 알려진다. 합계 `refund_amount` 는 하위호환으로 둔다.
            $format = fn (float $amount): string => $this->formatClaimAmount($order, $amount);
            $cash = RefundBreakdown::cashAmount($claim);
            $points = RefundBreakdown::pointsAmount($claim);

            return [
                'charge_amount' => '',
                'outstanding_amount' => '',
                'paid_amount' => '',
                'refund_amount' => $format($refundTotal),
                'refund_method_label' => RefundBreakdown::methodLabel($claim),
                'refund_cash_amount' => $cash > 0.0 ? $format($cash) : '',
                'refund_points_amount' => $points > 0.0 ? $format($points) : '',
                'refund_breakdown' => RefundBreakdown::lines($claim, $format),
            ];
        }

        $totalCharge = (float) $claim->total_charge_amount;
        $outstanding = is_numeric($snapshot['outstanding_amount'] ?? null)
            ? (float) $snapshot['outstanding_amount']
            : $this->claimPaymentService->outstandingAmount($claim);

        return [
            'charge_amount' => $this->formatClaimAmount($order, $totalCharge),
            'outstanding_amount' => $this->formatClaimAmount($order, $outstanding),
            // 결제 완료 안내는 청구액 전액이 수납된 시점에만 나간다 — 그 시점의 수납액은 청구액이다.
            'paid_amount' => $this->formatClaimAmount($order, $totalCharge),
            'refund_amount' => '',
            'refund_method_label' => '',
            'refund_cash_amount' => '',
            'refund_points_amount' => '',
            'refund_breakdown' => '',
        ];
    }

    /**
     * 회수·재발송 운송장 치환 변수를 만듭니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @return array<string, string> 배송 변수
     */
    private function buildShippingData(OrderExchange|OrderReturn|OrderRefund $claim): array
    {
        $empty = [
            'tracking_lines' => '',
            'advance_exchange_notice' => '',
            'request_origin_notice' => '',
            'withdraw_origin_notice' => $this->withdrawOriginNotice($claim),
            'collection_due_date' => '',
            'overdue_days' => '',
            'unreturned_item_lines' => '',
        ];

        if ($claim instanceof OrderRefund) {
            return $empty;
        }

        // 기본값은 **지금** 운송장 줄이다 — 발행 시점 스냅샷이 있으면 `extractData()` 가 덮는다(S10).
        // 방향은 신청서 상태로 고른다: 발송 단계면 재발송, 그 전이면 회수.
        $status = $claim instanceof OrderExchange ? $claim->exchange_status?->value : $claim->return_status?->value;
        $direction = $claim instanceof OrderExchange && in_array($status, ['redelivery_ready', 'redelivering', 'redelivered', 'completed'], true)
            ? ClaimTrackingDirectionEnum::REDELIVERY
            : ClaimTrackingDirectionEnum::COLLECTION;

        return [
            'tracking_lines' => ClaimTrackingGroups::lines($claim, $direction),
            'request_origin_notice' => $claim->isAdminRequested()
                ? __('sirsoft-ecommerce::messages.claims.notifications.request_origin_admin')
                : '',
            // 반송 기한 날짜는 사용자 타임존 날짜다(화면·배너와 같은 날) — UTC 날짜면 저녁 기한이 하루 당겨진다.
            'collection_due_date' => $claim instanceof OrderExchange && $claim->collection_due_at !== null
                ? (string) TimezoneHelper::toUserDateString($claim->collection_due_at)
                : '',
        ] + $empty;
    }

    /**
     * 판매자 대리 철회 안내 문구를 만듭니다. (S14 — 고객 본인 철회면 빈 문자열)
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @return string 안내 문구
     */
    private function withdrawOriginNotice(OrderExchange|OrderReturn|OrderRefund $claim): string
    {
        return $claim->withdrawn_by_admin !== null
            ? __('sirsoft-ecommerce::messages.claims.notifications.withdraw_origin_admin')
            : '';
    }

    /**
     * 기준 통화 금액을 주문 결제 통화로 환산해 포맷합니다.
     *
     * 주문을 읽지 못하거나 환율이 없으면 기준 통화 포맷으로 폴백한다 — 알림은 금액 표기를
     * 위해 발송 자체를 막을 자리가 아니다.
     *
     * @param  Order|null  $order  주문
     * @param  float  $baseAmount  기준 통화 금액
     * @return string 포맷된 금액
     */
    private function formatClaimAmount(?Order $order, float $baseAmount): string
    {
        $snapshot = $order?->currency_snapshot ?? [];

        try {
            $charge = $this->currencyConversionService->resolveSnapshotPaymentCharge($baseAmount, $snapshot);

            return $this->currencyConversionService->formatPrice($charge['amount'], $charge['currency']);
        } catch (\InvalidArgumentException $e) {
            $baseCurrency = $snapshot['base_currency'] ?? $this->currencyConversionService->getDefaultCurrency();

            return $this->currencyConversionService->formatPrice($baseAmount, $baseCurrency);
        }
    }

    /**
     * 신청자 표시 이름을 반환합니다.
     *
     * 회원은 계정 이름, 비회원은 주문의 주문자명이다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @param  Order|null  $order  주문
     * @return string 신청자 이름 (해석 불가 시 빈 문자열)
     */
    private function resolveRequesterName(OrderExchange|OrderReturn|OrderRefund $claim, ?Order $order): string
    {
        if (! $claim->isGuestRequest()) {
            $claim->loadMissing('requestedByUser');

            $name = $claim->requestedByUser?->name;

            if ($name !== null && $name !== '') {
                return $name;
            }
        }

        return (string) ($order?->getOrdererName() ?? '');
    }

    /**
     * 수신자 해석 컨텍스트를 만듭니다.
     *
     * 회원 신청은 `trigger_user_id`, 비회원 신청은 코어 표준 키 `guest_recipient` 로
     * 넘긴다 — 코어 `NotificationRecipientResolver` 의 `trigger_user` 규칙이 user_id 가
     * 없을 때 이 키로 `GuestNotifiable` 을 만들어 회원과 같은 경로로 보낸다(비회원 미허용
     * 채널은 코어 채널 게이트가 막는다).
     *
     * 관리자 수신 알림도 같은 컨텍스트를 싣는다 — `exclude_trigger_user` 규칙이 신청자를
     * 관리자 목록에서 빼려면 그가 누구인지 알아야 한다.
     *
     * **"누가 이 알림을 받아야 하는가" 는 알림의 관심사이고, 답은 언제나 주문의 주인이다.**
     * 신청자 컬럼(`requested_by`)은 "누가 신청했는가" 라는 도메인 값이라 운영자 대리 접수와
     * 비회원 신청이 똑같이 null 인 것이 의도된 계약이다(`OrderRefund` 참조). 그 값을 그대로
     * 수신자로 쓰면 **운영자가 회원 주문을 대리 취소했을 때 고객이 환불 알림을 못 받는다** —
     * 그래서 수신자 축에서만 주문 주인으로 폴백한다(도메인 값은 건드리지 않는다).
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @return array<string, mixed> 수신자 컨텍스트
     */
    private function buildClaimContext(OrderExchange|OrderReturn|OrderRefund $claim): array
    {
        $claim->loadMissing(['order', 'requestedByUser']);

        $order = $claim->order;

        $context = [
            'trigger_user_id' => $claim->requested_by ?? $order?->user_id,
            'trigger_user' => $claim->requestedByUser ?? $order?->user,
            'claim_type' => $this->claimTypeOf($claim)->value,
            'claim_number' => $this->claimNumberOf($claim),
        ];

        $isGuestClaim = $claim->isGuestRequest();

        if ($isGuestClaim) {
            $context['is_guest_claim'] = true;
        }

        // 게스트 재료는 **관리자 알림에도** 싣는다 — 운영자가 수신자 규칙을 바꾸면 같은 정의로
        // 고객도 받을 수 있고, 그때 재료가 없으면 조용히 0명이 된다.
        //
        // 회원 주문인데 그 계정이 사라진 경우(탈퇴·삭제)에는 싣지 않는다 — 코어 정책상 삭제된
        // 회원의 알림은 주문서에 남은 연락처로 대신 보내지 않는다(발송 이력에 사유만 남는다).
        if ($isGuestClaim) {
            $context['guest_recipient'] = [
                'email' => (string) ($order?->getOrdererEmail() ?? ''),
                'name' => (string) ($order?->getOrdererName() ?? ''),
                'locale' => $order?->getOrdererLocale(),
            ];
        }

        return $context;
    }
}
