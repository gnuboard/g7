<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Ecommerce\Listeners\Concerns\ResolvesClaimIdentity;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Support\ClaimUrl;

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
        ];
    }

    /**
     * 훅 이벤트를 처리합니다. (필터 매핑을 사용하므로 미사용)
     *
     * @param  mixed  ...$args  훅 인수
     */
    public function handle(...$args): void {}

    /**
     * 클레임 알림 유형이면 데이터와 컨텍스트를 만들어 돌려줍니다.
     *
     * @param  array  $default  앞선 필터까지의 extract_data 구조
     * @param  string  $type  알림 정의 유형
     * @param  array  $args  훅 인수 [$claim]
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

        $isAdminNotification = $type === 'claim_withdrawn';

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => $this->buildClaimData($claim, $isAdminNotification),
            'context' => $this->buildClaimContext($claim, $isAdminNotification),
        ];
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
        if ($type === 'claim_withdrawn') {
            return true;
        }

        foreach (ClaimStatusNotificationListener::notifiableStatuses() as $claimType => $keys) {
            foreach ($keys as $key) {
                if ($type === $claimType.'_'.$key) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 클레임 알림 치환 변수를 만듭니다. (29종 공용 키 집합)
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @param  bool  $isAdminNotification  관리자 수신 알림 여부
     * @return array<string, string> 치환 변수
     */
    private function buildClaimData(
        OrderExchange|OrderReturn|OrderRefund $claim,
        bool $isAdminNotification,
    ): array {
        $claim->loadMissing(['order']);

        $order = $claim->order;
        $baseUrl = (string) config('app.url');
        $type = $this->claimTypeOf($claim);
        $claimNumber = (string) ($this->claimNumberOf($claim) ?? '');
        $isGuest = $claim->isGuestRequest();

        return array_merge([
            // 관리자 알림은 수신자마다 이름이 다르므로 코어가 치환한다(주문 관리자 알림과 동일).
            'name' => $isAdminNotification ? '{recipient_name}' : $this->resolveRequesterName($claim, $order),
            'app_name' => (string) config('app.name'),
            'claim_number' => $claimNumber,
            'claim_type_label' => $type->label(),
            'order_number' => (string) ($order?->order_number ?? ''),
            'status_label' => $this->claimStatusLabel($claim),
            'reason_label' => method_exists($claim, 'getReasonLabel') ? $claim->getReasonLabel() : '',
            'reject_reason' => (string) ($claim->reject_reason ?? ''),
            'admin_memo' => (string) ($claim->admin_memo ?? ''),
            'requester_name' => $this->resolveRequesterName($claim, $order),
            'claim_url' => $baseUrl.$this->resolveClaimPath($claimNumber, $isGuest, $isAdminNotification),
            'site_url' => $baseUrl,
        ], $this->buildAmountData($claim, $order), $this->buildShippingData($claim));
    }

    /**
     * 신청서 상세로 이어질 앱 내 경로를 반환합니다.
     *
     * @param  string  $claimNumber  클레임 번호
     * @param  bool  $isGuest  비회원 신청 여부
     * @param  bool  $isAdminNotification  관리자 수신 알림 여부
     * @return string 앱 내 경로
     */
    private function resolveClaimPath(string $claimNumber, bool $isGuest, bool $isAdminNotification): string
    {
        if ($claimNumber === '') {
            return $isGuest ? ClaimUrl::guestLookup() : ClaimUrl::MEMBER_LIST;
        }

        if ($isAdminNotification) {
            return ClaimUrl::adminDetail($claimNumber);
        }

        return $isGuest ? ClaimUrl::guestLookup() : ClaimUrl::memberDetail($claimNumber);
    }

    /**
     * 금액 축 치환 변수를 만듭니다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @param  Order|null  $order  주문 (통화 스냅샷 출처)
     * @return array<string, string> 금액 변수
     */
    private function buildAmountData(OrderExchange|OrderReturn|OrderRefund $claim, ?Order $order): array
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

            return [
                'charge_amount' => '',
                'outstanding_amount' => '',
                'paid_amount' => '',
                'refund_amount' => $this->formatClaimAmount($order, $refundTotal),
                'refund_method_label' => $claim->refund_method?->label() ?? '',
            ];
        }

        $totalCharge = (float) $claim->total_charge_amount;
        $outstanding = $this->claimPaymentService->outstandingAmount($claim);

        return [
            'charge_amount' => $this->formatClaimAmount($order, $totalCharge),
            'outstanding_amount' => $this->formatClaimAmount($order, $outstanding),
            // 결제 완료 안내는 청구액 전액이 수납된 시점에만 나간다 — 그 시점의 수납액은 청구액이다.
            'paid_amount' => $this->formatClaimAmount($order, $totalCharge),
            'refund_amount' => '',
            'refund_method_label' => '',
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
        if ($claim instanceof OrderRefund) {
            return [
                'collection_carrier_name' => '',
                'collection_tracking_number' => '',
                'redelivery_carrier_name' => '',
                'redelivery_tracking_number' => '',
            ];
        }

        $claim->loadMissing($claim instanceof OrderExchange
            ? ['collectionCarrier', 'redeliveryCarrier']
            : ['collectionCarrier']);

        return [
            'collection_carrier_name' => $claim->collectionCarrier?->getLocalizedName() ?? '',
            'collection_tracking_number' => (string) ($claim->collection_tracking_number ?? ''),
            'redelivery_carrier_name' => $claim instanceof OrderExchange
                ? ($claim->redeliveryCarrier?->getLocalizedName() ?? '')
                : '',
            'redelivery_tracking_number' => $claim instanceof OrderExchange
                ? (string) ($claim->redelivery_tracking_number ?? '')
                : '',
        ];
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
     * @param  OrderExchange|OrderReturn|OrderRefund  $claim  신청서
     * @param  bool  $isAdminNotification  관리자 수신 알림 여부
     * @return array<string, mixed> 수신자 컨텍스트
     */
    private function buildClaimContext(
        OrderExchange|OrderReturn|OrderRefund $claim,
        bool $isAdminNotification,
    ): array {
        $claim->loadMissing(['order', 'requestedByUser']);

        $order = $claim->order;

        $context = [
            'trigger_user_id' => $claim->requested_by,
            'trigger_user' => $claim->requestedByUser,
            'claim_type' => $this->claimTypeOf($claim)->value,
            'claim_number' => $this->claimNumberOf($claim),
        ];

        if ($claim->isGuestRequest() && ! $isAdminNotification) {
            $context['is_guest_claim'] = true;
            $context['guest_recipient'] = [
                'email' => (string) ($order?->getOrdererEmail() ?? ''),
                'name' => (string) ($order?->getOrdererName() ?? ''),
                'locale' => $order?->getOrdererLocale(),
            ];
        }

        return $context;
    }
}
