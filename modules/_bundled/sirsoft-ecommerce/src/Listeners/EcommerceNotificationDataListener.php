<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\ModuleManager;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCancelRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;

/**
 * 이커머스 알림 데이터 필터 리스너
 *
 * notification_definitions의 extract_data 필터를 처리하여
 * 알림 발송에 필요한 데이터와 컨텍스트를 제공합니다.
 * 수신자 결정은 notification_definitions.recipients 설정에 위임합니다.
 */
class EcommerceNotificationDataListener implements HookListenerInterface
{
    /**
     * @param  OrderCancelRepositoryInterface  $orderCancelRepository  주문 취소 이력 조회 (취소 사유 추출)
     * @param  CurrencyConversionService  $currencyConversionService  알림 금액의 결제 통화 환산
     */
    public function __construct(
        protected OrderCancelRepositoryInterface $orderCancelRepository,
        protected CurrencyConversionService $currencyConversionService
    ) {}

    /**
     * 주문의 base 통화 금액을 결제 통화(order_currency)로 환산해 포맷합니다.
     *
     * 주문 금액 컬럼은 base 통화 정수로 저장되므로, 알림에 표시할 금액은 주문 스냅샷
     * 환율로 결제 통화 금액으로 환산한 뒤 결제 통화 포맷('원'/'$'/'¥' 등)으로 표기한다.
     * 환율 미설정 등으로 환산 불가 시 base 통화 포맷으로 안전하게 폴백한다.
     *
     * @param  Order  $order  주문
     * @param  float|int  $baseAmount  base 통화 금액
     * @return string 결제 통화로 포맷된 금액 문자열
     */
    private function formatOrderChargeAmount(Order $order, float|int $baseAmount): string
    {
        $snapshot = $order->currency_snapshot ?? [];

        try {
            $charge = $this->currencyConversionService->resolveSnapshotPaymentCharge($baseAmount, $snapshot);

            return $this->currencyConversionService->formatPrice($charge['amount'], $charge['currency']);
        } catch (\InvalidArgumentException $e) {
            // 환율 미설정 등 환산 불가 — base 통화 포맷으로 폴백
            $baseCurrency = $snapshot['base_currency'] ?? $this->currencyConversionService->getDefaultCurrency();

            return $this->currencyConversionService->formatPrice($baseAmount, $baseCurrency);
        }
    }

    /**
     * 구독할 훅 목록을 반환합니다.
     *
     * @return array 구독 훅 목록
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.notification.extract_data' => [
                'method' => 'extractData',
                'priority' => 20,
                'type' => 'filter',
            ],
            'core.notification.filter_default_definitions' => [
                'method' => 'contributeDefaultDefinitions',
                'priority' => 20,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 훅 이벤트를 처리합니다.
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     */
    public function handle(...$args): void {}

    /**
     * 이커머스 모듈의 기본 알림 정의를 코어 리셋 로직에 제공합니다.
     *
     * @param  array  $definitions  현재까지 수집된 기본 정의 목록
     * @param  array  $context  type/channel 필터 컨텍스트
     * @return array 이커머스 시더 정의를 병합한 목록
     */
    public function contributeDefaultDefinitions(array $definitions, array $context = []): array
    {
        // module.php 의 getNotificationDefinitions() 가 SSoT — declarative getter 패턴
        /** @var Module $module */
        $module = app(ModuleManager::class)->getModule('sirsoft-ecommerce');
        if (! $module) {
            return $definitions;
        }

        $contributed = [];
        foreach ($module->getNotificationDefinitions() as $data) {
            $contributed[] = array_merge($data, [
                'extension_type' => 'module',
                'extension_identifier' => $module->getIdentifier(),
            ]);
        }

        return array_merge($definitions, $contributed);
    }

    /**
     * 알림 유형에 따라 데이터와 컨텍스트를 추출합니다.
     *
     * @param  array  $default  기본 extract_data 구조
     * @param  string  $type  알림 정의 유형
     * @param  array  $args  훅에서 전달된 원본 인수
     * @return array{notifiable: null, notifiables: null, data: array, context: array}
     */
    public function extractData(array $default, string $type, array $args): array
    {
        return match ($type) {
            'order_confirmed' => $this->extractOrderConfirmed($args),
            'order_pending_deposit' => $this->extractOrderPendingDeposit($args),
            'order_shipped' => $this->extractOrderShipped($args),
            'order_delivered' => $this->extractOrderDelivered($args),
            'order_completed' => $this->extractOrderCompleted($args),
            'order_cancelled' => $this->extractOrderCancelled($args),
            'new_order_admin' => $this->extractNewOrderAdmin($args),
            'inquiry_received' => $this->extractInquiryReceived($args),
            'inquiry_replied' => $this->extractInquiryReplied($args),
            'mileage_expiring_soon' => $this->extractMileageExpiringSoon($args),
            default => $default,
        };
    }

