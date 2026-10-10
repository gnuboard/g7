<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use App\Models\User;
use App\Traits\HasSeederCounts;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageEarnTriggerEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\MileageBalance;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageBalanceRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageTransactionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\SequenceService;
use Modules\Sirsoft\Ecommerce\Support\MileageRounding;

/**
 * 마일리지 거래 더미 데이터 시더 (실주문 기반 — 합성 거래 없음)
 *
 * 실제 운영 흐름(UserMileageService)을 그대로 재현한다. 마일리지는 임의로 찍지 않고
 * OrderSeeder 가 만든 실제 주문에서 발생시키며, 회원마다 사건을 **시각순**으로 처리한다
 * (사용이 그보다 뒤에 생긴 적립을 소비하는 시간 역행을 막는다):
 *
 *  1. 사용(order_use): 결제된 주문 일부에서 주문 시각에 사용. 보유 잔액·최소 사용액·사용 단위·
 *     최대 사용 한도(설정) 안에서만 쓰고, 쓴 만큼 주문·옵션·결제 행·환불신청서의 현금 금액을 줄인다
 *     (OrderCalculationService 와 같이 결제금액 = 상품금액 − 할인 − 마일리지). 적립 예정액도 다시 계산한다.
 *  2. 적립(purchase_earn): 설정의 적립 시점(구매확정/배송완료)+지연일이 지난 주문의 옵션 적립액.
 *     시점이 아직 오지 않은 주문은 적립하지 않는다 — 화면의 「적립 예정」으로 보인다.
 *  3. 취소 복원(order_cancel_restore): 마일리지를 쓴 결제 후 취소 주문은 취소 시각에 사용액 전액을
 *     복원한다. 취소신청서는 OrderSeeder 가 만든 것을 연결한다(이 시더는 취소 문서를 만들지 않는다).
 *  4. 관리자 지급/차감(admin_earn/admin_deduct), 소멸(expired): 주문과 무관한 실제 경로 그대로.
 *
 * 마일리지 기능이 꺼져 있어도 시연용으로 만든다(적립률·절사·한도는 설정값). 이 경우 출력에 안내한다.
 * 잔액 캐시(ecommerce_mileage_balances)는 손으로 만들지 않고 원장에서 단방향 재계산한다.
 */
class MileageSeeder extends Seeder
{
    use HasSeederCounts;

    /**
     * 설정의 기본 통화 코드 캐시 (KRW 하드코딩 제거 — base 추종)
     */
    private ?string $baseCurrencyCode = null;

    /**
     * 설정의 기본 통화 코드를 반환합니다.
     *
     * 마일리지 금액(액수)은 그대로 두되 통화 라벨만 설정의 default_currency 로 맞춥니다.
     *
     * @return string 기본 통화 코드
     */
    private function baseCurrency(): string
    {
        if ($this->baseCurrencyCode === null) {
            $this->baseCurrencyCode = app(CurrencyConversionService::class)
                ->getDefaultCurrency();
        }

        return $this->baseCurrencyCode;
    }

    /**
     * 회원의 결제된 주문 중 마일리지를 사용할 비율 (%)
     */
    private const USAGE_ORDER_PERCENTAGE = 35;

    /**
     * 결제 후 취소 주문 중 마일리지를 사용했던 비율 (%) — 취소 복원 시나리오 노출용
     */
    private const CANCELLED_ORDER_USAGE_PERCENTAGE = 70;

    /**
     * 관리자 수동 지급을 받을 회원 비율 (%)
     */
    private const ADMIN_EARN_PERCENTAGE = 20;

    /**
     * 관리자 수동 차감 대상 회원 비율 (관리자 지급 회원 중, %)
     */
    private const ADMIN_DEDUCT_PERCENTAGE = 40;

    /**
     * 소멸(만료) 처리할 회원 비율 (%)
     */
    private const EXPIRE_PERCENTAGE = 15;

    /**
     * 같은 시각 사건의 처리 순서 (작을수록 먼저)
     */
    private const EVENT_ORDER = [
        'guarantee' => 0,
        'admin_earn' => 1,
        'use' => 2,
        'restore' => 3,
        'earn' => 4,
        'admin_deduct' => 5,
    ];

    /**
     * 유효기간 일수 (설정값으로 덮어씀)
     */
    private int $expiryDays = 365;

    /**
     * 통화별 사용 단위 (설정값으로 덮어씀)
     */
    private int $useUnit = 10;

    /**
     * 최소 사용 금액 (설정값으로 덮어씀)
     */
    private int $minUseAmount = 1000;

    /**
     * 기본 통화 마일리지 규칙 (최대 사용 한도·절사 기준)
     *
     * @var array<string, mixed>
     */
    private array $currencyRule = [];

    /**
     * 적립 시점 (설정 earn_trigger)
     */
    private MileageEarnTriggerEnum $earnTrigger = MileageEarnTriggerEnum::CONFIRMED;

    /**
     * 적립 지연일 (설정 earn_delay_days)
     */
    private int $earnDelayDays = 0;

    /**
     * 기본 적립률 (%)
     */
    private float $earnRate = 1.0;

    /**
     * 마일리지 기능 켜짐 여부 (꺼져 있어도 시연용으로 생성하고 안내만 한다)
     */
    private bool $mileageEnabled = false;

    /**
     * 재계산 대상 (user_id => [currency => true])
     *
     * @var array<int, array<string, bool>>
     */
    private array $touchedCurrencies = [];

