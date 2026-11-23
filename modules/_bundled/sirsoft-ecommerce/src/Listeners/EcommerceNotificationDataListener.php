<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\ModuleManager;
use App\Helpers\TimezoneHelper;
use App\Models\User;
use App\Notifications\GenericNotification;
use App\Services\NotificationRecipientCapability;
use App\Support\LocalizedValue;
use App\Support\ScreenUrl;
use App\Support\SiteUrl;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueSource;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCancelRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Support\CountryNames;
use Modules\Sirsoft\Ecommerce\Support\EcommerceScreenUrl;
use Modules\Sirsoft\Ecommerce\Support\MarketingConsentGate;
use Modules\Sirsoft\Ecommerce\Support\MemberDisplayName;
use Modules\Sirsoft\Ecommerce\Support\ShopPathResolver;

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
     * 주문 링크를 관리자 주소로 올려도 되는지 가르는 권한.
     *
     * 역할 이름이 아니라 권한으로 판정한다 — 상점이 새 역할을 만들 때마다 리스너를
     * 고쳐야 하는 일이 없고, 그 화면을 못 여는 사람에게 관리자 주소를 주지 않는다.
     */
    private const ADMIN_ORDER_PERMISSION = 'sirsoft-ecommerce.orders.read';

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
            'sirsoft-ecommerce.notification.personalize_data' => [
                'method' => 'personalizeOrderData',
                'priority' => 20,
                'type' => 'filter',
            ],
            'core.notification.filter_default_definitions' => [
                'method' => 'contributeDefaultDefinitions',
                'priority' => 20,
                'type' => 'filter',
            ],
            // 쿠폰별 발급 알림(#952)은 쿠폰 화면에서만 편집한다 — 알림 설정 목록에서 뺀다
            'core.notification_definition.filter_hidden_type_prefixes' => [
                'method' => 'hideCouponIssuedTypes',
                'priority' => 20,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 훅 이벤트를 처리합니다.
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     * @return void
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
     * 알림 설정 목록에서 쿠폰별 발급 알림 종류를 숨깁니다. (#952)
     *
     * @param  array<int, string>  $prefixes  앞선 구독자가 모은 숨길 접두
     * @param  array  $filters  목록 조회 조건
     * @return array<int, string> 숨길 접두
     */
    public function hideCouponIssuedTypes(array $prefixes = [], array $filters = []): array
    {
        $prefixes[] = Coupon::NOTIFICATION_TYPE_PREFIX;

        return array_values(array_unique($prefixes));
    }

    /**
     * 알림 유형에 따라 데이터와 컨텍스트를 추출합니다.
     *
     * @param  array  $default  기본 extract_data 구조
     * @param  string  $type  알림 정의 유형
     * @param  array  $args  훅에서 전달된 원본 인수
     * @return array{notifiable: null, notifiables: null, data: array, context: array} extract_data 결과 (notifiable·notifiables·data·context)
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
            // 결제완료·구매확정 관리자 알림은 신규 주문 관리자 알림과 변수가 같다(module.php 공통 빌더).
            'new_order_admin', 'order_confirmed_admin', 'order_completed_admin' => $this->extractNewOrderAdmin($args),
            'deposit_after_cancel_admin' => $this->extractDepositAfterCancelAdmin($args),
            'inquiry_received' => $this->extractInquiryReceived($args),
            'inquiry_replied' => $this->extractInquiryReplied($args),
            'mileage_expiring_soon' => $this->extractMileageExpiringSoon($args),
            // #952
            'coupon_expiring_soon' => $this->extractCouponExpiringSoon($args),
            'mileage_earned' => $this->extractMileageChange($args, true),
            'mileage_deducted' => $this->extractMileageChange($args, false),
            'order_partially_cancelled' => $this->extractOrderPartiallyCancelled($args),
            'review_replied' => $this->extractReviewReplied($args),
            default => str_starts_with($type, Coupon::NOTIFICATION_TYPE_PREFIX) ? $this->extractCouponIssued($args) : $default,
        };
    }

    // ──────────────────────────────────────────────
    // 주문자 알림 (4종)
    // ──────────────────────────────────────────────

    /**
     * 주문 확인(결제 완료) 알림 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$order]
     * @return array 추출 결과
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
     * @return array 추출 결과
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
                // 알림 변수 날짜는 사이트 시간대 — 요청자(관리자·고객) 시간대가 끼어들지 않게 고정.
                // 형식(Y-m-d H:i)은 기존 알림 템플릿 호환을 위해 유지한다.
                'deposit_due_at' => $dueAt ? $dueAt->copy()->setTimezone(TimezoneHelper::getSiteTimezone())->format('Y-m-d H:i') : '',
            ]),
            'context' => $this->buildOrderContext($order),
        ];
    }

    /**
     * 배송 시작 알림 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$order]
     * @return array 추출 결과
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
     * @return array 추출 결과
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
     * @return array 추출 결과
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
     * @return array 추출 결과
     */
    private function extractOrderCancelled(array $args): array
    {
        $order = $args[0] ?? null;
        if (! $order instanceof Order) {
            return $this->emptyResult();
        }

        $latestCancel = $this->orderCancelRepository->latestByOrderId($order->id);
        // 고른 사유는 코드(`cancel_reason_type`)로, 「기타」의 직접 입력만 `cancel_reason` 에 남는다 — 직접 입력만
        // 읽으면 목록 사유로 취소한 주문의 메일이 「취소 사유: 」 빈칸으로 나간다(S3-D8). 사유 이름을 싣고
        // 직접 입력이 있으면 괄호로 덧붙인다.
        $reasonLabel = $latestCancel !== null ? trim($latestCancel->getRefundReasonLabel()) : '';
        $reasonDetail = trim((string) ($latestCancel?->cancel_reason ?? ''));
        $cancelReason = match (true) {
            $reasonLabel !== '' && $reasonDetail !== '' && $reasonDetail !== $reasonLabel => $reasonLabel.' ('.$reasonDetail.')',
            $reasonLabel !== '' => $reasonLabel,
            default => $reasonDetail,
        };

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
     * 운영자용 주문 알림(신규 주문·결제완료·구매확정) 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$order]
     * @return array 추출 결과
     */
    private function extractNewOrderAdmin(array $args): array
    {
        $order = $args[0] ?? null;
        if (! $order instanceof Order) {
            return $this->emptyResult();
        }

        $baseUrl = SiteUrl::forOutboundLinks();

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => '{recipient_name}',
                'app_name' => config('app.name'),
                'order_number' => $order->order_number,
                // 받는 사람이 아닌 회원의 이름은 알림 영역의 표시 이름이다. 비회원 주문은 주문서에 적힌 주문자 이름이다.
                'customer_name' => MemberDisplayName::name($order->user, MemberDisplayName::AREA_NOTIFICATION) ?? $order->getOrdererName() ?? '',
                // 구매자 알림과 같은 경로로 포맷한다 — 원화를 이어 붙이면 기준통화가
                // KRW 가 아닌 상점에서 값은 기준통화인데 단위만 원으로 나간다.
                'total_amount' => $this->formatOrderChargeAmount(
                    $order,
                    (float) $order->total_paid_amount > 0 ? (float) $order->total_paid_amount : (float) $order->total_amount
                ),
                // 기본값은 **가장 안전한 쪽**(구매자 주소)이다. 운영자에게 보낼 관리자 주소는
                // 수신자가 정해진 뒤 personalize 가 올린다 — 운영자가 이 알림의 수신자를
                // 「주문자」로 바꿔도 고객에게 관리자 주소가 가지 않게 한다.
                'order_url' => $this->customerOrderUrl($order),
                'site_url' => $baseUrl,
            ],
            'context' => $this->buildOrderContext($order),
        ];
    }

    /**
     * 취소 주문 입금 관리자 알림 데이터를 추출합니다. (P-D1)
     *
     * 금액은 결제행에 기록된 입금액(결제 통화)이다 — 주문 총액이 아니다. 고객이 다른 금액을 넣었을 수도 있다.
     *
     * @param  array  $args  훅 인수 [$order, {amount, provider}]
     * @return array 추출 결과
     */
    private function extractDepositAfterCancelAdmin(array $args): array
    {
        $result = $this->extractNewOrderAdmin($args);
        $order = $args[0] ?? null;

        if (! $order instanceof Order) {
            return $result;
        }

        $amount = (float) ($args[1]['amount'] ?? 0);
        $currency = (string) ($order->payment?->currency ?: ($order->currency_snapshot['order_currency'] ?? ''));
        $result['data']['deposit_amount'] = $currency !== ''
            ? ecommerce_format_price($amount, $currency)
            : (string) $amount;

        return $result;
    }

    /**
     * 상품 문의 접수 관리자 알림 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$inquiry]
     * @return array 추출 결과
     */
    private function extractInquiryReceived(array $args): array
    {
        $inquiry = $args[0] ?? null;
        if (! $inquiry instanceof ProductInquiry) {
            return $this->emptyResult();
        }

        $baseUrl = SiteUrl::forOutboundLinks();
        $product = $inquiry->product;

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => '{recipient_name}',
                'app_name' => config('app.name'),
                'product_name' => $product?->getLocalizedName() ?? ($inquiry->product_name_snapshot[app()->getLocale()] ?? ''),
                // 받는 사람이 아닌 회원(문의 작성자)의 이름은 알림 영역의 표시 이름이다
                'customer_name' => MemberDisplayName::name($inquiry->user, MemberDisplayName::AREA_NOTIFICATION) ?? '',
                'inquiry_content' => mb_substr($inquiry->inquirable?->content ?? '', 0, 200),
                'inquiry_url' => ScreenUrl::toAbsoluteForLinks(EcommerceScreenUrl::admin('ecommerce.admin.inquiries', '/admin/ecommerce/product-inquiries'), ScreenUrl::SCOPE_ADMIN),
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
     * @return array 추출 결과
     */
    private function extractInquiryReplied(array $args): array
    {
        $inquiry = $args[0] ?? null;
        if (! $inquiry instanceof ProductInquiry) {
            return $this->emptyResult();
        }

        $baseUrl = ScreenUrl::userLinkBase();

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                // 받는 사람 이름 — 코어가 받는 사람과 채널마다 그 채널의 표시 이름으로 바꾼다
                'name' => '{recipient_name}',
                'app_name' => config('app.name'),
                'product_name' => $inquiry->product?->getLocalizedName() ?? ($inquiry->product_name_snapshot[app()->getLocale()] ?? ''),
                'inquiry_content' => mb_substr($inquiry->inquirable?->content ?? '', 0, 200),
                'inquiry_url' => ScreenUrl::toAbsoluteForLinks(EcommerceScreenUrl::mypageInquiries()),
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
     * 비회원 주문 조회 화면의 기준 경로 (상점 주소 설정 하위)
     *
     * 후속 이메일 발송 이슈가 비회원 주문 조회 URL을 만들 때 사용하는 경로 기준이다.
     * 비회원 본인 확인은 주문번호 + 전화번호 + 조회 비밀번호 입력을 전제로 하므로
     * 주문번호를 쿼리에 노출하지 않고 순수 경로만 제공한다.
     *
     * 앞의 상점 주소는 운영자 설정이므로 ShopPathResolver 가 붙인다 — 상수로 `/shop` 을
     * 박아 두면 주소를 바꾼 상점의 안내 메일이 존재하지 않는 화면을 가리킨다 (공개 #85).
     * 경로는 비회원 주문 조회 라우트(templates/sirsoft-basic/routes.json) 및
     * OrderController::showByOrderNumber 의 비회원 redirect_to 와 동일하게 유지한다.
     */
    public const GUEST_ORDER_LOOKUP_SUFFIX = 'guest/orders';

    /**
     * 주문 알림 데이터 배열을 구성합니다.
     *
     * 회원 주문은 기존과 동일하게 회원명과 마이페이지 주문 상세 URL을 사용한다.
     * 비회원 주문은 회원 정보가 없으므로 주문자명을 배송지 기준으로 채우고,
     * 마이페이지 대신 비회원 주문 조회 화면 경로를 order_url로 사용한다.
     *
     * @param  Order  $order  주문 모델
     * @param  array  $extra  추가 변수
     * @return array 추출 결과
     */
    private function buildOrderData(Order $order, array $extra = []): array
    {
        $baseUrl = ScreenUrl::userLinkBase();

        // 회원 주문의 이름은 받는 사람 이름이다 — 코어가 받는 사람과 채널마다 그 채널의 표시 이름으로 바꾼다.
        // 비회원 주문은 회원이 없으므로 주문서에 적힌 주문자 이름 그대로다.
        $name = $order->isGuestOrder()
            ? ($order->getOrdererName() ?? '')
            : '{recipient_name}';
        $orderUrl = $this->customerOrderUrl($order);

        // 표시 금액 = 결제완료액(있으면) 또는 주문 총액(base 통화)을 결제 통화로 환산한 포맷.
        $baseAmount = (float) $order->total_paid_amount > 0
            ? (float) $order->total_paid_amount
            : (float) $order->total_amount;

        // 배송지(국가 포함) 변수 (B6 — 운영자/고객이 해외주소 인지 가능)
        $shipping = $order->shippingAddress;
        $shippingCountryCode = $shipping?->recipient_country_code ?? '';
        $shippingCountryName = $this->localizeCountryName($shippingCountryCode);
        $shippingFullAddress = $shipping ? $shipping->getFullAddress() : '';

        $data = [
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
        ];

        // 비회원 주문은 수신자 객체에 전화번호가 없다 — 주문자 연락처를 코어 표준 수신 전화번호
        // 키로 싣는다. 문자·알림톡 채널은 이커머스를 모른 채 이 키만 읽는다(회원은 회원 휴대폰).
        $ordererPhone = $order->isGuestOrder() ? trim((string) ($shipping?->orderer_phone ?? '')) : '';
        if ($ordererPhone !== '') {
            $data[GenericNotification::RECIPIENT_PHONE_KEY] = $ordererPhone;
        }

        return array_merge($data, $extra);
    }

    /**
     * 구매자가 볼 주문 화면 주소를 반환합니다.
     *
     * 비회원은 조회 토큰이 없는 브라우저로 들어오므로 상세가 아니라 주문 조회 화면이다.
     *
     * @param  Order  $order  주문 모델
     * @return string 절대 URL
     */
    private function customerOrderUrl(Order $order): string
    {
        // 사람이 여는 화면 — 「사용자 화면 URL」·주소 필터를 따른다(#792)
        return $order->isGuestOrder()
            ? ScreenUrl::toAbsoluteForLinks(EcommerceScreenUrl::guestOrders())
            : ScreenUrl::toAbsoluteForLinks(EcommerceScreenUrl::mypageOrderDetail((string) $order->order_number));
    }

    /**
     * 수신자가 운영자면 주문 링크를 관리자 화면 주소로 올립니다.
     *
     * 판정 기준은 역할 이름이 아니라 **그 사람이 주문 화면을 열 수 있는가**(권한)다 —
     * 상점이 새 역할을 만들 때마다 리스너를 고칠 일이 없고, 그 화면을 못 여는 사람에게
     * 관리자 주소를 주지 않는다.
     *
     * > 큐 재로드 시점 오염을 막기 위해 `$context` 의 스칼라만 읽는다.
     *
     * @param  array  $data  앞선 필터까지의 알림 데이터
     * @param  object  $notifiable  수신자 (User 또는 GuestNotifiable)
     * @param  string  $type  알림 정의 유형
     * @param  array  $context  extract_data 가 실은 컨텍스트
     * @param  array  $args  원래 훅 인자 (읽지 않는다)
     * @return array 수신자별로 치환된 알림 데이터
     */
    public function personalizeOrderData(
        array $data,
        object $notifiable,
        string $type,
        array $context,
        array $args = [],
    ): array {
        if (! array_key_exists('order_url', $data)) {
            return $data;
        }

        $orderNumber = (string) ($context['order_number'] ?? '');
        if ($orderNumber === '') {
            return $data;
        }

        if (! app(NotificationRecipientCapability::class)->can($notifiable, self::ADMIN_ORDER_PERMISSION)) {
            return $data;
        }

        // 운영자에게 가는 알림의 사이트 주소는 관리자 화면 기준(사이트 주소)이다 — 회원 기본값은 사용자 화면 URL(#792).
        $data['site_url'] = SiteUrl::forOutboundLinks();
        $data['order_url'] = ScreenUrl::toAbsoluteForLinks(EcommerceScreenUrl::admin('ecommerce.admin.order', '/admin/ecommerce/orders/{order_number}', ['order_number' => $orderNumber]), ScreenUrl::SCOPE_ADMIN);

        return $data;
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
        // 코어 사전에 없는 언어(일본어 등)는 상점 배송국가 설정 이름으로 채운다 (S4-D17)
        $names = CountryNames::forCode($code);

        return LocalizedValue::pick($names, anyAsLast: false) ?? $code;
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
     * @return array 추출 결과
     */
    private function buildOrderContext(Order $order): array
    {
        $context = [
            'trigger_user_id' => $order->user_id,
            'trigger_user' => $order->user,
            // personalize 가 읽는 **스칼라**다 — 큐에서 모델을 다시 읽으면 과거 사건의
            // 알림에 "지금" 상태가 실린다.
            'order_number' => $order->order_number,
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
     * 소멸 예정 마일리지 알림 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$user, $amount, $currency, $expiresDate, $balance]
     * @return array 추출 결과
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
        $baseUrl = ScreenUrl::userLinkBase();

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                // 받는 사람 이름 — 코어가 받는 사람과 채널마다 그 채널의 표시 이름으로 바꾼다
                'name' => '{recipient_name}',
                'app_name' => config('app.name'),
                'amount' => number_format($amount),
                'currency' => $currency,
                'expires_date' => $expiresDate,
                'balance' => number_format($balance),
                'mileage_url' => ScreenUrl::toAbsoluteForLinks(EcommerceScreenUrl::mypageMileage()),
                'site_url' => $baseUrl,
            ],
            'context' => [
                'trigger_user_id' => $user->id,
                'trigger_user' => $user,
            ],
        ];
    }

    // ──────────────────────────────────────────────
    // #952 — 쿠폰 발급·만료 임박, 마일리지 적립·차감, 부분 취소, 리뷰 답글
    // ──────────────────────────────────────────────

    /**
     * 쿠폰 발급 알림(`coupon_issued.{쿠폰ID}`) 데이터를 추출합니다.
     *
     * 광고성 쿠폰이면 제목 앞 `{ad_prefix}` 에 「(광고) 」 를 싣고, 회원이 수신동의하지 않은 바깥 채널(메일·문자·알림톡)은
     * 빼고 사이트 알림만 남긴다. 광고성이 아니면 채널을 제한하지 않는다 — 켜고 끄기는 템플릿이 정한다.
     *
     * @param  array  $args  훅 인수 [$user, $issue, $source]
     * @return array 추출 결과
     */
    private function extractCouponIssued(array $args): array
    {
        $user = $args[0] ?? null;
        $issue = $args[1] ?? null;
        if (! $user instanceof User || ! $issue instanceof CouponIssue) {
            return $this->emptyResult();
        }

        $coupon = $issue->coupon;
        if ($coupon === null) {
            return $this->emptyResult();
        }

        $locale = $this->recipientLocale($user);
        $source = CouponIssueSource::tryFrom((string) ($args[2] ?? '')) ?? $issue->resolveIssueSource();
        $marketingOnly = (bool) $coupon->notify_marketing_only;
        $expires = $issue->expired_at !== null
            ? (TimezoneHelper::toSiteDateString($issue->expired_at) ?? '')
            : (string) __('sirsoft-ecommerce::messages.coupon.notification.no_expiry', [], $locale);

        $context = [
            'trigger_user_id' => $user->id,
            'trigger_user' => $user,
        ];

        if ($marketingOnly) {
            $context['channels'] = MarketingConsentGate::allowedChannels((int) $user->id, ['mail', 'database', 'sms', 'alimtalk']);
        }

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => '{recipient_name}',
                'app_name' => config('app.name'),
                'ad_prefix' => $marketingOnly ? (string) __('sirsoft-ecommerce::messages.coupon.notification.ad_prefix', [], $locale) : '',
                'coupon_name' => $coupon->getLocalizedName($locale),
                'coupon_benefit' => $coupon->benefit_formatted,
                'coupon_min_order_amount' => (float) ($coupon->min_order_amount ?? 0) > 0
                    ? ecommerce_format_price($coupon->min_order_amount)
                    : (string) __('sirsoft-ecommerce::messages.coupon.notification.no_min_order', [], $locale),
                'coupon_expires_date' => $expires,
                'coupon_code' => (string) $issue->coupon_code,
                'issue_source_label' => $source?->label() ?? '',
                'coupons_url' => ScreenUrl::toAbsoluteForLinks(EcommerceScreenUrl::mypageCoupons()),
                'coupons_path' => EcommerceScreenUrl::mypageCoupons(),
                'site_url' => ScreenUrl::userLinkBase(),
            ],
            'context' => $context,
        ];
    }

    /**
     * 쿠폰 만료 임박 알림 데이터를 추출합니다.
     *
     * @param  array  $args  훅 인수 [$user, $count, $earliestExpiresDate, $couponNames]
     * @return array 추출 결과
     */
    private function extractCouponExpiringSoon(array $args): array
    {
        $user = $args[0] ?? null;
        if (! $user instanceof User) {
            return $this->emptyResult();
        }

        $names = array_values(array_filter((array) ($args[3] ?? []), 'is_string'));

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => '{recipient_name}',
                'app_name' => config('app.name'),
                'count' => (string) (int) ($args[1] ?? 0),
                'earliest_expires_date' => (string) ($args[2] ?? ''),
                'coupon_names' => implode(', ', $names),
                'coupons_url' => ScreenUrl::toAbsoluteForLinks(EcommerceScreenUrl::mypageCoupons()),
                'coupons_path' => EcommerceScreenUrl::mypageCoupons(),
                'site_url' => ScreenUrl::userLinkBase(),
            ],
            'context' => [
                'trigger_user_id' => $user->id,
                'trigger_user' => $user,
            ],
        ];
    }

    /**
     * 마일리지 적립·차감 알림 데이터를 추출합니다.
     *
     * 적립 훅 인수: [$user, $amount, $currency, $balance, $type, $expiresDate, $tx]
     * 차감 훅 인수: [$user, $amount, $currency, $balance, $memo, $tx]
     *
     * @param  array  $args  훅 인수
     * @param  bool  $earned  적립이면 true, 관리자 차감이면 false
     * @return array 추출 결과
     */
    private function extractMileageChange(array $args, bool $earned): array
    {
        $user = $args[0] ?? null;
        if (! $user instanceof User) {
            return $this->emptyResult();
        }

        $locale = $this->recipientLocale($user);
        $data = [
            'name' => '{recipient_name}',
            'app_name' => config('app.name'),
            'amount' => number_format((float) ($args[1] ?? 0)),
            'currency' => (string) ($args[2] ?? 'KRW'),
            'balance' => number_format((float) ($args[3] ?? 0)),
            'mileage_url' => ScreenUrl::toAbsoluteForLinks(EcommerceScreenUrl::mypageMileage()),
            'mileage_path' => EcommerceScreenUrl::mypageMileage(),
            'site_url' => ScreenUrl::userLinkBase(),
        ];

        if ($earned) {
            $reason = (string) ($args[4] ?? '');
            $data['reason_label'] = (string) __('sirsoft-ecommerce::messages.mileage.notification.reason.'.($reason !== '' ? $reason : 'purchase'), [], $locale);
            $data['expires_date'] = (string) ($args[5] ?? '');
        } else {
            $data['memo'] = (string) ($args[4] ?? '');
        }

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => $data,
            'context' => [
                'trigger_user_id' => $user->id,
                'trigger_user' => $user,
            ],
        ];
    }

    /**
     * 주문 부분 취소 알림 데이터를 추출합니다.
     *
     * 회원 주문은 회원에게, 비회원 주문은 주문서의 연락처(guest_recipient)로 보낸다 — 주문 알림 공용 데이터·컨텍스트를 쓴다.
     *
     * @param  array  $args  훅 인수 [$order, {cancel_type, cancel_items}, $snapshot, $cancel]
     * @return array 추출 결과
     */
    private function extractOrderPartiallyCancelled(array $args): array
    {
        $order = $args[0] ?? null;
        if (! $order instanceof Order) {
            return $this->emptyResult();
        }

        $cancel = $args[3] ?? null;
        $locale = app()->getLocale();
        $items = [];
        $refundAmount = '';

        if ($cancel instanceof OrderCancel) {
            foreach ($cancel->cancelOptions()->with('orderOption')->get() as $cancelOption) {
                $option = $cancelOption->orderOption;
                $productName = LocalizedValue::pick($option?->product_name, $locale) ?? '';
                if ($productName === '') {
                    continue;
                }
                $items[] = $productName.' x'.(int) $cancelOption->cancel_quantity;
            }

            $refund = $cancel->refund;
            if ($refund !== null) {
                $refundAmount = $this->formatOrderChargeAmount($order, (float) $refund->refund_amount);
            }
        }

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => $this->buildOrderData($order, [
                'cancelled_items' => implode(', ', $items),
                'refund_amount' => $refundAmount,
            ]),
            'context' => $this->buildOrderContext($order),
        ];
    }

    /**
     * 리뷰 답글 알림 데이터를 추출합니다. 첫 답글이고 작성자가 회원일 때만 보낸다.
     *
     * @param  array  $args  훅 인수 [$review, $isFirst]
     * @return array 추출 결과
     */
    private function extractReviewReplied(array $args): array
    {
        $review = $args[0] ?? null;
        $isFirst = (bool) ($args[1] ?? false);
        if (! $review instanceof ProductReview || ! $isFirst || $review->user_id === null) {
            return $this->emptyResult();
        }

        $author = $review->user;
        if (! $author instanceof User) {
            return $this->emptyResult();
        }

        $product = $review->product;

        return [
            'notifiable' => null,
            'notifiables' => null,
            'data' => [
                'name' => '{recipient_name}',
                'app_name' => config('app.name'),
                'product_name' => $product?->getLocalizedName() ?? '',
                'reply_excerpt' => mb_substr(strip_tags((string) ($review->reply_content ?? '')), 0, 200),
                'reviews_url' => ScreenUrl::toAbsoluteForLinks(EcommerceScreenUrl::mypageReviews()),
                'reviews_path' => EcommerceScreenUrl::mypageReviews(),
                'site_url' => ScreenUrl::userLinkBase(),
            ],
            'context' => [
                'trigger_user_id' => $author->id,
                'trigger_user' => $author,
                'related_users' => [
                    'author' => $author,
                ],
            ],
        ];
    }

    /**
     * 받는 회원의 언어를 반환합니다. 회원 언어가 지원 언어가 아니면 지금 요청 언어입니다.
     *
     * @param  User  $user  회원
     * @return string 언어 코드
     */
    private function recipientLocale(User $user): string
    {
        $language = (string) ($user->language ?? '');
        $supported = (array) config('app.supported_locales', []);

        return $language !== '' && in_array($language, $supported, true) ? $language : app()->getLocale();
    }

    /**
     * 빈 추출 결과를 반환합니다 (이 리스너 소관이 아니거나 발송 조건 미충족).
     *
     * @return array 빈 추출 결과
     */
    private function emptyResult(): array
    {
        return ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];
    }
}