    // ──────────────────────────────────────────────
    // 주문자 알림 (4종)
    // ──────────────────────────────────────────────

    /**
     * 주문 확인(결제 완료) 알림 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$order]
     */
    private function extractOrderConfirmed(array $args): array
    {
        $order = $args[0] ?? null;
        if (! $order instanceof Order) {
            return $this->emptyResult();
        }

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => $this->buildOrderData($order),
            'context' => $this->buildOrderContext($order),
        ];
    }

    /**
     * 무통장입금 입금 안내 알림 데이터를 추출합니다.
     *
     * 발송 대상은 무통장입금(dbank) + 입금 필요액(total_due_amount) > 0 인 주문에 한정한다.
     * 전액 마일리지/예치금 충당 등으로 입금 필요액이 0이면 발송하지 않는다(빈 결과 → 미발송).
     * 입금 금액은 마일리지/예치금 차감 후 실제 입금액(total_due_amount)을 사용한다.
     *
     * @param  array  $args  훅 인수 [$order]
     */
    private function extractOrderPendingDeposit(array $args): array
    {
        $order = $args[0] ?? null;
        if (! $order instanceof Order) {
            return $this->emptyResult();
        }

        $payment = $order->payment;
        $paymentMethod = $payment?->payment_method;
        $paymentMethod = $paymentMethod instanceof \BackedEnum ? $paymentMethod->value : $paymentMethod;

        // 무통장입금(dbank) + 입금 필요액 > 0 인 경우에만 입금 안내 발송.
        // 입금 필요액 판정은 base 통화 금액 기준(소수 통화여도 양수면 청구 대상).
        if ($paymentMethod !== PaymentMethodEnum::DBANK->value || (float) $order->total_due_amount <= 0) {
            return $this->emptyResult();
        }

        $dueAt = $payment?->deposit_due_at;

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => $this->buildOrderData($order, [
                // 입금 안내액 = base 결제예정액을 결제 통화로 환산한 포맷('원'/'$' 등).
                'deposit_amount' => $this->formatOrderChargeAmount($order, (float) $order->total_due_amount),
                'bank_name' => $payment?->dbank_name ?? '',
                'account_number' => $payment?->dbank_account ?? '',
                'account_holder' => $payment?->dbank_holder ?? '',
                'depositor_name' => $payment?->depositor_name ?? '',
                'deposit_due_at' => $dueAt ? $dueAt->format('Y-m-d H:i') : '',
            ]),
            'context' => $this->buildOrderContext($order),
        ];
    }

    /**
     * 배송 시작 알림 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$order]
     */
    private function extractOrderShipped(array $args): array
    {
        $order = $args[0] ?? null;
        if (! $order instanceof Order) {
            return $this->emptyResult();
        }

        $shipping = $order->shippings()->latest()->first();

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => $this->buildOrderData($order, [
                'carrier_name' => $shipping?->carrier?->getLocalizedName() ?? '',
                'tracking_number' => $shipping?->tracking_number ?? '',
            ]),
            'context' => $this->buildOrderContext($order),
        ];
    }

    /**
     * 배송 완료 알림 데이터를 추출합니다.
     *
     * 배송완료 시점에도 운송장(carrier/tracking)을 동봉할 수 있도록 배송 시작과 동일하게
     * 최신 배송 레코드의 택배사/송장번호를 포함한다.
     *
     * @param  array  $args  훅 인수 [$order]
     */
    private function extractOrderDelivered(array $args): array
    {
        $order = $args[0] ?? null;
        if (! $order instanceof Order) {
            return $this->emptyResult();
        }

        $shipping = $order->shippings()->latest()->first();

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => $this->buildOrderData($order, [
                'carrier_name' => $shipping?->carrier?->getLocalizedName() ?? '',
                'tracking_number' => $shipping?->tracking_number ?? '',
            ]),
            'context' => $this->buildOrderContext($order),
        ];
    }

    /**
     * 구매 확정 알림 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$order]
     */
    private function extractOrderCompleted(array $args): array
    {
        $order = $args[0] ?? null;
        if (! $order instanceof Order) {
            return $this->emptyResult();
        }

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => $this->buildOrderData($order),
            'context' => $this->buildOrderContext($order),
        ];
    }

    /**
     * 주문 취소 알림 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$order, $cancelSnapshot?]
     */
    private function extractOrderCancelled(array $args): array
    {
        $order = $args[0] ?? null;
        if (! $order instanceof Order) {
            return $this->emptyResult();
        }

        $latestCancel = $this->orderCancelRepository->latestByOrderId($order->id);
        $cancelReason = $latestCancel?->cancel_reason ?? '';

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => $this->buildOrderData($order, [
                'cancel_reason' => $cancelReason,
            ]),
            'context' => $this->buildOrderContext($order),
        ];
    }

    // ──────────────────────────────────────────────
    // 관리자 알림 (2종)
    // ──────────────────────────────────────────────

    /**
     * 신규 주문 관리자 알림 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$order]
     */
    private function extractNewOrderAdmin(array $args): array
    {
        $order = $args[0] ?? null;
        if (! $order instanceof Order) {
            return $this->emptyResult();
        }

        $baseUrl = config('app.url');

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => '{recipient_name}',
                'app_name' => config('app.name'),
                'order_number' => $order->order_number,
                'customer_name' => $order->user?->name ?? $order->getOrdererName() ?? '',
                'total_amount' => number_format((float) $order->total_paid_amount > 0 ? $order->total_paid_amount : $order->total_amount).'원',
                'order_url' => "{$baseUrl}/admin/ecommerce/orders/{$order->order_number}",
                'site_url' => $baseUrl,
            ],
            'context' => [
                'trigger_user_id' => $order->user_id,
                'trigger_user' => $order->user,
            ],
        ];
    }

    /**
     * 상품 문의 접수 관리자 알림 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$inquiry]
     */
    private function extractInquiryReceived(array $args): array
    {
        $inquiry = $args[0] ?? null;
        if (! $inquiry instanceof ProductInquiry) {
            return $this->emptyResult();
        }

        $baseUrl = config('app.url');
        $product = $inquiry->product;

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => '{recipient_name}',
                'app_name' => config('app.name'),
                'product_name' => $product?->getLocalizedName() ?? ($inquiry->product_name_snapshot[app()->getLocale()] ?? ''),
                'customer_name' => $inquiry->user?->name ?? '',
                'inquiry_content' => mb_substr($inquiry->inquirable?->content ?? '', 0, 200),
                'inquiry_url' => "{$baseUrl}/admin/ecommerce/product-inquiries",
                'site_url' => $baseUrl,
            ],
            'context' => [
                'trigger_user_id' => $inquiry->user_id,
                'trigger_user' => $inquiry->user,
            ],
        ];
    }

    // ──────────────────────────────────────────────
    // 사용자 알림 (1종)
    // ──────────────────────────────────────────────

    /**
     * 문의 답변 완료 알림 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$inquiry]
     */
    private function extractInquiryReplied(array $args): array
    {
        $inquiry = $args[0] ?? null;
        if (! $inquiry instanceof ProductInquiry) {
            return $this->emptyResult();
        }

        $baseUrl = config('app.url');

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => $inquiry->user?->name ?? '',
                'app_name' => config('app.name'),
                'product_name' => $inquiry->product?->getLocalizedName() ?? ($inquiry->product_name_snapshot[app()->getLocale()] ?? ''),
                'inquiry_content' => mb_substr($inquiry->inquirable?->content ?? '', 0, 200),
                'inquiry_url' => "{$baseUrl}/mypage/inquiries",
                'site_url' => $baseUrl,
            ],
            'context' => [
                'trigger_user_id' => $inquiry->user_id,
                'trigger_user' => $inquiry->user,
                'related_users' => [
                    'author' => $inquiry->user,
                ],
            ],
        ];
    }

    // ──────────────────────────────────────────────
    // 헬퍼 메서드
    // ──────────────────────────────────────────────

    /**
     * 비회원 주문 조회 화면 경로
     *
     * 후속 이메일 발송 이슈가 비회원 주문 조회 URL을 만들 때 사용하는 경로 기준이다.
     * 비회원 본인 확인은 주문번호 + 전화번호 + 조회 비밀번호 입력을 전제로 하므로
     * 주문번호를 쿼리에 노출하지 않고 순수 경로만 제공한다.
     *
     * 경로는 비회원 주문 조회 라우트(templates/sirsoft-basic/routes.json) 및
     * OrderController::showByOrderNumber 의 비회원 redirect_to 와 동일하게 유지한다.
     */
    private const GUEST_ORDER_LOOKUP_PATH = '/shop/guest/orders';

    /**
     * 주문 알림 데이터 배열을 구성합니다.
     *
     * 회원 주문은 기존과 동일하게 회원명과 마이페이지 주문 상세 URL을 사용한다.
     * 비회원 주문은 회원 정보가 없으므로 주문자명을 배송지 기준으로 채우고,
     * 마이페이지 대신 비회원 주문 조회 화면 경로를 order_url로 사용한다.
     *
     * @param  Order  $order  주문 모델
     * @param  array  $extra  추가 변수
     */
    private function buildOrderData(Order $order, array $extra = []): array
    {
        $baseUrl = config('app.url');

        if ($order->isGuestOrder()) {
            $name = $order->getOrdererName() ?? '';
            $orderUrl = "{$baseUrl}".self::GUEST_ORDER_LOOKUP_PATH;
        } else {
            $name = $order->user?->name ?? '';
            $orderUrl = "{$baseUrl}/mypage/orders/{$order->order_number}";
        }

        // 표시 금액 = 결제완료액(있으면) 또는 주문 총액(base 통화)을 결제 통화로 환산한 포맷.
        $baseAmount = (float) $order->total_paid_amount > 0
            ? (float) $order->total_paid_amount
            : (float) $order->total_amount;

        // 배송지(국가 포함) 변수 (B6 — 운영자/고객이 해외주소 인지 가능)
        $shipping = $order->shippingAddress;
        $shippingCountryCode = $shipping?->recipient_country_code ?? '';
        $shippingCountryName = $this->localizeCountryName($shippingCountryCode);
        $shippingFullAddress = $shipping ? $shipping->getFullAddress() : '';

        return array_merge([
            'name' => $name,
            'app_name' => config('app.name'),
            'order_number' => $order->order_number,
            'total_amount' => $this->formatOrderChargeAmount($order, $baseAmount),
            'order_url' => $orderUrl,
            'site_url' => $baseUrl,
            // 배송지 변수 (B6)
            'shipping_recipient_name' => $shipping?->recipient_name ?? '',
            'shipping_recipient_phone' => $shipping?->recipient_phone ?? '',
            'shipping_country_code' => $shippingCountryCode,
            'shipping_country_name' => $shippingCountryName,
            'shipping_address' => $shippingFullAddress,
        ], $extra);
    }

    /**
     * 국가 코드를 현재 로케일 국가명으로 변환합니다. (B6)
     *
     * @param  string|null  $countryCode  ISO alpha-2 국가 코드
     * @return string 현지화 국가명 (미상 시 코드 자체, 빈값 시 빈 문자열)
     */
    private function localizeCountryName(?string $countryCode): string
    {
        if (empty($countryCode)) {
            return '';
        }

        $code = strtoupper($countryCode);
        $locale = app()->getLocale();
        $localizedNames = config('countries.localized_names', []);

        return $localizedNames[$locale][$code]
            ?? ($localizedNames['en'][$code] ?? $code);
    }

    /**
     * 주문 컨텍스트를 구성합니다.
     *
     * 비회원 주문은 회원 수신자(trigger_user_id)가 없으므로, 코어 알림 표준 키
     * `guest_recipient`({email, name, locale})를 제공한다. 코어
     * NotificationRecipientResolver 의 trigger_user 규칙이 user_id 가 없을 때
     * 이 키로 GuestNotifiable 을 생성해 회원과 동일한 발송 경로로 알림을 보낸다.
     * (사이트내 알림 등 비회원 미허용 채널은 코어 채널 게이트가 자동 차단)
     *
     * @param  Order  $order  주문 모델
     */
    private function buildOrderContext(Order $order): array
    {
        $context = [
            'trigger_user_id' => $order->user_id,
            'trigger_user' => $order->user,
        ];

        if ($order->isGuestOrder()) {
            $context['is_guest_order'] = true;
            $context['guest_orderer_email'] = $order->getOrdererEmail() ?? '';
            $context['guest_orderer_name'] = $order->getOrdererName() ?? '';

            // 코어 알림 표준 게스트 수신자 계약 — 회원과 동일한 발송 경로로 라우팅
            $context['guest_recipient'] = [
                'email' => $order->getOrdererEmail() ?? '',
                'name' => $order->getOrdererName() ?? '',
                'locale' => $order->getOrdererLocale(),
            ];
        }

        return $context;
    }

    /**
     * 빈 결과를 반환합니다.
     */
    /**
     * 소멸 예정 마일리지 알림 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$user, $amount, $currency, $expiresDate, $balance]
     */
    private function extractMileageExpiringSoon(array $args): array
    {
        $user = $args[0] ?? null;
        if (! $user instanceof User) {
            return $this->emptyResult();
        }

        $amount = (float) ($args[1] ?? 0);
        $currency = (string) ($args[2] ?? 'KRW');
        $expiresDate = (string) ($args[3] ?? '');
        $balance = (float) ($args[4] ?? 0);
        $baseUrl = config('app.url');

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => $user->name ?? '',
                'app_name' => config('app.name'),
                'amount' => number_format($amount),
                'currency' => $currency,
                'expires_date' => $expiresDate,
                'balance' => number_format($balance),
                'mileage_url' => "{$baseUrl}/mypage/mileage",
                'site_url' => $baseUrl,
            ],
            'context' => [
                'trigger_user_id' => $user->id,
                'trigger_user' => $user,
            ],
        ];
    }

    private function emptyResult(): array
    {
        return ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];
    }
}