    /**
     * 통계 집계
     *
     * @var array<string, int>
     */
    private array $stats = [
        'members' => 0,
        'earn' => 0,
        'pending' => 0,
        'use' => 0,
        'cancel_restore' => 0,
        'admin_earn' => 0,
        'admin_deduct' => 0,
        'expired' => 0,
    ];

    /**
     * 시더 실행
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $this->command->info('마일리지 거래 더미 데이터 생성을 시작합니다. (실주문 기반)');

        $this->resolveSettings();
        $this->deleteExistingTransactions();

        $members = $this->loadMembersWithOrders();
        if ($members->isEmpty()) {
            $this->command->warn('  - 주문을 가진 회원이 없어 마일리지 데이터를 생성하지 않습니다. (OrderSeeder 선행 필요)');

            return;
        }

        $admins = $this->resolveAdmins();

        $progressBar = $this->command->getOutput()->createProgressBar($members->count());
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');

        foreach ($members as $member) {
            $this->buildMemberMileage($member, $admins);
            $this->stats['members']++;
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->command->newLine();

        $this->reconcileBalanceCache();
        $this->reportStats();
    }

    /**
     * 환경설정에서 유효기간·사용 규칙·적립 시점·적립률을 읽어옵니다.
     *
     * @return void 반환값 없음
     */
    private function resolveSettings(): void
    {
        $settings = app(EcommerceSettingsService::class);
        $this->mileageEnabled = (bool) $settings->getSetting('mileage.enabled', false);
        $this->expiryDays = max(1, (int) $settings->getSetting('mileage.expiry_days', 365));
        $this->earnTrigger = MileageEarnTriggerEnum::tryFrom((string) $settings->getSetting('mileage.earn_trigger', 'confirmed'))
            ?? MileageEarnTriggerEnum::CONFIRMED;
        $this->earnDelayDays = max(0, (int) $settings->getSetting('mileage.earn_delay_days', 0));
        $this->earnRate = max(0.0, (float) $settings->getSetting('mileage.default_earn_rate', 1));

        $rules = (array) $settings->getSetting('mileage.currency_rules', []);
        $rule = collect($rules)->firstWhere('currency_code', $this->baseCurrency()) ?? ($rules[0] ?? []);
        $this->currencyRule = is_array($rule) ? $rule : [];
        $this->useUnit = max(1, (int) ($this->currencyRule['use_unit'] ?? 10));
        $this->minUseAmount = max(0, (int) ($this->currencyRule['min_use_amount'] ?? 1000));
    }

    /**
     * 기존 마일리지 데이터를 지우고, 이전 실행이 주문에 기록한 사용 흔적을 되돌립니다 (재실행 멱등).
     *
     * 주문 문서(취소·환불신청서)는 OrderSeeder 소유라 지우지 않는다 — 사용액만큼 줄였던 현금 금액을 원래대로 돌린다.
     *
     * @return void 반환값 없음
     */
    private function deleteExistingTransactions(): void
    {
        $existing = MileageTransaction::count();

        MileageTransaction::query()->delete();
        MileageBalance::query()->delete();

        Order::query()->where('total_points_used_amount', '>', 0)->with('options')->get()
            ->each(function (Order $order) {
                $shares = $order->options->mapWithKeys(fn (OrderOption $o) => [$o->id => (int) round((float) $o->subtotal_points_used_amount)])->all();
                $this->applyPointsToOrder($order, $shares, -1);
            });

        if ($existing > 0) {
            $this->command->warn("  - 기존 마일리지 거래 {$existing}건 및 주문 사용 흔적을 정리했습니다.");
        }
    }

    /**
     * 주문을 가진 회원(관리자 제외)을 주문과 함께 로드합니다.
     *
     * @return Collection<int, User> 회원 컬렉션 (orders 관계 주입)
     */
    private function loadMembersWithOrders(): Collection
    {
        $userIds = Order::query()
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->all();

        if (empty($userIds)) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->whereDoesntHave('roles', fn ($q) => $q->where('identifier', 'admin'))
            ->get();
    }

    /**
     * 관리자 부여 거래에 사용할 관리자 목록을 조회합니다.
     *
     * @return Collection<int, User> 관리자 컬렉션
     */
    private function resolveAdmins(): Collection
    {
        return User::whereHas('roles', fn ($q) => $q->where('identifier', 'admin'))->get();
    }

    /**
     * 단일 회원의 마일리지를 실주문 기반으로, 사건 시각순으로 생성합니다.
     *
     * @param  User  $member  회원
     * @param  Collection<int, User>  $admins  관리자 컬렉션
     * @return void 반환값 없음
     */
    private function buildMemberMileage(User $member, Collection $admins): void
    {
        $orders = Order::query()
            ->where('user_id', $member->id)
            ->with(['options'])
            ->orderBy('ordered_at')
            ->get();

        $events = $this->planEvents($orders, $admins);

        usort($events, fn (array $a, array $b) => [$a['at']->getTimestamp(), self::EVENT_ORDER[$a['kind']]]
            <=> [$b['at']->getTimestamp(), self::EVENT_ORDER[$b['kind']]]);

        // 통화별 활성 lot 누적기: ['KRW' => MileageTransaction[]] (시각순으로만 쌓인다)
        $lots = [];
        $usedByOrder = [];

        foreach ($events as $event) {
            match ($event['kind']) {
                'guarantee' => $this->guaranteeBalance($member, $event['order'], $event['at'], $admins, $lots),
                'admin_earn' => $this->adminEarn($member, $admins, $event['at'], $lots),
                'use' => $usedByOrder[$event['order']->id] = $this->useForOrder($member, $event['order'], $event['at'], $lots),
                'restore' => $this->restoreForCancelledOrder($member, $event['order'], (int) ($usedByOrder[$event['order']->id] ?? 0), $event['at'], $lots),
                'earn' => $this->earnForOrder($member, $event['order'], $event['at'], $lots),
                'admin_deduct' => $this->adminDeduct($member, $admins, $event['at'], $lots),
            };
        }

        // 적립 시점이 아직 오지 않은 주문 수 (화면의 「적립 예정」)
        $this->stats['pending'] += $orders->filter(fn (Order $o) => $this->isEarnEligible($o) && $this->earnAt($o) === null)->count();

        // 소멸 — 보유 lot 중 만료 예정일이 지난 것만 (현재 시점 기준이라 마지막에 처리)
        if (rand(1, 100) <= self::EXPIRE_PERCENTAGE) {
            $this->expireOldestLot($member, $lots);
        }
    }

