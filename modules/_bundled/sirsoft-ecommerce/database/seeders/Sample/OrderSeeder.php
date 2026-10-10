<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use App\Models\User;
use App\Traits\HasSeederCounts;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderAddressFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderShippingFactory;
use Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\Concerns\PurgesSampleOrders;
use Modules\Sirsoft\Ecommerce\Enums\CancelOptionStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Enums\ShippingStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderCancelOption;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductAdditionalOptionValue;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyCountrySetting;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\SequenceService;
use Modules\Sirsoft\Ecommerce\Support\MileageRounding;

/**
 * 주문 더미 데이터 시더
 *
 * 실제 주문 흐름이 남기는 문서를 상태별로 빠짐없이 만든다 — 모든 주문에 결제 행·배송 행이 있고,
 * 주문→결제→발송→배송완료→구매확정 시각이 한 줄로 이어지며, 취소 주문에는 취소신청서(+결제 후
 * 취소면 환불신청서)가 붙는다. 카드·가상계좌 샘플은 켜진 결제 플러그인의 id 로 기록하되
 * `payment_meta.is_test_mode=true` 와 `SAMPLE-` 거래번호로 실거래와 구분하고, 켜진 결제 플러그인이
 * 없으면 무통장입금만 만든다.
 */
class OrderSeeder extends Seeder
{
    use HasSeederCounts;
    use PurgesSampleOrders;

    /**
     * 샘플 PG 거래번호 접두 — 실거래와 구분하는 표식
     */
    public const SAMPLE_TRANSACTION_PREFIX = 'SAMPLE-';

    /**
     * 취소 주문 중 결제 후 취소 비율 (%) — 나머지는 입금 전 취소
     */
    private const PAID_BEFORE_CANCEL_PERCENTAGE = 70;

    /**
     * 샘플 카드·가상계좌에 기록할 PG id (켜진 결제 플러그인이 없으면 null)
     */
    private ?string $samplePgProvider = null;

    /**
     * 적립 절사 기준 (설정의 기본 통화 규칙)
     *
     * @var array{unit: string, method: string}|null
     */
    private ?array $earnRounding = null;

    /**
     * 기본 적립률 (%)
     */
    private float $earnRate = 1.0;

    /**
     * 취소·환불 처리자로 쓸 관리자 ID
     */
    private ?int $adminId = null;

    /**
     * 주문 생성 중 소진된 쿠폰 기록 [['user_id' => int, 'coupon_id' => int], ...]
     *
     * @var array<int, array{user_id: int, coupon_id: int}>
     */
    private array $usedCouponRecords = [];

    /**
     * 생성할 기본 주문 수
     */
    private const ORDER_COUNT = 100;

    /**
     * 주문을 배정할 회원 풀 크기 (주문을 더 많은 회원에 분산)
     */
    private const ORDER_USER_POOL = 40;

    /**
     * 상태별 최소 주문 수
     */
    private const MIN_PER_STATUS = 5;

    /**
     * 비회원 주문 비율 (%)
     */
    private const GUEST_ORDER_PERCENTAGE = 20;

    /**
     * 쿠폰 적용 주문 비율 (%)
     */
    private const COUPON_ORDER_PERCENTAGE = 40;

    /**
     * 주문 상태별 비율 (합계 100)
     */
    private array $statusDistribution = [
        'pending_order' => 3,
        'pending_payment' => 7,
        'payment_complete' => 20,
        'shipping_hold' => 2,
        'preparing' => 15,
        'shipping_ready' => 5,
        'shipping' => 13,
        'delivered' => 20,
        'confirmed' => 10,
        'cancelled' => 5,
    ];

    /**
     * 쿠폰 우선 배정 상태 (이 상태들을 먼저 생성하여 쿠폰 확보)
     */
    private const COUPON_PRIORITY_STATUSES = [
        'payment_complete', 'preparing', 'shipping_ready', 'shipping', 'delivered', 'confirmed',
    ];

    /**
     * 시더 실행
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $this->command->info('주문 더미 데이터 생성을 시작합니다.');

        $this->deleteExistingOrders();
        $this->resolveSampleContext();
        $this->createOrders();
        $this->replenishAvailableCoupons();

        $count = Order::count();
        $this->command->info("주문 더미 데이터 {$count}건이 성공적으로 생성되었습니다.");
    }

    /**
     * 기존 주문 삭제
     *
     * @return void 반환값 없음
     */
    private function deleteExistingOrders(): void
    {
        $result = $this->purgeSampleOrderGraph();

        if ($result['orders'] > 0) {
            $this->command->warn("기존 주문 {$result['orders']}건과 하위 문서(결제·배송·취소·환불·클레임·첨부)를 삭제했습니다. (쿠폰 {$result['coupons_restored']}건 복원)");
        }
    }

    /**
     * 샘플 PG id·적립 규칙·처리 관리자를 한 번 해석합니다.
     *
     * PG id 는 결제 플러그인이 등록한 목록 안에서만 고른다(설정의 카드 PG → 기본 PG → 등록 첫 항목).
     * 목록 밖의 이름(`inicis`·`nicepay` 같은 가짜 id)을 쓰면 결제 상세·환불 화면이 그 PG 를 찾지 못한다.
     *
     * @return void 반환값 없음
     */
    private function resolveSampleContext(): void
    {
        $settings = app(EcommerceSettingsService::class);

        $registered = array_values(array_filter(array_map(
            fn ($provider) => is_array($provider) ? ($provider['id'] ?? null) : null,
            $settings->getRegisteredPgProviders(),
        )));

        if ($registered !== []) {
            $configured = $settings->getPaymentMethodConfig('card')['pg_provider']
                ?? $settings->getSetting('order_settings.default_pg_provider');
            $this->samplePgProvider = in_array($configured, $registered, true) ? $configured : $registered[0];
            $this->command->line("  - 샘플 카드·가상계좌 PG: {$this->samplePgProvider} (테스트 표식 is_test_mode, 거래번호 ".self::SAMPLE_TRANSACTION_PREFIX.' 접두)');
        } else {
            $this->samplePgProvider = null;
            $this->command->warn('  - 켜진 결제 플러그인이 없어 무통장입금 주문만 만듭니다.');
        }

        $rules = (array) $settings->getSetting('mileage.currency_rules', []);
        $base = $this->getCurrencyConversionService()->getDefaultCurrency();
        $rule = collect($rules)->first(fn ($r) => is_array($r) && ($r['currency_code'] ?? null) === $base) ?? ($rules[0] ?? null);
        $this->earnRounding = MileageRounding::normalize(is_array($rule) ? $rule : null);
        $this->earnRate = max(0.0, (float) $settings->getSetting('mileage.default_earn_rate', 1));

        $this->adminId = User::whereHas('roles', fn ($q) => $q->where('identifier', 'admin'))->orderBy('id')->value('id');
    }

    /**
     * 결제 금액에서 적립 예정액을 계산합니다 (설정 적립률·절사 기준, OrderCalculationService 와 같은 식).
     *
     * 마일리지가 꺼져 있어도 시연용으로 계산한다 — 화면에는 마일리지를 켜야 보인다.
     *
     * @param  float  $earnableAmount  적립 대상 금액
     * @return int 적립 예정액
     */
    private function earnPointsFor(float $earnableAmount): int
    {
        return MileageRounding::apply(max(0.0, $earnableAmount) * $this->earnRate / 100, $this->earnRounding);
    }