    /**
     * 회원의 사건 목록을 만듭니다. 사용 여부는 여기서 정하고, 사용액은 처리 시점의 잔액으로 정한다.
     *
     * @param  Collection<int, Order>  $orders  회원 주문 (시각순)
     * @param  Collection<int, User>  $admins  관리자 컬렉션
     * @return array<int, array{at: Carbon, kind: string, order?: Order}> 사건 목록
     */
    private function planEvents(Collection $orders, Collection $admins): array
    {
        $events = [];
        $now = now();
        $guaranteed = false;

        foreach ($orders as $order) {
            $orderedAt = $order->ordered_at instanceof Carbon ? $order->ordered_at->copy() : $now->copy();
            $cancelledAfterPayment = $order->order_status === OrderStatusEnum::CANCELLED && $order->paid_at !== null;

            if ($this->isUsableOrder($order)) {
                $chance = $cancelledAfterPayment ? self::CANCELLED_ORDER_USAGE_PERCENTAGE : self::USAGE_ORDER_PERCENTAGE;
                if (rand(1, 100) <= $chance) {
                    // 「마일리지 쓴 주문이 취소됨 → 복원」 시나리오에 쓸 잔액을 첫 취소 주문 앞에 보장한다
                    if ($cancelledAfterPayment && ! $guaranteed && (int) $order->total_amount > 0) {
                        $events[] = ['at' => $orderedAt->copy()->subDays(rand(5, 15)), 'kind' => 'guarantee', 'order' => $order];
                        $guaranteed = true;
                    }

                    $events[] = ['at' => $orderedAt, 'kind' => 'use', 'order' => $order];

                    if ($cancelledAfterPayment) {
                        $cancelledAt = $order->cancelled_at instanceof Carbon ? $order->cancelled_at->copy() : $orderedAt->copy()->addDays(rand(1, 3));
                        $events[] = ['at' => $cancelledAt->min($now), 'kind' => 'restore', 'order' => $order];
                    }
                }
            }

            $earnAt = $this->earnAt($order);
            if ($earnAt !== null) {
                $events[] = ['at' => $earnAt, 'kind' => 'earn', 'order' => $order];
            }
        }

        if (rand(1, 100) <= self::ADMIN_EARN_PERCENTAGE) {
            $grantedAt = $now->copy()->subDays(rand(5, 60));
            $events[] = ['at' => $grantedAt, 'kind' => 'admin_earn'];

            if (rand(1, 100) <= self::ADMIN_DEDUCT_PERCENTAGE) {
                $events[] = ['at' => $grantedAt->copy()->addDays(rand(1, 4))->min($now), 'kind' => 'admin_deduct'];
            }
        }

        return $events;
    }

    /**
     * 주문이 마일리지 사용 가능 상태인지 판정합니다 — 결제가 일어난 주문(결제 후 취소 포함).
     *
     * @param  Order  $order  주문
     * @return bool 사용 가능 여부
     */
    private function isUsableOrder(Order $order): bool
    {
        if ($order->order_status === OrderStatusEnum::CANCELLED) {
            return $order->paid_at !== null;
        }

        return $order->order_status->isPastPayment();
    }

    /**
     * 설정의 적립 시점에 도달할 수 있는 상태인지 판정합니다 (MileageEarnTriggerEnum::eligibleStatuses).
     *
     * @param  Order  $order  주문
     * @return bool 적립 대상 상태면 true
     */
    private function isEarnEligible(Order $order): bool
    {
        return in_array($order->order_status->value, $this->earnTrigger->eligibleStatuses(), true);
    }

    /**
     * 주문의 적립 시각을 돌려줍니다 — 적립 시점 컬럼(구매확정/배송완료 시각) + 지연일.
     * 적립 대상 상태가 아니거나 그 시각이 아직 오지 않았으면 null.
     *
     * @param  Order  $order  주문
     * @return Carbon|null 적립 시각
     */
    private function earnAt(Order $order): ?Carbon
    {
        if (! $this->isEarnEligible($order)) {
            return null;
        }

        $column = $this->earnTrigger->timestampColumn();
        $times = $order->options->pluck($column)->filter();
        $at = $times->isNotEmpty() ? $times->max() : ($order->{$column} ?? null);

        // 시각이 비어 있는 옛 주문(시각 사슬 이전 데이터)은 주문 후 며칠로 대신한다
        if (! $at instanceof Carbon) {
            $base = $order->ordered_at instanceof Carbon ? $order->ordered_at->copy() : now()->subDays(15);
            $at = $base->addDays(rand(3, 8))->min(now());
        }

        $at = $at->copy()->addDays($this->earnDelayDays);

        return $at->greaterThan(now()) ? null : $at;
    }

    /**
     * 결제 후 취소 주문에 쓸 잔액이 부족하면 관리자 적립금(admin_earn)을 보장합니다.
     *
     * 실제 출처 주문이 없는 잔액의 정직한 표현은 관리자 지급이며(구매 적립은 항상 실주문에 연결),
     * 이로써 「마일리지 쓴 주문이 취소됨 → 복원」 시나리오가 화면에 노출된다.
     *
     * @param  User  $member  회원
     * @param  Order  $order  보장 대상 취소 주문
     * @param  Carbon  $at  지급 시각
     * @param  Collection<int, User>  $admins  관리자 컬렉션
     * @param  array  $lots  통화별 활성 lot 누적기 (참조)
     * @return void 반환값 없음
     */
    private function guaranteeBalance(User $member, Order $order, Carbon $at, Collection $admins, array &$lots): void
    {
        $currency = $order->currency ?: $this->baseCurrency();
        $needed = max($this->minUseAmount, (int) ($order->total_amount * 0.3));
        $available = $this->availableBalance($lots, $currency);
        if ($available >= $needed) {
            return;
        }

        $grant = (float) max($needed - (int) $available, $this->minUseAmount * 2);
        $admin = $admins->isNotEmpty() ? $admins->random() : null;

        $this->insertTransaction($member, $currency, MileageTransactionTypeEnum::ADMIN_EARN, $grant, $at, [
            'remaining_amount' => $grant,
            'expires_at' => $at->copy()->addDays($this->expiryDays),
            'granted_by' => $admin?->id,
            'memo' => '사전 적립금 지급',
            'description_key' => 'mileage_admin_earn',
            'description_amount' => $grant,
        ], $lots);

        $this->stats['admin_earn']++;
    }

    /**
     * 주문 옵션의 적립 예정액에서 적립 거래를 생성합니다 (적립 대상 상태의 옵션만).
     *
     * @param  User  $member  회원
     * @param  Order  $order  주문
     * @param  Carbon  $earnedAt  적립 시각
     * @param  array  $lots  통화별 활성 lot 누적기 (참조)
     * @return void 반환값 없음
     */
    private function earnForOrder(User $member, Order $order, Carbon $earnedAt, array &$lots): void
    {
        $currency = $order->currency ?: $this->baseCurrency();
        $eligible = $this->earnTrigger->eligibleStatuses();

        foreach ($order->options()->get() as $option) {
            $status = $option->option_status instanceof OrderStatusEnum ? $option->option_status->value : (string) $option->option_status;
            $amount = round((float) $option->subtotal_earned_points_amount, 2);
            if ($amount <= 0 || ! in_array($status, $eligible, true)) {
                continue;
            }

            $this->insertTransaction($member, $currency, MileageTransactionTypeEnum::PURCHASE_EARN, $amount, $earnedAt, [
                'remaining_amount' => $amount,
                'expires_at' => $earnedAt->copy()->addDays($this->expiryDays),
                'order_id' => $order->id,
                'order_option_id' => $option->id,
                'description_key' => 'mileage_earn',
                'description_amount' => $amount,
            ], $lots);

            $this->stats['earn']++;
        }
    }

    /**
     * 주문에 실제 마일리지 사용을 발생시킵니다 (주문 컬럼 갱신 + FIFO 차감).
     *
     * @param  User  $member  회원
     * @param  Order  $order  주문
     * @param  Carbon  $usedAt  사용 시각 (주문 시각)
     * @param  array  $lots  통화별 활성 lot 누적기 (참조)
     * @return int 실제 사용한 마일리지 (0 = 사용 안 함)
     */
    private function useForOrder(User $member, Order $order, Carbon $usedAt, array &$lots): int
    {
        $currency = $order->currency ?: $this->baseCurrency();
        $available = $this->availableBalance($lots, $currency, $usedAt);
        if ($available < $this->minUseAmount) {
            return 0;
        }

        // 결제금액 캡 + 최대 사용 한도 (UserMileageService::resolveMaxUseLimit 과 같은 규칙)
        $payment = (int) $order->total_amount;
        if ($payment <= 0) {
            return 0;
        }

        $raw = (int) ($available * (rand(30, 80) / 100));
        $use = intdiv(min($raw, $payment, $this->maxUseLimit($payment)), $this->useUnit) * $this->useUnit;
        if ($use < $this->minUseAmount) {
            return 0;
        }

        $this->consumeFifo($member, $currency, $use, MileageTransactionTypeEnum::ORDER_USE, $usedAt, [
            'order_id' => $order->id,
            'description_key' => 'mileage_use',
        ], $lots);

        $this->writeUsageToOrder($order, $use);

        $this->stats['use']++;

        return $use;
    }

    /**
     * 설정의 최대 사용 한도를 계산합니다 (percent / fixed).
     *
     * @param  int  $paymentAmount  결제 금액
     * @return int 최대 사용 한도
     */
    private function maxUseLimit(int $paymentAmount): int
    {
        if (($this->currencyRule['max_use_type'] ?? 'percent') === 'percent') {
            return (int) floor($paymentAmount * (float) ($this->currencyRule['max_use_percent'] ?? 100) / 100);
        }

        $value = (int) ($this->currencyRule['max_use_value'] ?? 0);

        return $value > 0 ? $value : PHP_INT_MAX;
    }