    /**
     * 주문 생성
     *
     * @return void 반환값 없음
     */
    private function createOrders(): void
    {
        // 사용자 목록 가져오기 (없으면 생성)
        // 주문을 더 많은 회원에 분산시키기 위해 일반 회원(관리자 제외) 풀을 넓게 사용한다.
        // 풀이 넓을수록 $users->random() 로 배정되는 주문이 더 많은 회원에 퍼지고,
        // 후행 마일리지 시더(실주문 기반 적립/사용 정합)도 그만큼 많은 회원에 분포된다.
        $users = User::whereDoesntHave('roles', fn ($q) => $q->where('identifier', 'admin'))
            ->inRandomOrder()
            ->take(self::ORDER_USER_POOL)
            ->get();

        // 일반 회원이 부족하면 전체 회원으로 폴백, 그래도 없으면 생성
        if ($users->isEmpty()) {
            $users = User::take(self::ORDER_USER_POOL)->get();
        }
        if ($users->isEmpty()) {
            $this->command->line('  - 사용자가 없어 '.self::ORDER_USER_POOL.'명 생성합니다.');
            $users = User::factory()->count(self::ORDER_USER_POOL)->create();
        }

        $this->command->line("  - 주문 배정 회원 풀: {$users->count()}명");

        // 상품 옵션 목록 가져오기 (없으면 경고)
        $productOptions = ProductOption::with('product')->take(100)->get();
        if ($productOptions->isEmpty()) {
            $this->command->warn('상품 옵션이 없습니다. ProductSeeder를 먼저 실행하는 것을 권장합니다.');
            $this->command->warn('상품 없이 주문을 생성합니다.');
        }

        // 상태별 주문 수 계산
        $orderCount = $this->getSeederCount('orders', self::ORDER_COUNT);
        $statusCounts = $this->calculateStatusCounts($orderCount);

        $createdCount = 0;
        $progressBar = $this->command->getOutput()->createProgressBar($orderCount);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');

        // 사용자별 사용 가능한 쿠폰 발급 목록 캐시
        $availableCouponIssuesByUser = $this->loadAvailableCouponIssuesByUser();
        $couponCount = 0;

        $guestCount = 0;

        // 쿠폰 우선 배정: 쿠폰이 필요한 상태를 먼저 생성하여 쿠폰 재고 확보
        $priorityStatuses = [];
        $normalStatuses = [];
        foreach ($statusCounts as $status => $count) {
            if (in_array($status, self::COUPON_PRIORITY_STATUSES)) {
                $priorityStatuses[$status] = $count;
            } else {
                $normalStatuses[$status] = $count;
            }
        }
        $orderedStatusCounts = array_merge($priorityStatuses, $normalStatuses);

        foreach ($orderedStatusCounts as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                // 비회원 주문 비율 적용 (쿠폰 우선 상태는 비회원 비율 절반으로)
                $guestChance = in_array($status, self::COUPON_PRIORITY_STATUSES)
                    ? (int) (self::GUEST_ORDER_PERCENTAGE / 2)
                    : self::GUEST_ORDER_PERCENTAGE;
                $isGuest = rand(1, 100) <= $guestChance;
                $user = $isGuest ? null : $users->random();

                // 쿠폰 적용 여부 결정 (회원 주문 + 취소 아닌 경우만)
                // 쿠폰 우선 상태는 적용 비율 80%로 상향
                $couponChance = in_array($status, self::COUPON_PRIORITY_STATUSES)
                    ? 80
                    : self::COUPON_ORDER_PERCENTAGE;
                $applyCoupon = ! $isGuest
                    && $status !== 'cancelled'
                    && rand(1, 100) <= $couponChance
                    && isset($availableCouponIssuesByUser[$user?->id]);

                $this->createOrder(
                    $user,
                    OrderStatusEnum::from($status),
                    $productOptions,
                    $applyCoupon ? $availableCouponIssuesByUser[$user->id] : null,
                );
                if ($applyCoupon) {
                    $couponCount++;
                }
                if ($isGuest) {
                    $guestCount++;
                }
                $createdCount++;
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->command->newLine();
        $this->command->info('  - 회원 주문: '.($createdCount - $guestCount)."건, 비회원 주문: {$guestCount}건, 쿠폰 적용: {$couponCount}건");
    }

    /**
     * 상태별 주문 수 계산
     *
     * @param  int  $orderCount  총 주문 수
     * @return array<string, int>
     */
    private function calculateStatusCounts(int $orderCount): array
    {
        $counts = [];
        $total = 0;

        foreach ($this->statusDistribution as $status => $percentage) {
            $count = max(self::MIN_PER_STATUS, (int) round($orderCount * $percentage / 100));
            $counts[$status] = $count;
            $total += $count;
        }

        // 최소 보장으로 총 수가 늘어났으면 orderCount 자체를 확장
        // (비율 기반 상태에서 줄이지 않고, 늘어난 총합을 그대로 사용)
        if ($total > $orderCount) {
            return $counts;
        }

        // 반올림으로 인한 오차 보정 (delivered에 추가 — 가장 비율 높은 상태)
        if ($total < $orderCount) {
            $counts['delivered'] += $orderCount - $total;
        }

        return $counts;
    }

    /**
     * 단일 주문 생성
     *
     * @param  User|null  $user  사용자 (null이면 비회원 주문)
     * @param  OrderStatusEnum  $status  주문 상태
     * @param  Collection  $productOptions  상품 옵션 목록
     * @param  Collection|null  $userCouponIssues  사용자의 사용 가능 쿠폰 발급 목록
     */
    private function createOrder(
        ?User $user,
        OrderStatusEnum $status,
        $productOptions,
        $userCouponIssues = null,
    ): void {
        // 주문 날짜 (상태에 따라 다르게 설정)
        $orderedAt = $this->getOrderedAtByStatus($status);

        // 결제수단과 「결제 후 취소」 여부를 먼저 정해야 시각 사슬을 만들 수 있다.
        $paidBeforeCancel = $status === OrderStatusEnum::CANCELLED
            && rand(1, 100) <= self::PAID_BEFORE_CANCEL_PERCENTAGE;
        $paymentMethod = $this->pickPaymentMethod($status, $paidBeforeCancel);
        $timeline = $this->buildTimeline($status, $orderedAt, $paymentMethod, $paidBeforeCancel);

        // 주문 기본 정보 생성
        $order = $this->createOrderByStatus($user, $status, $timeline);

        // 주문 옵션 생성 (1~3개 랜덤)
        $optionCount = rand(1, 3);
        $orderOptions = $this->createOrderOptions($order, $productOptions, $optionCount, $status, $timeline);

        // 쿠폰 적용 (쿠폰 발급 목록이 있는 경우)
        $pendingShippingCoupons = [];
        if ($userCouponIssues !== null && $orderOptions->isNotEmpty()) {
            $pendingShippingCoupons = $this->applyCouponsToOrderOptions($order, $orderOptions, $userCouponIssues);
        }

        // 배송지 주소 생성
        $this->createOrderAddress($order);

        // 배송 행은 주문 생성 시점에 만들어지는 것이 실제 흐름이다 — 결제 전·취소 주문도 배송 대기 행을 갖는다.
        $this->createOrderShippings($order, $orderOptions, $status, $timeline);

        // 배송비 쿠폰 적용 (배송 레코드 생성 후)
        if (! empty($pendingShippingCoupons)) {
            $this->applyShippingCouponsToShippings($order, $pendingShippingCoupons);
        }

        // 주문 금액 재계산 (배송 생성 + 배송비 쿠폰 적용 후)
        $this->recalculateOrderAmount($order, $orderOptions, $timeline);

        // 결제 행은 결제 전 주문에도 있다 (주문 생성 시 READY / 입금 대기로 만들어짐)
        $payment = $this->createOrderPayment($order, $status, $paymentMethod, $timeline);

        // 취소 주문은 취소신청서(+결제 후 취소면 환불신청서)를 남긴다
        if ($status === OrderStatusEnum::CANCELLED) {
            $this->createCancellationDocuments($order, $payment, $timeline, $user);
        }

        // 적립(PURCHASE_EARN) 발행은 OrderSeeder 가 아니라 MileageSeeder 의 책임이다.
        // MileageSeeder 가 OrderSeeder 뒤에 실행되며 기존 거래를 전량 정리 후 배송완료/구매확정
        // 주문의 적립액에서 거래를 재발행한다 (잔액 lot·시간순·캐시 정합 일괄 관리). 여기서 발행하면
        // 중복/덮어쓰기가 된다. 따라서 적립 보정은 MileageSeeder 측에서 처리한다.
    }

    /**
     * 상태에 따른 주문일 반환
     */
    private function getOrderedAtByStatus(OrderStatusEnum $status): Carbon
    {
        return match ($status) {
            OrderStatusEnum::PENDING_ORDER, OrderStatusEnum::PENDING_PAYMENT => now()->subHours(rand(1, 48)),
            OrderStatusEnum::PAYMENT_COMPLETE, OrderStatusEnum::SHIPPING_HOLD, OrderStatusEnum::PREPARING => now()->subDays(rand(1, 3)),
            OrderStatusEnum::SHIPPING_READY, OrderStatusEnum::SHIPPING => now()->subDays(rand(2, 5)),
            OrderStatusEnum::DELIVERED => now()->subDays(rand(5, 10)),
            OrderStatusEnum::CONFIRMED => now()->subDays(rand(10, 30)),
            OrderStatusEnum::CANCELLED => now()->subDays(rand(1, 14)),
            default => now(),
        };
    }

    /**
     * 주문 상태에 맞는 결제수단을 고릅니다.
     *
     * 켜진 결제 플러그인이 없으면 무통장입금만 쓴다. 입금 전 취소 주문은 입금을 기다리는 수단
     * (가상계좌·무통장)이어야 말이 된다 — 카드는 결제창에서 승인되거나 아예 주문이 남지 않는다.
     *
     * @param  OrderStatusEnum  $status  주문 상태
     * @param  bool  $paidBeforeCancel  결제 후 취소 여부 (취소 주문만 의미)
     * @return string 결제수단 (card|vbank|dbank)
     */
    private function pickPaymentMethod(OrderStatusEnum $status, bool $paidBeforeCancel): string
    {
        if ($this->samplePgProvider === null) {
            return 'dbank';
        }

        if ($status === OrderStatusEnum::CANCELLED && ! $paidBeforeCancel) {
            return rand(1, 100) <= 50 ? 'vbank' : 'dbank';
        }

        return $this->getRandomPaymentMethod();
    }

    /**
     * 주문 → 결제 → 발송 → 배송완료 → 구매확정(또는 취소) 시각 사슬을 만듭니다.
     *
     * 각 시각은 앞 시각 이후이고 현재 시각을 넘지 않는다. 상태에 이르지 않은 단계는 null 이다.
     *
     * @param  OrderStatusEnum  $status  주문 상태
     * @param  Carbon  $orderedAt  주문 시각
     * @param  string  $paymentMethod  결제수단
     * @param  bool  $paidBeforeCancel  결제 후 취소 여부
     * @return array{ordered: Carbon, paid: ?Carbon, shipped: ?Carbon, delivered: ?Carbon, confirmed: ?Carbon, cancelled: ?Carbon}
     */
    private function buildTimeline(OrderStatusEnum $status, Carbon $orderedAt, string $paymentMethod, bool $paidBeforeCancel): array
    {
        $now = now();
        $after = function (Carbon $from, int $minMinutes, int $maxMinutes) use ($now): Carbon {
            $at = $from->copy()->addMinutes(rand($minMinutes, $maxMinutes));

            return $at->greaterThan($now) ? $now->copy() : $at;
        };

        $timeline = [
            'ordered' => $orderedAt->copy(),
            'paid' => null,
            'shipped' => null,
            'delivered' => null,
            'confirmed' => null,
            'cancelled' => null,
        ];

        $isPaid = $status->isPastPayment() || ($status === OrderStatusEnum::CANCELLED && $paidBeforeCancel);
        if ($isPaid) {
            // 카드는 주문 직후 승인, 입금형 수단은 몇 시간 뒤 입금
            $timeline['paid'] = $paymentMethod === 'card'
                ? $after($orderedAt, 1, 10)
                : $after($orderedAt, 30, 20 * 60);
        }

        if ($status->isShipped()) {
            $timeline['shipped'] = $after($timeline['paid'], 12 * 60, 36 * 60);
        }

        if ($status->isCompleted()) {
            $timeline['delivered'] = $after($timeline['shipped'], 12 * 60, 48 * 60);
        }

        if ($status === OrderStatusEnum::CONFIRMED) {
            $timeline['confirmed'] = $after($timeline['delivered'], 24 * 60, 5 * 24 * 60);
        }

        if ($status === OrderStatusEnum::CANCELLED) {
            $timeline['cancelled'] = $after($timeline['paid'] ?? $orderedAt, 30, 48 * 60);
        }

        return $timeline;
    }

    /**
     * 상태에 따라 주문 생성
     *
     * @param  User|null  $user  사용자 (null이면 비회원 주문)
     * @param  array  $timeline  시각 사슬 (buildTimeline)
     */
    private function createOrderByStatus(
        ?User $user,
        OrderStatusEnum $status,
        array $timeline,
    ): Order {
        $orderedAt = $timeline['ordered'];
        $factory = $user
            ? OrderFactory::new()->forUser($user)
            : OrderFactory::new()->forGuest();

        // 상태별 Factory 적용
        $factory = match ($status) {
            OrderStatusEnum::PENDING_ORDER, OrderStatusEnum::PENDING_PAYMENT => $factory->pendingPayment(),
            OrderStatusEnum::PAYMENT_COMPLETE, OrderStatusEnum::SHIPPING_HOLD, OrderStatusEnum::PREPARING, OrderStatusEnum::SHIPPING_READY => $factory->paid(),
            OrderStatusEnum::SHIPPING => $factory->shipping(),
            OrderStatusEnum::DELIVERED => $factory->delivered(),
            OrderStatusEnum::CONFIRMED => $factory->confirmed(),
            OrderStatusEnum::CANCELLED => $factory->cancelled(),
        };

        // 모바일 주문 30% 확률
        if (rand(1, 100) <= 30) {
            $factory = $factory->mobile();
        }

        // 시퀀스 서비스로 주문번호 생성 (프로덕션과 동일한 로직)
        $orderNumber = app(SequenceService::class)->generateCode(SequenceType::ORDER);

        $attributes = [
            'order_number' => $orderNumber,
            'order_status' => $status,
            'ordered_at' => $orderedAt,
            // 팩토리 상태가 now() 기준으로 찍는 시각을 시각 사슬로 덮는다 (주문보다 결제가 먼저인 행 방지)
            'paid_at' => $timeline['paid'],
            'confirmed_at' => $timeline['confirmed'],
            'cancelled_at' => $timeline['cancelled'],
            'payment_due_at' => $orderedAt->copy()->addDays($this->autoCancelDays()),
            'currency_snapshot' => $this->buildCurrencySnapshot($orderedAt),
            'created_at' => $orderedAt,
            'updated_at' => $timeline['cancelled'] ?? $timeline['confirmed'] ?? $timeline['delivered']
                ?? $timeline['shipped'] ?? $timeline['paid'] ?? $orderedAt,
        ];

        return $factory->create($attributes);
    }

    /**
     * 미입금 자동취소 기한(일)을 설정에서 읽습니다 (입금기한 단일 출처).
     *
     * @return int 기한 일수
     */
    private function autoCancelDays(): int
    {
        return max(1, (int) app(EcommerceSettingsService::class)->getSetting('order_settings.auto_cancel_days', 3));
    }

    /**
     * 주문 옵션 생성
     *
     * @param  Collection  $productOptions
     */
    private function createOrderOptions(
        Order $order,
        $productOptions,
        int $count,
        OrderStatusEnum $orderStatus,
        array $timeline,
    ): Collection {
        // 취소 주문은 남은 적립 예정액이 없다 (전체취소 재계산 결과와 같음)
        $earnable = $orderStatus !== OrderStatusEnum::CANCELLED;
        $optionTimes = [
            'delivered_at' => $timeline['delivered'],
            'confirmed_at' => $timeline['confirmed'],
            'created_at' => $timeline['ordered'],
            'updated_at' => $timeline['ordered'],
        ];
        $orderOptions = collect();

        // 실제 상품 옵션이 있으면 사용
        if ($productOptions->isNotEmpty()) {
            $selectedOptions = $productOptions->random(min($count, $productOptions->count()));

            foreach ($selectedOptions as $productOption) {
                $quantity = rand(1, 3);

                $product = $productOption->product;

                // 추가옵션 스냅샷 (상품에 추가옵션이 있으면 30% 확률로 선택 동결)
                // 추가옵션 단위 합계는 단가에 가산된다 (OrderProcessingService와 동일).
                [$additionalOptionsSnapshot, $additionalOptionsTotal] = $this->buildOrderOptionAdditionalOptions($product);

                $basePrice = $productOption->product->selling_price + ($productOption->price_adjustment ?? 0);
                $unitPrice = $basePrice + $additionalOptionsTotal;
                $subtotalPrice = $quantity * $unitPrice;

                // 할인은 쿠폰/코드 미적용 시 0 (출처 없는 유령 할인 방지)
                $discountAmount = 0;

                $optionStatus = $this->getOrderOptionStatusByOrderStatus($orderStatus);

                // 다국어 원본 직접 저장
                $optionNameI18n = $productOption->option_name ?? [];
                $optionValueI18n = $this->buildMultilingualOptionValue($productOption->option_values);

                $productNameJson = is_array($product->name) ? $product->name : ['ko' => $product->getLocalizedName(), 'en' => $product->getLocalizedName()];
                $finalAmount = $subtotalPrice - $discountAmount;

                // created_at 은 fillable 이 아니라 create() 가 버린다 — 시각 사슬을 지키려고 가드를 풀고 만든다.
                $orderOption = OrderOption::unguarded(fn () => OrderOption::create($optionTimes + [
                    'order_id' => $order->id,
                    'product_id' => $productOption->product_id,
                    'product_option_id' => $productOption->id,
                    'option_status' => $optionStatus,
                    'is_stock_deducted' => $this->isStockDeductedForStatus($optionStatus),
                    'source_type' => 'order',
                    'sku' => $productOption->option_code,
                    'product_name' => $productNameJson,
                    'product_option_name' => $optionNameI18n,
                    'option_name' => $optionNameI18n,
                    'option_value' => $optionValueI18n,
                    'quantity' => $quantity,
                    // 무게(g)·부피(cm³)는 상품 옵션 값 (OrderProcessingService 와 같은 출처)
                    'unit_weight' => (float) ($productOption->weight ?? 500),
                    'unit_volume' => (float) ($productOption->volume ?? 1000),
                    'subtotal_weight' => (float) ($productOption->weight ?? 500) * $quantity,
                    'subtotal_volume' => (float) ($productOption->volume ?? 1000) * $quantity,
                    'unit_price' => $unitPrice,
                    'additional_options_total' => $additionalOptionsTotal,
                    'subtotal_price' => $subtotalPrice,
                    'subtotal_discount_amount' => $discountAmount,
                    'product_coupon_discount_amount' => 0,
                    'order_coupon_discount_amount' => 0,
                    'coupon_discount_amount' => 0,
                    'code_discount_amount' => 0,
                    'subtotal_points_used_amount' => 0,
                    'subtotal_deposit_used_amount' => 0,
                    'subtotal_paid_amount' => $finalAmount,
                    'subtotal_tax_amount' => round($finalAmount / 11, 2),
                    'subtotal_tax_free_amount' => 0,
                    'subtotal_earned_points_amount' => $earnable ? $this->earnPointsFor($finalAmount) : 0,
                    'product_snapshot' => $product->toSnapshotArray(),
                    'option_snapshot' => $productOption->toSnapshotArray(),
                    'additional_options_snapshot' => $additionalOptionsSnapshot,
                    'promotions_applied_snapshot' => null,
                    // 다중 통화 필드 (OrderProcessingService와 동일 형식)
                    'mc_unit_price' => $this->buildMultiCurrencyAmount($unitPrice),
                    'mc_additional_options_total' => $additionalOptionsTotal > 0 ? $this->buildMultiCurrencyAmount($additionalOptionsTotal) : null,
                    'mc_subtotal_price' => $this->buildMultiCurrencyAmount($subtotalPrice),
                    'mc_product_coupon_discount_amount' => $this->buildMultiCurrencyAmount(0),
                    'mc_order_coupon_discount_amount' => $this->buildMultiCurrencyAmount(0),
                    'mc_coupon_discount_amount' => $this->buildMultiCurrencyAmount(0),
                    'mc_code_discount_amount' => $this->buildMultiCurrencyAmount(0),
                    'mc_subtotal_points_used_amount' => $this->buildMultiCurrencyAmount(0),
                    'mc_subtotal_deposit_used_amount' => $this->buildMultiCurrencyAmount(0),
                    'mc_subtotal_tax_amount' => $this->buildMultiCurrencyAmount(round($finalAmount / 11, 2)),
                    'mc_subtotal_tax_free_amount' => $this->buildMultiCurrencyAmount(0),
                    'mc_final_amount' => $this->buildMultiCurrencyAmount($finalAmount),
                ]));

                $orderOptions->push($orderOption);
            }
        } else {
            // 상품 없이 더미 주문 옵션 생성
            for ($i = 0; $i < $count; $i++) {
                $optionStatus = $this->getOrderOptionStatusByOrderStatus($orderStatus);

                $orderOption = OrderOptionFactory::new()
                    ->forOrder($order)
                    ->create($optionTimes + [
                        'option_status' => $optionStatus,
                        'is_stock_deducted' => $this->isStockDeductedForStatus($optionStatus),
                        'product_id' => null,
                        'product_option_id' => null,
                    ]);

                $orderOption->update([
                    'subtotal_earned_points_amount' => $earnable ? $this->earnPointsFor((float) $orderOption->subtotal_paid_amount) : 0,
                ]);

                $orderOptions->push($orderOption);
            }
        }

        return $orderOptions;
    }

    /**
     * 주문 상태에 따른 주문 옵션 상태 반환
     *
     * OrderStatusEnum으로 통일되었으므로 동일한 값을 반환합니다.
     */
    private function getOrderOptionStatusByOrderStatus(OrderStatusEnum $orderStatus): OrderStatusEnum
    {
        return $orderStatus;
    }

    /**
     * 옵션 상태에 따른 재고 차감 여부를 판정합니다.
     *
     * 실제 주문 프로세스(StockService::deductStock)는 결제완료/주문접수 타이밍에 재고를 차감하고,
     * 취소 시 복원(is_stock_deducted=false)합니다. 시더는 이 라이프사이클을 거치지 않고 상태만
     * 직접 세팅하므로, 결제완료 이후 상태(취소 제외)는 차감된 것으로 보정합니다.
     *
     * @param  OrderStatusEnum  $optionStatus  옵션 상태
     * @return bool 재고 차감 여부
     */
    private function isStockDeductedForStatus(OrderStatusEnum $optionStatus): bool
    {
        // 결제 전(주문대기/결제대기)은 미차감, 취소는 차감 후 복원되어 미차감
        if ($optionStatus->isBeforePayment() || $optionStatus === OrderStatusEnum::CANCELLED) {
            return false;
        }

        return true;
    }

    /**
     * 주문 금액 재계산
     *
     * 받은 돈(total_paid_amount)은 실제로 결제가 일어난 주문에만 둔다 — 입금 전 취소 주문은 0 이다.
     * 결제 후 취소 주문은 받은 돈을 그대로 두고 같은 금액을 환불액·취소액으로 쌓는다(장부 잔액 0).
     *
     * @param  Order  $order  금액을 다시 계산할 주문
     * @param  Collection  $orderOptions  주문 옵션 목록 (호출 맥락 표시용 — 금액은 DB 에서 다시 읽는다)
     * @param  array  $timeline  시각 사슬 (buildTimeline)
     * @return void 반환값 없음
     */
    private function recalculateOrderAmount(Order $order, $orderOptions, array $timeline): void
    {
        // 옵션 모델을 DB에서 다시 조회 (쿠폰 적용 후 업데이트된 값 반영)
        $freshOptions = OrderOption::where('order_id', $order->id)->get();

        $subtotalAmount = $freshOptions->sum('subtotal_price');
        $discountAmount = $freshOptions->sum('subtotal_discount_amount');
        $productCouponDiscount = $freshOptions->sum('product_coupon_discount_amount');
        $orderCouponDiscount = $freshOptions->sum('order_coupon_discount_amount');
        $couponDiscount = $freshOptions->sum('coupon_discount_amount');
        $codeDiscount = $freshOptions->sum('code_discount_amount');

        // 배송 레코드에서 실제 배송비 합산 (배송 미생성 시 0)
        $shippings = OrderShipping::where('order_id', $order->id)->get();
        $baseShippingAmount = (float) $shippings->sum('base_shipping_amount');
        $shippingDiscountAmount = (float) $shippings->sum('shipping_discount_amount');
        $shippingAmount = (float) $shippings->sum('total_shipping_amount');
        $totalAmount = $subtotalAmount - $discountAmount + $shippingAmount;

        $taxAmount = round($totalAmount / 11, 2);

        $updateData = [
            'subtotal_amount' => $subtotalAmount,
            'total_discount_amount' => $discountAmount,
            'total_coupon_discount_amount' => $couponDiscount,
            'total_product_coupon_discount_amount' => $productCouponDiscount,
            'total_order_coupon_discount_amount' => $orderCouponDiscount,
            'total_code_discount_amount' => $codeDiscount,
            'base_shipping_amount' => $baseShippingAmount,
            'shipping_discount_amount' => $shippingDiscountAmount,
            'total_shipping_amount' => $shippingAmount,
            'total_amount' => $totalAmount,
            'total_tax_amount' => $taxAmount,
            // 주문 적립 예정액 = 옵션 적립 예정액의 합 (OrderCalculationService 와 같은 구성)
            'total_earned_points_amount' => (float) $freshOptions->sum('subtotal_earned_points_amount'),
            'item_count' => $freshOptions->count(),
            'total_weight' => $freshOptions->sum('subtotal_weight'),
            'total_volume' => $freshOptions->sum('subtotal_volume'),
            // 다중 통화 필드 (OrderProcessingService::buildOrderMultiCurrency와 동일 형식)
            'mc_subtotal_amount' => $this->buildMultiCurrencyAmount($subtotalAmount),
            'mc_total_discount_amount' => $this->buildMultiCurrencyAmount($discountAmount),
            'mc_total_product_coupon_discount_amount' => $this->buildMultiCurrencyAmount($productCouponDiscount),
            'mc_total_order_coupon_discount_amount' => $this->buildMultiCurrencyAmount($orderCouponDiscount),
            'mc_total_coupon_discount_amount' => $this->buildMultiCurrencyAmount($couponDiscount),
            'mc_total_code_discount_amount' => $this->buildMultiCurrencyAmount($codeDiscount),
            'mc_base_shipping_amount' => $this->buildMultiCurrencyAmount($baseShippingAmount),
            'mc_extra_shipping_amount' => $this->buildMultiCurrencyAmount(0),
            'mc_shipping_discount_amount' => $this->buildMultiCurrencyAmount($shippingDiscountAmount),
            'mc_total_shipping_amount' => $this->buildMultiCurrencyAmount($shippingAmount),
            'mc_total_points_used_amount' => $this->buildMultiCurrencyAmount(0),
            'mc_total_deposit_used_amount' => $this->buildMultiCurrencyAmount(0),
            'mc_total_tax_amount' => $this->buildMultiCurrencyAmount($taxAmount),
            'mc_total_tax_free_amount' => $this->buildMultiCurrencyAmount(0),
            'mc_total_amount' => $this->buildMultiCurrencyAmount($totalAmount),
            'mc_total_paid_amount' => $this->buildMultiCurrencyAmount(0),
        ];

        $isPaid = $timeline['paid'] !== null;

        if ($isPaid) {
            $updateData['total_paid_amount'] = $totalAmount;
            $updateData['mc_total_paid_amount'] = $this->buildMultiCurrencyAmount($totalAmount);
            $updateData['total_due_amount'] = 0;
        } else {
            $updateData['total_paid_amount'] = 0;
            $updateData['total_due_amount'] = $totalAmount;
        }

        // 취소 주문: 취소액 누적, 결제 후 취소면 환불액도 같은 금액, 전체취소는 받을 돈 0 (S1-D17)
        if ($order->order_status === OrderStatusEnum::CANCELLED) {
            $updateData['total_cancelled_amount'] = $totalAmount;
            $updateData['total_refunded_amount'] = $isPaid ? $totalAmount : 0;
            $updateData['total_due_amount'] = 0;
            $updateData['cancellation_count'] = 1;
        }

        $order->update($updateData);
    }

    /**
     * 주문 주소 생성
     *
     * @return void 반환값 없음
     */
    private function createOrderAddress(Order $order): void
    {
        OrderAddressFactory::new()
            ->forOrder($order)
            ->shipping()
            ->create();
    }

    /**
     * 주문 결제 정보 생성
     *
     * 결제 상태는 주문 상태와 시각 사슬에서 정한다:
     *  - 주문대기: READY (결제창 진입 전)
     *  - 결제대기: 카드 READY / 가상계좌·무통장 WAITING_DEPOSIT (입금기한은 미래)
     *  - 결제 이후: PAID
     *  - 취소: CANCELLED (결제 후 취소면 받은 금액 전액 취소, 입금 전 취소면 0)
     * 무통장은 `manual`, 카드·가상계좌는 샘플 PG id 이며 PG 행은 `SAMPLE-` 거래번호와
     * `payment_meta.is_test_mode=true` 로 실거래와 구분한다.
     *
     * @param  Order  $order  주문 (금액 재계산 완료)
     * @param  OrderStatusEnum  $status  주문 상태
     * @param  string  $paymentMethod  결제수단 (card|vbank|dbank)
     * @param  array  $timeline  시각 사슬
     * @return OrderPayment 생성된 결제 행
     */
    private function createOrderPayment(Order $order, OrderStatusEnum $status, string $paymentMethod, array $timeline): OrderPayment
    {
        $order->refresh();
        $order->loadMissing('shippingAddress');

        $orderedAt = $timeline['ordered'];
        $paidAt = $timeline['paid'];
        $cancelledAt = $timeline['cancelled'];
        $total = (float) $order->total_amount;
        $isPg = $paymentMethod !== 'dbank';

        $paymentStatus = match (true) {
            $status === OrderStatusEnum::CANCELLED => PaymentStatusEnum::CANCELLED,
            $paidAt !== null => PaymentStatusEnum::PAID,
            $status === OrderStatusEnum::PENDING_PAYMENT && $paymentMethod !== 'card' => PaymentStatusEnum::WAITING_DEPOSIT,
            default => PaymentStatusEnum::READY,
        };

        $factory = OrderPaymentFactory::new()->forOrder($order);
        $factory = match ($paymentMethod) {
            'dbank' => $factory->directBank(),
            'vbank' => $factory->virtualAccount(),
            default => $factory->card(),
        };

        $paidLocal = $paidAt !== null ? $total : 0.0;
        $address = $order->shippingAddress;

        $attributes = [
            'payment_status' => $paymentStatus,
            'pg_provider' => $isPg ? $this->samplePgProvider : 'manual',
            'embedded_pg_provider' => null,
            // 승인·발급이 일어난 PG 행에만 거래번호가 있다 (결제창 진입 전 카드는 없음)
            'transaction_id' => $isPg && ($paidAt !== null || $paymentMethod === 'vbank')
                ? self::SAMPLE_TRANSACTION_PREFIX.strtoupper(bin2hex(random_bytes(8)))
                : null,
            'merchant_order_id' => $order->order_number.'_'.$orderedAt->timestamp,
            'paid_amount_local' => $paidLocal,
            'paid_amount_base' => $total,
            'vat_amount' => $order->total_tax_amount,
            'currency' => $order->currency,
            'currency_snapshot' => $order->currency_snapshot,
            'mc_paid_amount' => $this->buildMultiCurrencyAmount($paidLocal),
            'mc_cancelled_amount' => $this->buildMultiCurrencyAmount($status === OrderStatusEnum::CANCELLED ? $paidLocal : 0),
            'buyer_name' => $address?->orderer_name,
            'buyer_email' => $address?->orderer_email,
            'buyer_phone' => $address?->orderer_phone,
            'receipt_url' => null,
            'payment_meta' => $isPg ? ['is_test_mode' => true] : null,
            'payment_started_at' => $orderedAt,
            'paid_at' => $paidAt,
            'cancelled_at' => $cancelledAt,
            'cancelled_amount' => $status === OrderStatusEnum::CANCELLED ? $paidLocal : 0,
            'cancelled_vat_amount' => $status === OrderStatusEnum::CANCELLED && $paidAt !== null ? $order->total_tax_amount : 0,
            'cancel_reason' => $status === OrderStatusEnum::CANCELLED ? '주문 취소' : null,
            'created_at' => $orderedAt,
            'updated_at' => $cancelledAt ?? $paidAt ?? $orderedAt,
        ];

        $dueAt = $orderedAt->copy()->addDays($this->autoCancelDays());
        if ($paymentStatus === PaymentStatusEnum::WAITING_DEPOSIT && $dueAt->lessThanOrEqualTo(now())) {
            $dueAt = now()->addDay();
        }

        if ($paymentMethod === 'card') {
            $attributes['card_approval_number'] = $paidAt !== null ? (string) rand(10000000, 99999999) : null;
            if ($paidAt === null) {
                $attributes['card_name'] = null;
                $attributes['card_number_masked'] = null;
            }
        } elseif ($paymentMethod === 'vbank') {
            [$bankCode, $bankName] = $this->randomBank();
            $attributes += [
                'vbank_code' => $bankCode,
                'vbank_name' => $bankName,
                'vbank_holder' => $address?->orderer_name,
                'vbank_issued_at' => $orderedAt->copy()->addMinute(),
                'vbank_due_at' => $dueAt,
            ];
        } else {
            [$bankCode, $bankName, $account, $holder] = $this->shopBankAccount();
            $attributes += [
                'dbank_code' => $bankCode,
                'dbank_name' => $bankName,
                'dbank_account' => $account,
                'dbank_holder' => $holder,
                'depositor_name' => $address?->orderer_name,
                'deposit_due_at' => $dueAt,
            ];
        }

        return $factory->create($attributes);
    }

    /**
     * 취소 주문의 취소신청서와(결제 후 취소면) 환불신청서를 남깁니다.
     *
     * 실제 전체취소가 남기는 문서 모양을 따른다(OrderCancellationService·OrderRefundRequestService):
     * 취소신청서 COMPLETED FULL + 옵션별 취소 행, 결제 후 취소면 환불신청서 COMPLETED + 옵션별 환불 행,
     * 환불이 취소를 가리키는 링크(`order_cancel_id`). 번호는 SequenceService 로 채번한다. 상태 전이 서비스를
     * 부르지 않는 이유는 알림 발송·PG 환불 호출을 일으키지 않기 위해서다.
     *
     * 구매자가 낸 취소는 출처 DIRECT·고객 사유, 운영자 취소는 ADMIN·`admin_cancel`, 입금 전 취소의
     * 운영자 쪽은 입금기한 만료 자동취소(`auto_cancel_expired`)로 둔다.
     *
     * @param  Order  $order  취소 주문
     * @param  OrderPayment  $payment  결제 행
     * @param  array  $timeline  시각 사슬
     * @param  User|null  $user  주문 회원 (비회원이면 null)
     * @return void 반환값 없음
     */
    private function createCancellationDocuments(Order $order, OrderPayment $payment, array $timeline, ?User $user): void
    {
        $order->load(['options', 'shippings', 'shippingAddress']);

        $cancelledAt = $timeline['cancelled'];
        $paid = $timeline['paid'] !== null;
        $byBuyer = $user !== null && rand(1, 100) <= 60;

        $reasonCode = match (true) {
            $byBuyer => ['changed_mind', 'order_mistake', 'reorder_other'][array_rand(['changed_mind', 'order_mistake', 'reorder_other'])],
            $paid => 'admin_cancel',
            default => 'auto_cancel_expired',
        };
        $requestedBy = $byBuyer ? $user->id : $this->adminId;
        $reason = ClaimReason::query()->where('type', 'refund')->where('code', $reasonCode)->first();

        $sequence = app(SequenceService::class);
        $address = $order->shippingAddress;

        $cancel = OrderCancel::unguarded(fn () => OrderCancel::create([
            'order_id' => $order->id,
            'cancel_number' => $sequence->generateCode(SequenceType::CANCEL),
            'cancel_type' => CancelTypeEnum::FULL,
            'cancel_status' => CancelStatusEnum::COMPLETED,
            'cancel_reason_type' => $reasonCode,
            'cancel_reason' => null,
            'items_snapshot' => $order->options->map(fn (OrderOption $opt) => [
                'option_id' => $opt->id,
                'product_name' => $opt->product_name,
                'option_name' => $opt->option_name,
                'option_value' => $opt->option_value,
                'quantity' => (int) $opt->quantity,
                'unit_price' => (float) $opt->unit_price,
            ])->values()->all(),
            'shipping_snapshot' => [
                'country_code' => strtoupper((string) ($address?->recipient_country_code ?: 'KR')),
                'zipcode' => $address?->zipcode ?: ($address?->intl_postal_code ?: null),
                'policies' => [],
            ],
            'cancelled_by' => $requestedBy,
            'cancelled_at' => $cancelledAt,
            'created_at' => $cancelledAt,
            'updated_at' => $cancelledAt,
        ]));

        foreach ($order->options as $option) {
            OrderCancelOption::unguarded(fn () => OrderCancelOption::create([
                'order_cancel_id' => $cancel->id,
                'order_id' => $order->id,
                'order_option_id' => $option->id,
                'option_status' => CancelOptionStatusEnum::COMPLETED,
                'cancel_quantity' => (int) $option->quantity,
                'original_quantity' => (int) $option->quantity,
                'unit_price' => (float) $option->unit_price,
                'subtotal_amount' => (float) $option->unit_price * (int) $option->quantity,
                'completed_at' => $cancelledAt,
                'processed_by' => $this->adminId,
                'created_at' => $cancelledAt,
                'updated_at' => $cancelledAt,
            ]));

            $option->update([
                'cancelled_quantity' => (int) $option->quantity,
                'cancel_reason' => $reasonCode,
            ]);
        }

        if (! $paid) {
            return;
        }

        $refundAmount = (float) $order->total_paid_amount;
        $refundMethod = $payment->payment_method === 'dbank' ? RefundMethodEnum::BANK : RefundMethodEnum::PG;
        [$refundBankCode, $refundBankName] = $refundMethod === RefundMethodEnum::BANK ? $this->randomBank() : [null, null];
        $approvedAt = $cancelledAt->copy();
        $refundedAt = $refundMethod === RefundMethodEnum::BANK ? $cancelledAt->copy()->addHours(rand(1, 24))->min(now()) : $cancelledAt->copy();
        $shippingByOption = $order->shippings->groupBy('order_option_id')->map(fn ($rows) => (float) $rows->sum('total_shipping_amount'));

        $refund = OrderRefund::unguarded(fn () => OrderRefund::create([
            'order_id' => $order->id,
            'order_cancel_id' => $cancel->id,
            'refund_source_type' => $byBuyer ? RefundSourceTypeEnum::DIRECT : RefundSourceTypeEnum::ADMIN,
            'refund_source_id' => null,
            'refund_number' => $sequence->generateCode(SequenceType::REFUND),
            'refund_status' => RefundStatusEnum::COMPLETED,
            'refund_method' => $refundMethod,
            'refund_method_overridden' => false,
            'reason_code' => $reasonCode,
            'reason_detail' => null,
            'fault_type' => $reason?->fault_type,
            'requested_by' => $requestedBy,
            'refund_amount' => $refundAmount,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => (float) $order->total_shipping_amount,
            'refund_priority' => RefundPriorityEnum::PG_FIRST,
            'mc_refund_amount' => $this->buildMultiCurrencyAmount($refundAmount),
            'mc_refund_points_amount' => $this->buildMultiCurrencyAmount(0),
            'mc_refund_shipping_amount' => $this->buildMultiCurrencyAmount((float) $order->total_shipping_amount),
            'refund_bank_code' => $refundBankCode,
            'refund_bank_account' => $refundBankCode !== null ? sprintf('%03d-%04d-%06d', rand(100, 999), rand(0, 9999), rand(0, 999999)) : null,
            'refund_bank_holder' => $refundBankCode !== null ? $address?->orderer_name : null,
            'pg_transaction_id' => $refundMethod === RefundMethodEnum::PG ? $payment->transaction_id : null,
            'approved_at' => $approvedAt,
            'refunded_at' => $refundedAt,
            'processed_by' => $this->adminId,
            'created_at' => $cancelledAt,
            'updated_at' => $refundedAt,
        ]));

        foreach ($order->options as $option) {
            $shippingShare = (float) ($shippingByOption[$option->id] ?? 0);

            OrderRefundOption::unguarded(fn () => OrderRefundOption::create([
                'order_refund_id' => $refund->id,
                'order_id' => $order->id,
                'order_option_id' => $option->id,
                'option_status' => RefundStatusEnum::COMPLETED,
                'quantity' => (int) $option->quantity,
                'unit_price' => (float) $option->unit_price,
                'subtotal_amount' => (float) $option->subtotal_price,
                'discount_amount' => (float) $option->subtotal_discount_amount,
                'shipping_amount' => $shippingShare,
                'refund_amount' => (float) $option->subtotal_paid_amount + $shippingShare,
                'completed_at' => $refundedAt,
                'processed_by' => $this->adminId,
                'created_at' => $cancelledAt,
                'updated_at' => $refundedAt,
            ]));
        }

        if ($refundMethod === RefundMethodEnum::BANK) {
            $payment->update([
                'refund_bank_code' => $refundBankCode,
                'refund_bank_name' => $refundBankName,
                'refund_bank_account' => $refund->refund_bank_account,
                'refund_bank_holder' => $refund->refund_bank_holder,
            ]);
        }
    }

    /**
     * 은행 목록(코드-이름 쌍)에서 하나를 고릅니다.
     *
     * @return array{0: string, 1: string} [은행 코드, 은행 이름]
     */
    private function randomBank(): array
    {
        $banks = (array) config('sirsoft-ecommerce.payment.banks', []);
        if ($banks === []) {
            return ['088', '신한은행'];
        }

        $bank = $banks[array_rand($banks)];

        return [(string) $bank['code'], (string) $bank['name']];
    }

    /**
     * 무통장입금 받을 계좌를 상점 설정에서 고릅니다. 켜진 계좌가 없으면 샘플 계좌를 씁니다.
     *
     * 은행 코드와 이름은 항상 같은 쌍이다 — 코드는 신한(088)인데 이름은 국민은행인 행을 만들지 않는다.
     *
     * @return array{0: string, 1: string, 2: string, 3: string} [은행 코드, 은행 이름, 계좌번호, 예금주]
     */
    private function shopBankAccount(): array
    {
        $accounts = (array) app(EcommerceSettingsService::class)->getSetting('order_settings.bank_accounts', []);
        $active = collect($accounts)->first(fn ($a) => is_array($a) && ! empty($a['is_active']) && ! empty($a['account_number']));

        if ($active) {
            $code = (string) $active['bank_code'];
            $name = collect((array) config('sirsoft-ecommerce.payment.banks', []))->firstWhere('code', $code)['name'] ?? $code;

            return [$code, $name, (string) $active['account_number'], (string) ($active['account_holder'] ?? '')];
        }

        [$code, $name] = $this->randomBank();

        return [$code, $name, sprintf('%03d-%03d-%06d', rand(100, 999), rand(100, 999), rand(0, 999999)), '샘플상점'];
    }

    /**
     * 랜덤 결제 방법 반환
     */
    private function getRandomPaymentMethod(): string
    {
        $rand = rand(1, 100);

        if ($rand <= 60) {
            return 'card';
        } elseif ($rand <= 85) {
            return 'dbank';
        }

        return 'vbank';
    }

    /**
     * CurrencyConversionService 인스턴스 캐시
     */
    private ?CurrencyConversionService $currencyConversionService = null;

    /**
     * CurrencyConversionService를 지연 로딩합니다.
     */
    private function getCurrencyConversionService(): CurrencyConversionService
    {
        if ($this->currencyConversionService === null) {
            $this->currencyConversionService = app(CurrencyConversionService::class);
        }

        return $this->currencyConversionService;
    }

    /**
     * 다중 통화 금액 생성
     *
     * OrderProcessingService::buildAllCurrencyConverter와 동일한 형식으로
     * CurrencyConversionService를 사용하여 등록된 모든 통화로 변환합니다.
     *
     * @param  float  $amount  KRW 기준 금액
     * @return array 다중 통화 데이터 {통화코드: 금액}
     */
    private function buildMultiCurrencyAmount(float $amount): array
    {
        $multiCurrency = $this->getCurrencyConversionService()->convertToMultiCurrency((int) $amount);

        $result = [];
        foreach ($multiCurrency as $code => $data) {
            $result[$code] = $data['price'];
        }

        // 변환 결과가 없으면 기본 통화만 반환
        if (empty($result)) {
            $baseCurrency = $this->getCurrencyConversionService()->getDefaultCurrency();
            $result[$baseCurrency] = $amount;
        }

        return $result;
    }

    /**
     * 주문 옵션의 추가옵션 스냅샷과 단위 합계를 생성합니다.
     *
     * 상품에 활성 추가옵션 선택지가 있으면 30% 확률로 그룹당 1개를 선택해 주문 시점 스냅샷으로
     * 동결합니다. 스냅샷 형식은 ProductAdditionalOptionValue::toSnapshotArray 와 정합합니다.
     *
     * @param  Product  $product  상품 모델
     * @return array{0: array<int, array>, 1: int} [스냅샷 배열, 단위당 추가금 합계(KRW)]
     */
    private function buildOrderOptionAdditionalOptions(Product $product): array
    {
        // 활성 추가옵션 그룹·선택지 로드
        $product->loadMissing('additionalOptions.activeValues');

        if ($product->additionalOptions->isEmpty()) {
            return [[], 0];
        }

        // 70% 는 추가옵션 미선택 (분포 다양화)
        if (rand(1, 100) > 30) {
            return [[], 0];
        }

        $snapshot = [];
        $total = 0;

        foreach ($product->additionalOptions as $group) {
            $values = $group->activeValues;
            if ($values->isEmpty()) {
                continue;
            }

            // 그룹당 1개 선택 (필수 그룹은 항상, 비필수는 60% 확률)
            if (! $group->is_required && rand(1, 100) > 60) {
                continue;
            }

            /** @var ProductAdditionalOptionValue $value */
            $value = $values->random();
            $entry = $value->toSnapshotArray();
            $entry['group_name'] = $group->name;
            $entry['is_required'] = (bool) $group->is_required;

            // 직접입력 선택지면 custom_text 더미 채움
            if ($value->allow_custom_text) {
                $entry['custom_text'] = '주문자 직접 입력 문구';
            }

            $snapshot[] = $entry;
            $total += $value->getPriceAdjustment();
        }

        return [$snapshot, $total];
    }

    /**
     * 통화 스냅샷 생성
     *
     * OrderProcessingService::buildCurrencySnapshot과 동일한 형식으로
     * 주문 생성 시점의 통화 정보를 반환합니다.
     *
     * @param  Carbon|null  $at  스냅샷 시각 (주문 시각, 생략하면 지금)
     * @return array 통화 스냅샷
     */
    private function buildCurrencySnapshot(?Carbon $at = null): array
    {
        $service = $this->getCurrencyConversionService();
        $baseCurrency = $service->getDefaultCurrency();
        $currencies = $service->getCurrencySettings();

        $exchangeRates = [];
        foreach ($currencies as $currency) {
            $code = $currency['code'];
            $rate = ($currency['is_default'] ?? false) ? 1.0 : ($currency['exchange_rate'] ?? 0);
            $exchangeRates[$code] = $rate;
        }

        return [
            'base_currency' => $baseCurrency,
            'order_currency' => $baseCurrency,
            'exchange_rate' => 1.0,
            'exchange_rates' => $exchangeRates,
            'snapshot_at' => ($at ?? now())->toIso8601String(),
        ];
    }

    /**
     * 옵션값 요약 문자열 생성 (다국어)
     *
     * ProductOption의 option_values를 로케일별 요약 문자열로 변환합니다.
     * 예: {"ko": "색상: 빨강, 사이즈: L", "en": "Color: Red, Size: L"}
     *
     * @param  array|null  $optionValues  ProductOption의 option_values
     * @return array 다국어 요약 문자열 배열
     */
    private function buildMultilingualOptionValue(?array $optionValues): array
    {
        if (empty($optionValues)) {
            return [];
        }

        // 새 구조: [{"key": {"ko": "색상"}, "value": {"ko": "빨강"}}]
        if (isset($optionValues[0]['key'])) {
            $locales = config('app.supported_locales', ['ko', 'en']);
            $result = [];

            foreach ($locales as $locale) {
                $parts = [];
                foreach ($optionValues as $item) {
                    $key = $item['key'] ?? [];
                    $value = $item['value'] ?? [];

                    $localizedKey = is_array($key) ? ($key[$locale] ?? $key['ko'] ?? array_values($key)[0] ?? '') : $key;
                    $localizedValue = is_array($value) ? ($value[$locale] ?? $value['ko'] ?? array_values($value)[0] ?? '') : $value;

                    if ($localizedKey !== '' && $localizedValue !== '') {
                        $parts[] = $localizedKey.': '.$localizedValue;
                    }
                }
                $result[$locale] = implode(', ', $parts);
            }

            return $result;
        }

        // 기존 구조: {"색상": "빨강"} (하위 호환성) - ko로만 반환
        $parts = [];
        foreach ($optionValues as $key => $value) {
            $parts[] = $key.': '.$value;
        }

        return ['ko' => implode(', ', $parts)];
    }

    /**
     * 주문 배송 정보 생성
     *
     * 주문 옵션마다 배송 행을 만든다. 배송 상태·택배사·송장·발송/배송완료/구매확정 시각은 주문 상태와
     * 시각 사슬을 따른다(송장이 있으면 택배사도 있다).
     *
     * @param  Order  $order  주문
     * @param  Collection  $orderOptions  주문 옵션 목록
     * @param  OrderStatusEnum  $status  주문 상태
     * @param  array  $timeline  시각 사슬 (buildTimeline)
     * @return void 반환값 없음
     */
    private function createOrderShippings(Order $order, $orderOptions, OrderStatusEnum $status, array $timeline): void
    {
        $shippingPolicies = $this->shippingPolicies ??= ShippingPolicy::with('countrySettings')->get();
        // 해외 배송(API) 정책 제외 — 시더에서 외부 API 호출 불가
        $domesticPolicies = $shippingPolicies->filter(function ($policy) {
            return $policy->countrySettings->contains(fn ($cs) => $cs->country_code === 'KR' && $cs->is_active);
        });

        $packageNumber = 'PKG-'.str_pad($order->id, 8, '0', STR_PAD_LEFT);
        $currencySnapshot = $order->currency_snapshot;

        foreach ($orderOptions as $orderOption) {
            $factory = OrderShippingFactory::new()
                ->forOrder($order)
                ->forOrderOption($orderOption);

            // 상태에 따른 배송 상태 — 송장은 배송정보 필수 상태(배송준비중·배송중) 이후에만 있고,
            // 송장이 있으면 택배사도 반드시 있다(택배사 없는 송장은 배송조회 링크를 만들 수 없다).
            $shippingStatus = match ($status) {
                OrderStatusEnum::PREPARING => ShippingStatusEnum::PREPARING,
                OrderStatusEnum::SHIPPING_READY => ShippingStatusEnum::READY,
                OrderStatusEnum::SHIPPING => ShippingStatusEnum::IN_TRANSIT,
                OrderStatusEnum::DELIVERED, OrderStatusEnum::CONFIRMED => ShippingStatusEnum::DELIVERED,
                default => ShippingStatusEnum::PENDING,
            };
            $hasTracking = $status->requiresShippingInfo() || $status->isShipped();
            $carrierId = $hasTracking ? $this->domesticCarrierId() : null;

            // 배송정책 기반 배송비 계산
            $policy = $domesticPolicies->isNotEmpty() ? $domesticPolicies->random() : null;
            $countrySetting = $policy?->countrySettings
                ->where('country_code', 'KR')
                ->where('is_active', true)
                ->first();

            $shippingAmount = $countrySetting
                ? $this->calculateSeederShippingFee($countrySetting, $orderOption)
                : 0;

            $extraAttributes = [
                'shipping_status' => $shippingStatus,
                'carrier_id' => $carrierId,
                'tracking_number' => $carrierId !== null ? (string) rand(100000000000, 999999999999) : null,
                'shipped_at' => $timeline['shipped'],
                'estimated_arrival_at' => $timeline['shipped']?->copy()->addDays(2),
                'delivered_at' => $timeline['delivered'],
                'confirmed_at' => $timeline['confirmed'],
                'created_at' => $timeline['ordered'],
                'updated_at' => $timeline['confirmed'] ?? $timeline['delivered'] ?? $timeline['shipped'] ?? $timeline['ordered'],
                'base_shipping_amount' => $shippingAmount,
                'total_shipping_amount' => $shippingAmount,
                // 필수 보완: 다중 통화
                'currency_snapshot' => $currencySnapshot,
                'mc_base_shipping_amount' => $this->buildMultiCurrencyAmount($shippingAmount),
                'mc_total_shipping_amount' => $this->buildMultiCurrencyAmount($shippingAmount),
                'mc_return_shipping_amount' => $this->buildMultiCurrencyAmount(0),
                // 선택적 보완: 묶음배송 번호
                'package_number' => $packageNumber,
            ];

            // 배송정책 연결 + 배송정책 스냅샷
            if ($policy) {
                $extraAttributes['shipping_policy_id'] = $policy->id;
                $extraAttributes['delivery_policy_snapshot'] = $this->buildDeliveryPolicySnapshot($policy, $shippingAmount);
            }

            $factory->create($extraAttributes);
        }
    }

    /**
     * 배송정책 목록 캐시 (주문마다 다시 읽지 않음)
     */
    private ?Collection $shippingPolicies = null;

    /**
     * 국내 택배사 id 캐시 (cj 우선)
     */
    private int|false|null $domesticCarrierId = null;

    /**
     * 송장에 붙일 활성 국내 택배사를 고릅니다 (CJ대한통운 우선, 없으면 첫 활성 국내 택배사).
     *
     * @return int|null 택배사 id (활성 국내 택배사가 없으면 null)
     */
    private function domesticCarrierId(): ?int
    {
        if ($this->domesticCarrierId === null) {
            $carriers = ShippingCarrier::query()->where('is_active', true)->where('type', 'domestic')->orderBy('sort_order')->get();
            $carrier = $carriers->firstWhere('code', 'cj') ?? $carriers->first();
            $this->domesticCarrierId = $carrier ? (int) $carrier->id : false;
        }

        return $this->domesticCarrierId === false ? null : $this->domesticCarrierId;
    }

    /**
     * 시더용 배송비 계산 (실제 OrderCalculationService 로직 간소화)
     *
     * charge_policy 기반으로 배송비를 산출합니다.
     * 실제 주문 시 calculateCountryShippingFee()와 동일한 분기 로직을 적용합니다.
     *
     * @param  ShippingPolicyCountrySetting  $countrySetting
     * @return int 배송비 (원)
     */
    private function calculateSeederShippingFee($countrySetting, OrderOption $orderOption): int
    {
        $subtotal = (int) $orderOption->subtotal_price;
        $quantity = (int) $orderOption->quantity;
        // 주문 옵션의 무게/부피는 g / cm³ 로 저장된다 (미입력 시 1개당 500g / 1,000cm³ 로 가정)
        $weightGram = (float) ($orderOption->subtotal_weight ?? $quantity * 500);
        $volumeCubicCm = (float) ($orderOption->subtotal_volume ?? $quantity * 1000);
        $baseFee = (int) round((float) $countrySetting->base_fee);
        $ranges = $countrySetting->ranges;

        // 배송정책의 구간·단위값은 kg / L 이므로 여기서 환산한다 (엔진과 동일 규칙)
        $volumeWeightDivisor = $ranges['volume_weight_divisor'] ?? 6000;
        $weightKg = $weightGram / 1000;
        $volumeLiter = $volumeCubicCm / 1000;
        $volumetricWeightKg = $volumeWeightDivisor > 0 ? $volumeCubicCm / $volumeWeightDivisor : 0.0;
        $chargeableWeightKg = max($weightKg, $volumetricWeightKg);

        return match ($countrySetting->charge_policy) {
            ChargePolicyEnum::FREE => 0,
            ChargePolicyEnum::FIXED => $baseFee,
            ChargePolicyEnum::CONDITIONAL_FREE => $subtotal >= (int) $countrySetting->free_threshold ? 0 : $baseFee,
            ChargePolicyEnum::RANGE_AMOUNT => $this->calculateSeederRangeFee($ranges, $subtotal),
            ChargePolicyEnum::RANGE_QUANTITY => $this->calculateSeederRangeFee($ranges, $quantity),
            ChargePolicyEnum::RANGE_WEIGHT => $this->calculateSeederRangeFee($ranges, $weightKg),
            ChargePolicyEnum::RANGE_VOLUME => $this->calculateSeederRangeFee($ranges, $volumeLiter),
            ChargePolicyEnum::RANGE_VOLUME_WEIGHT => $this->calculateSeederRangeFee($ranges, $chargeableWeightKg),
            ChargePolicyEnum::PER_QUANTITY => (int) round(ceil($quantity / max($ranges['unit_value'] ?? 1, 1)) * $baseFee),
            ChargePolicyEnum::PER_WEIGHT => (int) round(ceil($weightKg / max($ranges['unit_value'] ?? 0.5, 0.01)) * $baseFee),
            ChargePolicyEnum::PER_VOLUME => (int) round(ceil($volumeLiter / max($ranges['unit_value'] ?? 1, 0.01)) * $baseFee),
            ChargePolicyEnum::PER_VOLUME_WEIGHT => (int) round(ceil($chargeableWeightKg / max($ranges['unit_value'] ?? 0.5, 0.01)) * $baseFee),
            ChargePolicyEnum::PER_AMOUNT => (int) round(ceil($subtotal / max($ranges['unit_value'] ?? 10000, 1)) * $baseFee),
            ChargePolicyEnum::API => $baseFee, // API는 시더에서 base_fee 사용
            default => $baseFee,
        };
    }

    /**
     * 구간별 배송비 계산 (시더용 — 상한 포함 사다리 매칭)
     *
     * 구간을 상한 오름차순(무제한이 마지막)으로 정렬한 뒤, 값이 상한 이하인 첫 구간의
     * 배송비를 반환합니다. 시작값은 표시용이라 매칭에 쓰지 않습니다
     * (OrderCalculationService::calculateRangeFee 와 동일 규칙).
     *
     * @param  array|null  $ranges  구간 설정
     * @param  int|float  $value  비교 값 (금액·수량·kg·L)
     * @return int 배송비
     */
    private function calculateSeederRangeFee(?array $ranges, int|float $value): int
    {
        $tiers = is_array($ranges) ? ($ranges['tiers'] ?? []) : [];

        if (! is_array($tiers) || count($tiers) === 0) {
            return 0;
        }

        $sortedTiers = array_values($tiers);
        usort($sortedTiers, function ($a, $b) {
            $aMax = ($a['max'] ?? null) === null || ($a['max'] ?? null) === '' ? null : (float) $a['max'];
            $bMax = ($b['max'] ?? null) === null || ($b['max'] ?? null) === '' ? null : (float) $b['max'];

            if ($aMax === null && $bMax === null) {
                return 0;
            }

            return $aMax === null ? 1 : ($bMax === null ? -1 : $aMax <=> $bMax);
        });

        foreach ($sortedTiers as $tier) {
            $max = ($tier['max'] ?? null) === null || ($tier['max'] ?? null) === '' ? null : (float) $tier['max'];

            if ($max === null || (float) $value <= $max) {
                return (int) round((float) ($tier['fee'] ?? 0));
            }
        }

        return 0;
    }

    /**
     * 사용자별 사용 가능한 쿠폰 발급 목록을 로드합니다.
     *
     * CouponSeeder에서 생성한 available 상태의 발급 내역을
     * 쿠폰 정보와 함께 사용자별로 그룹화하여 반환합니다.
     *
     * @return array<int, Collection> [user_id => Collection<CouponIssue>]
     */
    private function loadAvailableCouponIssuesByUser(): array
    {
        $issues = CouponIssue::with('coupon')
            ->where('status', CouponIssueRecordStatus::AVAILABLE)
            ->whereHas('coupon', fn ($q) => $q->whereNull('deleted_at'))
            ->get();

        if ($issues->isEmpty()) {
            $this->command->line('  - 사용 가능한 쿠폰 발급 내역이 없습니다. 쿠폰 미적용 주문만 생성합니다.');

            return [];
        }

        // Collection 그대로 유지 (모델 인스턴스 보존)
        $grouped = $issues->groupBy('user_id')->all();
        $this->command->line("  - 쿠폰 발급 내역 {$issues->count()}건 로드 (".count($grouped).'명 사용자)');

        return $grouped;
    }

    /**
     * 주문 옵션에 쿠폰 할인을 적용합니다.
     *
     * 사용자의 사용 가능한 쿠폰 중 1~2개를 선택하여 적용합니다.
     * - 상품금액 쿠폰 (product_amount): 각 옵션에 균등 분배
     * - 주문금액 쿠폰 (order_amount): 각 옵션에 금액 비율로 배분
     * - 배송비 쿠폰 (shipping_fee): 배송 레코드 생성 후 적용하기 위해 반환
     * 적용 후 CouponIssue 상태를 'used'로 업데이트합니다.
     *
     * @param  Order  $order  주문
     * @param  Collection  $orderOptions  주문 옵션 목록
     * @param  Collection  $userCouponIssues  사용자의 쿠폰 발급 목록
     * @return array 배송비 쿠폰 목록 (배송 레코드 생성 후 적용 필요)
     */
    private function applyCouponsToOrderOptions(Order $order, $orderOptions, $userCouponIssues): array
    {
        // Collection으로 변환 (array로 전달된 경우)
        $issues = collect($userCouponIssues);

        // 사용 가능한 쿠폰 필터링 (available 상태, 만료되지 않은 것)
        $available = $issues->filter(function ($issue) {
            if (! ($issue instanceof CouponIssue)) {
                return false;
            }

            return $issue->status === CouponIssueRecordStatus::AVAILABLE
                && $issue->coupon
                && ($issue->expired_at === null || $issue->expired_at->isFuture());
        });

        if ($available->isEmpty()) {
            return [];
        }

        $orderSubtotal = $orderOptions->sum('subtotal_price');

        // 1~2개 쿠폰 랜덤 선택 (중복불가 쿠폰은 1개만)
        $selected = $this->selectCouponsForOrder($available, $orderSubtotal, $orderOptions);

        if (empty($selected)) {
            return [];
        }

        // 배송비 쿠폰과 상품/주문 쿠폰 분리
        $shippingCoupons = [];
        $optionCoupons = [];
        foreach ($selected as $issueData) {
            if ($issueData['coupon']->target_type === CouponTargetType::SHIPPING_FEE) {
                $shippingCoupons[] = $issueData;
            } else {
                $optionCoupons[] = $issueData;
            }
        }

        // 쿠폰별 할인금액 계산 및 옵션에 배분
        $promotionSnapshot = ['coupons' => [], 'discount_codes' => [], 'events' => []];

        foreach ($optionCoupons as $issueData) {
            /** @var CouponIssue $issueModel */
            $issueModel = $issueData['issue'];
            $coupon = $issueData['coupon'];
            $discountAmount = $issueData['discount'];

            // 옵션별 할인 배분
            $this->distributeDiscountToOptions($orderOptions, $coupon, $discountAmount, $orderSubtotal);

            // CouponIssue 상태 업데이트 (메모리 상 모델도 갱신하여 중복 선택 방지)
            $issueModel->status = CouponIssueRecordStatus::USED;
            $issueModel->used_at = $order->ordered_at ?? now();
            $issueModel->order_id = $order->id;
            $issueModel->discount_amount = $discountAmount;
            $issueModel->save();

            // 소진 기록 추가 (보충용)
            $this->usedCouponRecords[] = ['user_id' => $issueModel->user_id, 'coupon_id' => $coupon->id];

            // 프로모션 스냅샷 빌드
            $promotionSnapshot['coupons'][] = $this->buildCouponSnapshotEntry($coupon, $issueModel, $discountAmount, $orderOptions);
        }

        // 배송비 쿠폰도 CouponIssue 사용 처리 + 스냅샷 추가
        foreach ($shippingCoupons as $issueData) {
            /** @var CouponIssue $issueModel */
            $issueModel = $issueData['issue'];
            $coupon = $issueData['coupon'];
            $discountAmount = $issueData['discount'];

            $issueModel->status = CouponIssueRecordStatus::USED;
            $issueModel->used_at = $order->ordered_at ?? now();
            $issueModel->order_id = $order->id;
            $issueModel->discount_amount = $discountAmount;
            $issueModel->save();

            // 소진 기록 추가 (보충용)
            $this->usedCouponRecords[] = ['user_id' => $issueModel->user_id, 'coupon_id' => $coupon->id];

            $promotionSnapshot['coupons'][] = $this->buildCouponSnapshotEntry($coupon, $issueModel, $discountAmount, $orderOptions);
        }

        // 모든 옵션에 promotions_applied_snapshot 업데이트
        if (! empty($promotionSnapshot['coupons'])) {
            foreach ($orderOptions as $opt) {
                $opt->update(['promotions_applied_snapshot' => $promotionSnapshot]);
            }
        }

        // 주문 레벨 promotions_applied_snapshot 저장
        // OrderAdjustmentService.buildRecalcInput()이 쿠폰 재적용에 필요
        $allSelected = array_merge($optionCoupons, $shippingCoupons);
        if (! empty($allSelected)) {
            $orderSnapshot = $this->buildOrderPromotionsSnapshot($allSelected, $orderOptions);
            $order->update(['promotions_applied_snapshot' => $orderSnapshot]);
        }

        return $shippingCoupons;
    }

    /**
     * 쿠폰 스냅샷 항목을 생성합니다.
     *
     * @param  Coupon  $coupon  쿠폰 모델
     * @param  CouponIssue  $issueModel  쿠폰 발급 모델
     * @param  int  $discountAmount  할인금액
     * @param  Collection  $orderOptions  주문 옵션 목록
     */
    private function buildCouponSnapshotEntry(Coupon $coupon, CouponIssue $issueModel, int $discountAmount, $orderOptions): array
    {
        $couponName = is_array($coupon->name)
            ? ($coupon->name['ko'] ?? $coupon->name['en'] ?? '')
            : (string) $coupon->name;

        return [
            'coupon_id' => $coupon->id,
            'coupon_issue_id' => $issueModel->id,
            'name' => $couponName,
            'target_type' => $coupon->target_type->value,
            'target_scope' => $coupon->target_scope,
            'discount_type' => $coupon->discount_type->value,
            'discount_value' => (float) $coupon->discount_value,
            'total_discount' => $discountAmount,
            'total_discount_formatted' => ecommerce_format_price($discountAmount),
            'applied_items' => $orderOptions->map(fn ($opt) => [
                'product_option_id' => $opt->product_option_id,
                'discount_amount' => (int) $opt->product_coupon_discount_amount + (int) $opt->order_coupon_discount_amount,
                'discount_amount_formatted' => ecommerce_format_price((int) $opt->product_coupon_discount_amount + (int) $opt->order_coupon_discount_amount),
            ])->toArray(),
            'is_exclusive' => ! $coupon->is_combinable,
            'min_order_amount' => (int) $coupon->min_order_amount,
            'max_discount_amount' => (int) ($coupon->discount_max_amount ?? 0),
        ];
    }

    /**
     * 주문에 적용할 쿠폰을 선택합니다.
     *
     * 최소 주문금액 조건을 만족하는 쿠폰 중 1~2개를 선택합니다.
     * 중복불가(is_combinable=false) 쿠폰은 단독 적용됩니다.
     *
     * @param  Collection  $available  사용 가능한 쿠폰 발급 목록
     * @param  float  $orderSubtotal  주문 소계
     * @return array [{issue, coupon, discount}]
     */
    private function selectCouponsForOrder($available, float $orderSubtotal, $orderOptions = null): array
    {
        $candidates = [];

        foreach ($available as $issueModel) {
            if (! ($issueModel instanceof CouponIssue) || ! $issueModel->coupon) {
                continue;
            }

            $coupon = $issueModel->coupon;

            // 최소 주문금액 조건
            if ($orderSubtotal < (float) $coupon->min_order_amount) {
                continue;
            }

            // 상품금액 정액 쿠폰: 적용 대상 옵션의 총 수량만큼 할인
            $totalQuantity = 1;
            if ($orderOptions
                && $coupon->target_type === CouponTargetType::PRODUCT_AMOUNT
                && $coupon->discount_type === CouponDiscountType::FIXED
            ) {
                $totalQuantity = $this->getTargetQuantity($coupon, $orderOptions);
            }

            $discount = $this->calculateCouponDiscount($coupon, $orderSubtotal, $totalQuantity);
            if ($discount <= 0) {
                continue;
            }

            $candidates[] = [
                'issue' => $issueModel,
                'coupon' => $coupon,
                'discount' => $discount,
            ];
        }

        if (empty($candidates)) {
            return [];
        }

        // 중복불가 쿠폰이 있으면 단독 적용
        $exclusives = array_values(array_filter($candidates, fn ($c) => ! $c['coupon']->is_combinable));
        if (! empty($exclusives) && rand(1, 100) <= 30) {
            return [$exclusives[array_rand($exclusives)]];
        }

        // 중복 가능 쿠폰 중 1~2개 선택
        $combinables = array_values(array_filter($candidates, fn ($c) => $c['coupon']->is_combinable));
        if (empty($combinables)) {
            // 중복 가능 쿠폰이 없으면 중복불가 쿠폰 1개
            return [$exclusives[array_rand($exclusives)]];
        }

        $combinables = array_values($combinables);
        shuffle($combinables);
        $pickCount = min(rand(1, 2), count($combinables));

        // 같은 target_type 쿠폰 중복 방지
        $picked = [];
        $usedTypes = [];
        foreach ($combinables as $c) {
            if (count($picked) >= $pickCount) {
                break;
            }
            if (in_array($c['coupon']->target_type, $usedTypes)) {
                continue;
            }
            $picked[] = $c;
            $usedTypes[] = $c['coupon']->target_type;
        }

        return $picked;
    }

    /**
     * 쿠폰의 할인금액을 계산합니다.
     *
     * @param  Coupon  $coupon  쿠폰 모델
     * @param  float  $orderSubtotal  주문 소계
     * @return int 할인금액
     */
    private function calculateCouponDiscount(Coupon $coupon, float $orderSubtotal, int $quantity = 1): int
    {
        if ($coupon->discount_type === CouponDiscountType::FIXED) {
            // 정액 할인: 수량만큼 할인 (상품금액 쿠폰에서 수량 전달)
            $discount = (int) $coupon->discount_value * $quantity;
        } else {
            // 정률 할인
            $discount = (int) floor($orderSubtotal * (float) $coupon->discount_value / 100);

            // 최대 할인금액 제한
            if ($coupon->discount_max_amount && $discount > (int) $coupon->discount_max_amount) {
                $discount = (int) $coupon->discount_max_amount;
            }
        }

        // 할인금액이 주문금액을 초과하지 않도록
        return min($discount, (int) $orderSubtotal);
    }

    /**
     * 할인금액을 주문 옵션에 배분합니다.
     *
     * - 상품금액 쿠폰: product_coupon_discount_amount에 배분
     * - 주문금액 쿠폰: order_coupon_discount_amount에 금액 비율로 배분
     *
     * @param  Collection  $orderOptions  주문 옵션 목록
     * @param  Coupon  $coupon  쿠폰 모델
     * @param  int  $totalDiscount  총 할인금액
     * @param  float  $orderSubtotal  주문 소계
     * @return void 반환값 없음
     */
    private function distributeDiscountToOptions($orderOptions, Coupon $coupon, int $totalDiscount, float $orderSubtotal): void
    {
        $isProductCoupon = $coupon->target_type === CouponTargetType::PRODUCT_AMOUNT;
        $discountField = $isProductCoupon ? 'product_coupon_discount_amount' : 'order_coupon_discount_amount';

        $remaining = $totalDiscount;
        $optionCount = $orderOptions->count();

        foreach ($orderOptions as $index => $opt) {
            // 마지막 옵션에 나머지 배분 (반올림 오차 방지)
            if ($index === $optionCount - 1) {
                $share = $remaining;
            } else {
                // 금액 비율로 배분
                $ratio = $orderSubtotal > 0 ? (float) $opt->subtotal_price / $orderSubtotal : (1 / $optionCount);
                $share = (int) floor($totalDiscount * $ratio);
            }

            $remaining -= $share;
            $currentFieldValue = (int) $opt->$discountField;
            $currentCouponTotal = (int) $opt->coupon_discount_amount;
            $newFieldValue = $currentFieldValue + $share;
            $newCouponTotal = $currentCouponTotal + $share;
            $newSubtotalDiscount = (int) $opt->subtotal_discount_amount + $share;
            $newFinalAmount = (int) $opt->subtotal_price - $newSubtotalDiscount;

            $opt->update([
                $discountField => $newFieldValue,
                'coupon_discount_amount' => $newCouponTotal,
                'subtotal_discount_amount' => $newSubtotalDiscount,
                'subtotal_paid_amount' => $newFinalAmount,
                'subtotal_tax_amount' => round($newFinalAmount / 11, 2),
                'subtotal_earned_points_amount' => $this->earnPointsFor((float) $newFinalAmount),
                // 다중 통화
                'mc_'.($isProductCoupon ? 'product_coupon' : 'order_coupon').'_discount_amount' => $this->buildMultiCurrencyAmount($newFieldValue),
                'mc_coupon_discount_amount' => $this->buildMultiCurrencyAmount($newCouponTotal),
                'mc_subtotal_tax_amount' => $this->buildMultiCurrencyAmount(round($newFinalAmount / 11, 2)),
                'mc_final_amount' => $this->buildMultiCurrencyAmount($newFinalAmount),
            ]);
        }
    }

    /**
     * 쿠폰 적용 대상 옵션의 총 수량을 계산합니다.
     *
     * target_scope에 따라:
     * - ALL: 모든 옵션의 수량 합계
     * - PRODUCTS: 쿠폰에 포함된 상품의 옵션 수량 합계
     * - CATEGORIES: 쿠폰에 포함된 카테고리에 속한 상품의 옵션 수량 합계
     *
     * @param  Coupon  $coupon  쿠폰 모델
     * @param  Collection  $orderOptions  주문 옵션 목록
     * @return int 적용 대상 총 수량
     */
    private function getTargetQuantity(Coupon $coupon, $orderOptions): int
    {
        if ($coupon->target_scope === CouponTargetScope::ALL || $coupon->target_scope === null) {
            return $orderOptions->sum('quantity');
        }

        if ($coupon->target_scope === CouponTargetScope::PRODUCTS) {
            $includedProductIds = $coupon->includedProducts()->pluck('product_id')->toArray();
            if (empty($includedProductIds)) {
                return $orderOptions->sum('quantity');
            }

            return $orderOptions
                ->filter(fn ($opt) => in_array($opt->product_id, $includedProductIds))
                ->sum('quantity');
        }

        if ($coupon->target_scope === CouponTargetScope::CATEGORIES) {
            $includedCategoryIds = $coupon->includedCategories()->pluck('category_id')->toArray();
            if (empty($includedCategoryIds)) {
                return $orderOptions->sum('quantity');
            }

            return $orderOptions
                ->filter(function ($opt) use ($includedCategoryIds) {
                    $product = Product::find($opt->product_id);
                    if (! $product) {
                        return false;
                    }
                    $productCategoryIds = $product->categories()->pluck('id')->toArray();

                    return ! empty(array_intersect($productCategoryIds, $includedCategoryIds));
                })
                ->sum('quantity');
        }

        return $orderOptions->sum('quantity');
    }

    /**
     * 주문 레벨 promotions_applied_snapshot을 생성합니다.
     *
     * OrderAdjustmentService.buildRecalcInput()이 부분취소 시 쿠폰을 재적용하기 위해
     * coupon_issue_ids, product_promotions, order_promotions 구조가 필요합니다.
     *
     * @param  array  $selectedCoupons  적용된 쿠폰 목록 [{issue, coupon, discount}]
     * @param  Collection  $orderOptions  주문 옵션 목록
     * @return array 주문 레벨 스냅샷
     */
    private function buildOrderPromotionsSnapshot(array $selectedCoupons, $orderOptions): array
    {
        $couponIssueIds = [];
        $productPromotionCoupons = [];
        $orderPromotionCoupons = [];

        foreach ($selectedCoupons as $issueData) {
            /** @var CouponIssue $issueModel */
            $issueModel = $issueData['issue'];
            $coupon = $issueData['coupon'];
            $discountAmount = $issueData['discount'];

            $couponIssueIds[] = $issueModel->id;

            $couponName = is_array($coupon->name)
                ? ($coupon->name['ko'] ?? $coupon->name['en'] ?? '')
                : (string) $coupon->name;

            $entry = [
                'coupon_issue_id' => $issueModel->id,
                'coupon_id' => $coupon->id,
                'name' => $couponName,
                'discount_type' => $coupon->discount_type->value,
                'discount_value' => (float) $coupon->discount_value,
                'min_order_amount' => (int) $coupon->min_order_amount,
                'max_discount_amount' => (int) ($coupon->discount_max_amount ?? 0),
                'target_type' => $coupon->target_type->value,
                'target_scope' => $coupon->target_scope,
                'is_exclusive' => ! $coupon->is_combinable,
                'total_discount' => $discountAmount,
                'applied_items' => $orderOptions->map(fn ($opt) => [
                    'product_option_id' => $opt->product_option_id,
                    'discount_amount' => (int) $opt->product_coupon_discount_amount + (int) $opt->order_coupon_discount_amount,
                ])->toArray(),
            ];

            // target_type에 따라 product_promotions 또는 order_promotions에 분류
            if ($coupon->target_type === CouponTargetType::PRODUCT_AMOUNT) {
                $productPromotionCoupons[] = $entry;
            } else {
                // order_amount, shipping_fee → order_promotions
                $orderPromotionCoupons[] = $entry;
            }
        }

        return [
            'coupon_issue_ids' => $couponIssueIds,
            'item_coupons' => [],
            'discount_code' => null,
            'product_promotions' => [
                'coupons' => $productPromotionCoupons,
            ],
            'order_promotions' => [
                'coupons' => $orderPromotionCoupons,
            ],
        ];
    }

    /**
     * 배송비 쿠폰을 배송 레코드에 적용합니다.
     *
     * 배송비 쿠폰의 할인금액을 배송 레코드의 shipping_discount_amount에 배분하고,
     * total_shipping_amount를 재계산합니다.
     *
     * @param  Order  $order  주문
     * @param  array  $shippingCoupons  배송비 쿠폰 목록 [{issue, coupon, discount}]
     * @return void 반환값 없음
     */
    private function applyShippingCouponsToShippings(Order $order, array $shippingCoupons): void
    {
        $shippings = OrderShipping::where('order_id', $order->id)->get();
        if ($shippings->isEmpty()) {
            return;
        }

        foreach ($shippingCoupons as $issueData) {
            $coupon = $issueData['coupon'];
            $totalShippingAmount = $shippings->sum('total_shipping_amount');

            if ($totalShippingAmount <= 0) {
                continue;
            }

            // 배송비 쿠폰 할인: 실제 배송비를 초과하지 않도록 제한
            $discount = min($issueData['discount'], (int) $totalShippingAmount);

            // 배송 레코드별 배분 (배송비 비율 기준)
            $remaining = $discount;
            $shippingCount = $shippings->count();

            foreach ($shippings as $index => $shipping) {
                if ($index === $shippingCount - 1) {
                    $share = $remaining;
                } else {
                    $ratio = $totalShippingAmount > 0
                        ? (float) $shipping->total_shipping_amount / $totalShippingAmount
                        : (1 / $shippingCount);
                    $share = (int) floor($discount * $ratio);
                }

                $remaining -= $share;
                $currentDiscount = (int) $shipping->shipping_discount_amount;
                $newDiscount = $currentDiscount + $share;
                $newTotalShipping = max(0, (int) $shipping->base_shipping_amount - $newDiscount);

                $shipping->update([
                    'shipping_discount_amount' => $newDiscount,
                    'total_shipping_amount' => $newTotalShipping,
                    'mc_shipping_discount_amount' => $this->buildMultiCurrencyAmount($newDiscount),
                    'mc_total_shipping_amount' => $this->buildMultiCurrencyAmount($newTotalShipping),
                ]);
            }
        }
    }

    /**
     * 배송정책 스냅샷 생성
     *
     * AppliedShippingPolicy DTO의 toArray() 형식과 동일한 구조로
     * 주문 시점의 배송정책 정보를 스냅샷합니다.
     * 국가별 설정(countrySettings)에서 첫 번째 활성 설정을 기반으로 생성합니다.
     *
     * @param  ShippingPolicy  $policy  배송정책 모델
     * @param  float  $shippingAmount  배송비
     * @return array 배송정책 스냅샷
     */
    private function buildDeliveryPolicySnapshot(ShippingPolicy $policy, float $shippingAmount): array
    {
        $countrySetting = $policy->countrySettings()->where('is_active', true)->first();

        return [
            'policy_id' => $policy->id,
            'policy_name' => $policy->getLocalizedName(),
            'country_code' => $countrySetting?->country_code ?? 'KR',
            'charge_policy' => $countrySetting?->charge_policy?->value ?? '',
            'shipping_method' => $countrySetting?->shipping_method ?? '',
            'base_fee' => (float) ($countrySetting?->base_fee ?? 0),
            'free_threshold' => $countrySetting?->free_threshold ? (float) $countrySetting->free_threshold : null,
            'currency_code' => $countrySetting?->currency_code ?? $this->getCurrencyConversionService()->getDefaultCurrency(),
            'extra_fee_enabled' => (bool) ($countrySetting?->extra_fee_enabled ?? false),
            'shipping_amount' => $shippingAmount,
            'snapshot_at' => now()->toIso8601String(),
        ];
    }

    /**
     * 주문 생성 중 소진된 쿠폰을 보충합니다.
     *
     * 주문 생성 과정에서 기록된 소진 내역(usedCouponRecords)을 기반으로,
     * 해당 사용자-쿠폰 조합에 available 이슈를 1건씩 재발급합니다.
     *
     * @return void 반환값 없음
     */
    private function replenishAvailableCoupons(): void
    {
        if (empty($this->usedCouponRecords)) {
            return;
        }

        // 중복 제거 (동일 사용자-쿠폰 조합은 1건만 보충)
        $unique = collect($this->usedCouponRecords)
            ->unique(fn ($r) => $r['user_id'].'-'.$r['coupon_id']);

        $now = now();
        $replenished = 0;
        $perCoupon = [];

        $coupons = Coupon::whereIn('id', $unique->pluck('coupon_id')->unique())->get()->keyBy('id');

        foreach ($unique as $record) {
            // 1인 발급 한도와 총 발급 수량을 지킨다 — 한도를 넘는 보충은 실제로는 받을 수 없는 쿠폰이다
            $coupon = $coupons->get($record['coupon_id']);
            $userIssued = CouponIssue::where('coupon_id', $record['coupon_id'])->where('user_id', $record['user_id'])->count();
            $issuedTotal = (int) ($coupon?->issued_count ?? 0) + ($perCoupon[$record['coupon_id']] ?? 0);
            if ($coupon === null
                || ($coupon->per_user_limit && $userIssued >= (int) $coupon->per_user_limit)
                || ($coupon->total_quantity !== null && $issuedTotal >= (int) $coupon->total_quantity)) {
                continue;
            }

            CouponIssue::create([
                'coupon_id' => $record['coupon_id'],
                'user_id' => $record['user_id'],
                'coupon_code' => null,
                'status' => CouponIssueRecordStatus::AVAILABLE,
                'issued_at' => $now,
                'expired_at' => null,
                'used_at' => null,
                'order_id' => null,
                'discount_amount' => null,
            ]);

            $perCoupon[$record['coupon_id']] = ($perCoupon[$record['coupon_id']] ?? 0) + 1;
            $replenished++;
        }

        // 발급수 카운터 동기화 — 보충 발급도 발급이므로 issued_count 에 반영한다.
        // 누락하면 쿠폰 목록의 "발급수" 와 발급 이력의 "총 N건" 이 영구히 어긋난다.
        foreach ($perCoupon as $couponId => $count) {
            Coupon::where('id', $couponId)->increment('issued_count', $count);
        }

        $this->command->line("  - 주문서 테스트용 쿠폰 {$replenished}건 보충 발급 완료");
    }
}