    /**
     * 주문/주문옵션에 마일리지 사용액을 기록합니다 (옵션별 결제금액 비례 안분).
     *
     * @param  Order  $order  주문
     * @param  int  $usedAmount  총 사용액
     * @return void 반환값 없음
     */
    private function writeUsageToOrder(Order $order, int $usedAmount): void
    {
        $options = $order->options()->get();
        $base = (float) $options->sum('subtotal_paid_amount');
        $count = $options->count();
        $remaining = $usedAmount;
        $shares = [];

        foreach ($options->values() as $index => $option) {
            if ($index === $count - 1) {
                $share = $remaining; // 마지막 옵션에 잔여 배분 (반올림 오차 흡수)
            } else {
                $ratio = $base > 0 ? (float) $option->subtotal_paid_amount / $base : (1 / max($count, 1));
                $share = (int) floor($usedAmount * $ratio);
            }
            $remaining -= $share;
            $shares[$option->id] = $share;
        }

        $this->applyPointsToOrder($order, $shares, 1);
    }

    /**
     * 마일리지 사용액을 주문 문서에 반영하거나(+1) 되돌립니다(−1).
     *
     * 사용액만큼 현금 금액이 준다: 옵션 결제금액·최종금액·과세액·적립 예정액, 주문 결제금액(입금 전이면 받을 돈)·
     * 총액·적립 예정 합, 결제 행 금액, 결제 후 취소면 환불신청서 현금/마일리지 환불액과 주문 환불 누계.
     *
     * @param  Order  $order  주문
     * @param  array<int, int>  $shares  옵션 id => 사용액
     * @param  int  $sign  +1 사용, −1 되돌림
     * @return void 반환값 없음
     */
    private function applyPointsToOrder(Order $order, array $shares, int $sign): void
    {
        $order->refresh();
        $total = array_sum($shares);
        if ($total <= 0) {
            return;
        }

        $currency = $order->currency ?: $this->baseCurrency();
        $isCancelled = $order->order_status === OrderStatusEnum::CANCELLED;
        $isPaid = $order->paid_at !== null || $order->order_status->isPastPayment();
        $delta = $sign * $total;

        foreach ($order->options()->get() as $option) {
            $share = (int) ($shares[$option->id] ?? 0);
            $paid = max(0.0, (float) $option->subtotal_paid_amount - $sign * $share);
            $option->update([
                'subtotal_points_used_amount' => $sign > 0 ? $share : 0,
                'mc_subtotal_points_used_amount' => $sign > 0 ? $this->multiCurrency($share) : null,
                'subtotal_paid_amount' => $paid,
                'mc_final_amount' => $this->multiCurrency($paid),
                'subtotal_tax_amount' => round($paid / 11, 2),
                'mc_subtotal_tax_amount' => $this->multiCurrency(round($paid / 11, 2)),
                'subtotal_earned_points_amount' => $isCancelled ? 0 : $this->earnPointsFor($paid),
            ]);
        }

        $totalAmount = max(0.0, (float) $order->total_amount - $delta);
        $orderUpdates = [
            'total_points_used_amount' => $sign > 0 ? $total : 0,
            'mc_total_points_used_amount' => $sign > 0 ? $this->multiCurrency($total) : null,
            'is_mileage_deducted' => $sign > 0,
            'total_amount' => $totalAmount,
            'mc_total_amount' => $this->multiCurrency($totalAmount),
            'total_tax_amount' => round($totalAmount / 11, 2),
            'total_earned_points_amount' => (float) $order->options()->sum('subtotal_earned_points_amount'),
        ];

        if ($isPaid) {
            $paidAmount = max(0.0, (float) $order->total_paid_amount - $delta);
            $orderUpdates['total_paid_amount'] = $paidAmount;
            $orderUpdates['mc_total_paid_amount'] = $this->multiCurrency($paidAmount);
        } elseif (! $isCancelled) {
            $orderUpdates['total_due_amount'] = max(0.0, (float) $order->total_due_amount - $delta);
        }

        if ($isCancelled && $order->paid_at !== null) {
            $orderUpdates['total_refunded_amount'] = max(0.0, (float) $order->total_refunded_amount - $delta);
            $orderUpdates['total_refunded_points_amount'] = $sign > 0 ? $total : 0;
        }

        $order->update($orderUpdates);

        $payment = OrderPayment::query()->where('order_id', $order->id)->first();
        if ($payment) {
            $local = $isPaid ? max(0.0, (float) $payment->paid_amount_local - $delta) : (float) $payment->paid_amount_local;
            $paymentUpdates = [
                'paid_amount_local' => $local,
                'paid_amount_base' => max(0.0, (float) $payment->paid_amount_base - $delta),
                'mc_paid_amount' => $this->multiCurrency($local),
            ];
            if ($isCancelled && $order->paid_at !== null) {
                $paymentUpdates['cancelled_amount'] = max(0.0, (float) $payment->cancelled_amount - $delta);
                $paymentUpdates['mc_cancelled_amount'] = $this->multiCurrency($paymentUpdates['cancelled_amount']);
            }
            $payment->update($paymentUpdates);
        }

        if ($isCancelled) {
            $refund = OrderRefund::query()->where('order_id', $order->id)->first();
            if ($refund) {
                $refundAmount = max(0.0, (float) $refund->refund_amount - $delta);
                $refund->update([
                    'refund_amount' => $refundAmount,
                    'refund_points_amount' => $sign > 0 ? $total : 0,
                    'mc_refund_amount' => $this->multiCurrency($refundAmount),
                    'mc_refund_points_amount' => $this->multiCurrency($sign > 0 ? $total : 0),
                ]);

                foreach (OrderRefundOption::query()->where('order_refund_id', $refund->id)->get() as $row) {
                    $share = (int) ($shares[$row->order_option_id] ?? 0);
                    $row->update(['refund_amount' => max(0.0, (float) $row->refund_amount - $sign * $share)]);
                }
            }
        }
    }

    /**
     * 결제된 취소 주문의 마일리지 사용분을 취소 시각에 전액 복원합니다 (기존 취소신청서에 연결).
     *
     * @param  User  $member  회원
     * @param  Order  $order  취소 주문
     * @param  int  $usedAmount  복원 대상 사용액
     * @param  Carbon  $restoredAt  복원 시각 (취소 시각)
     * @param  array  $lots  통화별 활성 lot 누적기 (참조)
     * @return void 반환값 없음
     */
    private function restoreForCancelledOrder(User $member, Order $order, int $usedAmount, Carbon $restoredAt, array &$lots): void
    {
        if ($usedAmount <= 0) {
            return;
        }

        $currency = $order->currency ?: $this->baseCurrency();
        $cancelId = $this->resolveCancelId($member, $order, $restoredAt);

        $this->insertTransaction($member, $currency, MileageTransactionTypeEnum::ORDER_CANCEL_RESTORE, (float) $usedAmount, $restoredAt, [
            'remaining_amount' => (float) $usedAmount,
            'expires_at' => $restoredAt->copy()->addDays($this->expiryDays),
            'order_id' => $order->id,
            'order_cancel_id' => $cancelId,
            'description_key' => 'mileage_restore',
            'description_amount' => $usedAmount,
        ], $lots);

        // 운영(restoreForCancel)과 동일 모양: 원 사용 거래에 같은 cancel_id 역주입 (연결 거래 정합)
        app(MileageTransactionRepositoryInterface::class)->linkUsageToCancel($order->id, $cancelId);

        $this->stats['cancel_restore']++;
    }

    /**
     * 취소 주문의 취소신청서 id 를 돌려줍니다.
     *
     * 정상 경로에서는 OrderSeeder 가 만든 취소신청서를 쓴다. 이 시더만 따로 돌린 옛 데이터처럼
     * 취소신청서가 없는 취소 주문에 한해, 실제 전체취소와 같은 모양으로 한 건 만든다.
     *
     * @param  User  $member  회원
     * @param  Order  $order  취소 주문
     * @param  Carbon  $cancelledAt  취소 시각
     * @return int 취소신청서 id
     */
    private function resolveCancelId(User $member, Order $order, Carbon $cancelledAt): int
    {
        $existing = OrderCancel::query()->where('order_id', $order->id)->orderBy('id')->value('id');
        if ($existing) {
            return (int) $existing;
        }

        $order->loadMissing(['options', 'shippingAddress']);
        $address = $order->shippingAddress;

        $cancel = OrderCancel::unguarded(fn () => OrderCancel::create([
            'order_id' => $order->id,
            'cancel_number' => app(SequenceService::class)->generateCode(SequenceType::CANCEL),
            'cancel_type' => CancelTypeEnum::FULL,
            'cancel_status' => CancelStatusEnum::COMPLETED,
            'cancel_reason_type' => 'changed_mind',
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
            'cancelled_by' => $member->id,
            'cancelled_at' => $cancelledAt,
            'created_at' => $cancelledAt,
            'updated_at' => $cancelledAt,
        ]));

        return (int) $cancel->id;
    }

    /**
     * 관리자 수동 지급 거래를 생성합니다.
     *
     * @param  User  $member  회원
     * @param  Collection<int, User>  $admins  관리자 컬렉션
     * @param  Carbon  $grantedAt  지급 시각
     * @param  array  $lots  통화별 활성 lot 누적기 (참조)
     * @return void 반환값 없음
     */
    private function adminEarn(User $member, Collection $admins, Carbon $grantedAt, array &$lots): void
    {
        $admin = $admins->isNotEmpty() ? $admins->random() : null;
        $amount = (float) (rand(10, 50) * 100); // 1000 ~ 5000

        $this->insertTransaction($member, $this->baseCurrency(), MileageTransactionTypeEnum::ADMIN_EARN, $amount, $grantedAt, [
            'remaining_amount' => $amount,
            'expires_at' => $grantedAt->copy()->addDays($this->expiryDays),
            'granted_by' => $admin?->id,
            'memo' => $this->randomAdminEarnMemo(),
            'description_key' => 'mileage_admin_earn',
            'description_amount' => $amount,
        ], $lots);

        $this->stats['admin_earn']++;
    }

    /**
     * 관리자 수동 차감 거래를 생성합니다 (보유 lot FIFO 소비).
     *
     * @param  User  $member  회원
     * @param  Collection<int, User>  $admins  관리자 컬렉션
     * @param  Carbon  $deductedAt  차감 시각
     * @param  array  $lots  통화별 활성 lot 누적기 (참조)
     * @return void 반환값 없음
     */
    private function adminDeduct(User $member, Collection $admins, Carbon $deductedAt, array &$lots): void
    {
        $available = $this->availableBalance($lots, $this->baseCurrency(), $deductedAt);
        if ($available < 100) {
            return;
        }

        $admin = $admins->isNotEmpty() ? $admins->random() : null;
        $deduct = (int) min((int) ($available * 0.3), 3000);
        $deduct = max(100, $deduct);

        $this->consumeFifo($member, $this->baseCurrency(), $deduct, MileageTransactionTypeEnum::ADMIN_DEDUCT, $deductedAt, [
            'granted_by' => $admin?->id,
            'memo' => '오지급 회수',
            'description_key' => 'mileage_admin_deduct',
        ], $lots);

        $this->stats['admin_deduct']++;
    }

    /**
     * 회원의 가장 오래된 활성 lot 을 만료일 경과 시 소멸 처리합니다.
     *
     * 인과 보장: 소멸 시점은 항상 적립 시점(lot.created_at) 이후이며, 운영
     * 소멸 로직(expireLots)과 동일하게 "만료 예정일(적립일 + 유효기간)이 이미
     * 지난 lot"만 소멸시킨다. 만료 예정일이 아직 미래인 lot 은 소멸 대상이 아니므로
     * 건너뛴다 — 이로써 "미래 적립 ← 과거 소멸" 시간 역전을 원천 차단한다.
     *
     * @param  User  $member  회원
     * @param  array  $lots  통화별 활성 lot 누적기 (참조)
     * @return void 반환값 없음
     */
    private function expireOldestLot(User $member, array &$lots): void
    {
        $currency = $this->baseCurrency();
        $active = array_values(array_filter($lots[$currency] ?? [], fn ($l) => (float) $l->remaining_amount > 0 && $l->expired_at === null));
        if (empty($active)) {
            return;
        }

        $oldest = $active[0];
        $now = now();

        // 적립 시점 기준 만료 예정일 (적립일 + 유효기간). 아직 미래면 소멸시키지 않는다.
        $earnedAt = $oldest->created_at instanceof Carbon ? $oldest->created_at->copy() : $now->copy()->subDays($this->expiryDays + 1);
        $dueAt = $earnedAt->copy()->addDays($this->expiryDays);
        if ($dueAt->greaterThanOrEqualTo($now)) {
            return; // 만료 예정일이 아직 도래하지 않음 — 소멸 대상 아님
        }

        $remaining = (float) $oldest->remaining_amount;
        // 소멸 시점: 만료 예정일 직후(1~3일), 단 현재 시각을 넘지 않도록 보정
        $expiredAt = $dueAt->copy()->addDays(rand(1, 3));
        if ($expiredAt->greaterThan($now)) {
            $expiredAt = $now->copy();
        }

        // lot 을 만료 + 소멸 처리 (expires_at = 만료 예정일, expired_at = 소멸 처리 시각)
        $oldest->expires_at = $dueAt;
        $oldest->expired_at = $expiredAt;
        $oldest->remaining_amount = 0;
        $oldest->save();

        $this->insertTransaction($member, $currency, MileageTransactionTypeEnum::EXPIRED, -$remaining, $expiredAt, [
            'remaining_amount' => 0,
            'source_transaction_id' => $oldest->id,
            'expired_at' => $expiredAt,
            'description_key' => 'mileage_expire',
            'description_amount' => $remaining,
        ], $lots);

        $this->stats['expired']++;
    }

    /**
     * FIFO 차감을 수행하고 차감 거래를 생성합니다.
     *
     * 차감 시각 이전에 생긴 lot 만 소비한다 — 사건을 시각순으로 처리하므로 원래도 그렇지만,
     * 같은 lot 목록을 다른 경로가 건드려도 미래 적립을 소비하지 않게 한 번 더 막는다.
     *
     * @param  User  $member  회원
     * @param  string  $currency  통화 코드
     * @param  int  $amount  차감액 (양수)
     * @param  MileageTransactionTypeEnum  $type  차감 유형
     * @param  Carbon  $createdAt  거래 시각
     * @param  array  $extra  추가 필드 (order_id/granted_by/memo/description_key)
     * @param  array  $lots  통화별 활성 lot 누적기 (참조)
     * @return void 반환값 없음
     */
    private function consumeFifo(User $member, string $currency, int $amount, MileageTransactionTypeEnum $type, Carbon $createdAt, array $extra, array &$lots): void
    {
        $remaining = $amount;
        $firstSource = null;

        foreach (($lots[$currency] ?? []) as $lot) {
            if ($remaining <= 0) {
                break;
            }
            $lotRemaining = (float) $lot->remaining_amount;
            if ($lotRemaining <= 0 || $lot->expired_at !== null || $lot->created_at->greaterThan($createdAt)) {
                continue;
            }
            $take = min($lotRemaining, (float) $remaining);
            $lot->remaining_amount = $lotRemaining - $take;
            $lot->save();
            $remaining -= $take;
            $firstSource ??= $lot->id;
        }

        $this->insertTransaction($member, $currency, $type, -$amount, $createdAt, array_merge([
            'remaining_amount' => 0,
            'source_transaction_id' => $firstSource,
            'description_amount' => $amount,
        ], $extra), $lots);
    }

    /**
     * 원장 거래를 생성하고 balance_after 스냅샷/활성 lot 목록을 갱신합니다.
     *
     * balance_after 는 이 거래가 반영된 뒤의 잔액이다(UserMileageService 와 같음). 차감·소멸은 lot 을 먼저
     * 줄인 뒤 오므로 현재 잔액이 곧 거래 후 잔액이고, 적립은 아직 목록에 없는 새 lot 몫을 더한다.
     *
     * @param  User  $member  회원
     * @param  string  $currency  통화 코드
     * @param  MileageTransactionTypeEnum  $type  거래 유형
     * @param  float  $signedAmount  부호 있는 금액 (적립 +, 차감 −)
     * @param  Carbon  $createdAt  거래 시각
     * @param  array  $fields  거래 필드
     * @param  array  $lots  통화별 활성 lot 누적기 (참조)
     * @return MileageTransaction 생성된 거래
     */
    private function insertTransaction(User $member, string $currency, MileageTransactionTypeEnum $type, float $signedAmount, Carbon $createdAt, array $fields, array &$lots): MileageTransaction
    {
        $balanceAfter = $this->availableBalance($lots, $currency) + ($type->isEarning() ? $signedAmount : 0);

        $descriptionAmount = (float) ($fields['description_amount'] ?? abs($signedAmount));
        $descriptionKey = $fields['description_key'] ?? 'mileage_earn';

        $transaction = MileageTransaction::create([
            'user_id' => $member->id,
            'currency' => $currency,
            'type' => $type,
            'amount' => round($signedAmount, 2),
            'remaining_amount' => round((float) ($fields['remaining_amount'] ?? 0), 2),
            'balance_after' => round($balanceAfter, 2),
            'order_id' => $fields['order_id'] ?? null,
            'order_option_id' => $fields['order_option_id'] ?? null,
            'order_cancel_id' => $fields['order_cancel_id'] ?? null,
            'source_transaction_id' => $fields['source_transaction_id'] ?? null,
            'granted_by' => $fields['granted_by'] ?? null,
            // 금액은 통화 표기 규칙으로 (ecommerce_format_price — 원 하드코딩 금지)
            'description' => __("sirsoft-ecommerce::activity_log.description.{$descriptionKey}", ['amount' => ecommerce_format_price($descriptionAmount, $currency)]),
            'memo' => $fields['memo'] ?? null,
            'expires_at' => $fields['expires_at'] ?? null,
            'expired_at' => $fields['expired_at'] ?? null,
        ]);

        // created_at 을 시간순 시나리오에 맞게 보정
        $transaction->created_at = $createdAt;
        $transaction->updated_at = $createdAt;
        $transaction->save();

        if ($type->isEarning()) {
            $lots[$currency][] = $transaction;
        }

        $this->touchedCurrencies[$member->id][$currency] = true;

        return $transaction;
    }

    /**
     * 현재 통화의 활성 lot 잔여 합을 반환합니다 (상태 기준, 시각을 주면 그 시각 이전 lot 만).
     *
     * @param  array  $lots  통화별 활성 lot 누적기
     * @param  string  $currency  통화 코드
     * @param  Carbon|null  $at  기준 시각
     * @return float 사용 가능 잔액
     */
    private function availableBalance(array $lots, string $currency, ?Carbon $at = null): float
    {
        $sum = 0.0;
        foreach (($lots[$currency] ?? []) as $lot) {
            if ($lot->expired_at === null && (float) $lot->remaining_amount > 0
                && ($at === null || ! $lot->created_at->greaterThan($at))) {
                $sum += (float) $lot->remaining_amount;
            }
        }

        return round($sum, 2);
    }

    /**
     * 결제 금액에서 적립 예정액을 계산합니다 (설정 적립률·절사 기준).
     *
     * @param  float  $earnableAmount  적립 대상 금액
     * @return int 적립 예정액
     */
    private function earnPointsFor(float $earnableAmount): int
    {
        return MileageRounding::apply(max(0.0, $earnableAmount) * $this->earnRate / 100, MileageRounding::normalize($this->currencyRule ?: null));
    }

    /**
     * 기본 통화 금액을 등록 통화 전체 금액 맵으로 바꿉니다 (OrderSeeder 와 같은 형식).
     *
     * @param  float|int  $amount  기본 통화 금액
     * @return array<string, float|int> 통화코드 => 금액
     */
    private function multiCurrency(float|int $amount): array
    {
        $result = [];
        foreach (app(CurrencyConversionService::class)->convertToMultiCurrency((int) $amount) as $code => $data) {
            $result[$code] = $data['price'];
        }

        return $result ?: [$this->baseCurrency() => $amount];
    }

    /**
     * 관리자 수동 지급 메모를 랜덤 반환합니다.
     *
     * @return string 메모
     */
    private function randomAdminEarnMemo(): string
    {
        $memos = [
            '회원 보상 지급',
            '이벤트 참여 보상',
            '고객 응대 보상 지급',
            '리뷰 작성 보상',
            '오류 보상 지급',
        ];

        return $memos[array_rand($memos)];
    }

    /**
     * 회원별 잔액 캐시를 원장에서 재계산합니다 (단방향 파생).
     *
     * @return void 반환값 없음
     */
    private function reconcileBalanceCache(): void
    {
        $cache = app(MileageBalanceRepositoryInterface::class);

        foreach ($this->touchedCurrencies as $userId => $currencies) {
            foreach (array_keys($currencies) as $currency) {
                $cache->recalculateForUser($userId, $currency);
                $cache->recalculatePending($userId, $currency);
            }
        }

        // 소멸 임박 윈도우(expiring_soon/expiring_date) 캐시 채우기
        $settings = app(EcommerceSettingsService::class);
        $daysBefore = max(1, (int) $settings->getSetting('mileage.expiry_notification_days_before', 7));
        $cache->recalculateExpiringWindow($daysBefore);
    }

    /**
     * 생성 통계를 출력합니다.
     *
     * @return void 반환값 없음
     */
    private function reportStats(): void
    {
        $txCount = MileageTransaction::count();
        $balanceCount = MileageBalance::count();

        $this->command->line("  - 마일리지 보유 회원: {$this->stats['members']}명");
        $this->command->line("  - 적립(구매): {$this->stats['earn']}건 / 적립 시점 전(적립 예정) 주문: {$this->stats['pending']}건 / 사용: {$this->stats['use']}건 / 취소복원: {$this->stats['cancel_restore']}건");
        $this->command->line("  - 관리자 지급: {$this->stats['admin_earn']}건 / 관리자 차감: {$this->stats['admin_deduct']}건 / 소멸: {$this->stats['expired']}건");
        $this->command->info("마일리지 거래 {$txCount}건, 잔액 캐시 {$balanceCount}건이 성공적으로 생성되었습니다.");

        if (! $this->mileageEnabled) {
            $this->command->warn('  ※ 마일리지가 꺼져 있어 화면에 안 보일 수 있습니다. 시연하려면 이커머스 설정 > 마일리지에서 사용을 켜세요.');
        }
    }
}
