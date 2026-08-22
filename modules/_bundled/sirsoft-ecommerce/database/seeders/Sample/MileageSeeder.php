<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use App\Models\User;
use App\Traits\HasSeederCounts;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\MileageBalance;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageBalanceRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageTransactionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\SequenceService;

/**
 * 마일리지 거래 더미 데이터 시더 (실주문 기반 — 합성 거래 없음)
 *
 * 실제 운영 흐름(UserMileageService)을 그대로 재현한다. 마일리지는 임의로 찍지 않고
 * OrderSeeder 가 만든 실제 주문에서 발생시킨다:
 *
 *  1. 적립(purchase_earn): 구매확정/배송완료 주문의 옵션 적립액(subtotal_earned_points_amount)에서
 *     거래 생성 — order_id + order_option_id 실연결.
 *  2. 사용(order_use): 회원의 결제완료 이상 주문 일부에 실제 마일리지 사용을 발생시킨다.
 *     주문옵션 subtotal_points_used_amount + 주문 total_points_used_amount(다중통화 포함) 갱신 +
 *     order_id 연결 + FIFO 차감(source_transaction_id). 사용은 보유 잔액 한도 내에서만.
 *  3. 취소 복원(order_cancel_restore): 마일리지를 쓴 주문이 취소된 경우, 실제 order_cancel 레코드를
 *     생성하고 그 cancel_id 로 복원 거래를 연결 — order_cancel_id 멱등/연결거래 정합.
 *  4. 관리자 지급/차감(admin_earn/admin_deduct), 소멸(expired): 주문과 무관한 실제 경로 그대로.
 *     지급은 granted_by+memo, 차감/소멸은 보유 lot 을 FIFO 로 실제 소비.
 *
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
     * 회원의 결제완료 이상 주문 중 마일리지를 사용할 비율 (%)
     */
    private const USAGE_ORDER_PERCENTAGE = 35;

    /**
     * 취소 주문 중 마일리지를 사용했던 비율 (%) — 취소 복원 시나리오 노출용
     */
    private const CANCELLED_ORDER_USAGE_PERCENTAGE = 70;

    /**
     * 마일리지를 사용한 주문이 취소될 때 복원 처리할 비율 (%)
     */
    private const CANCEL_RESTORE_PERCENTAGE = 60;

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
        'use' => 0,
        'cancel_restore' => 0,
        'admin_earn' => 0,
        'admin_deduct' => 0,
        'expired' => 0,
    ];

    /**
     * 시더 실행
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
     * 환경설정에서 유효기간/사용단위/최소사용액을 읽어옵니다.
     */
    private function resolveSettings(): void
    {
        $settings = app(EcommerceSettingsService::class);
        $this->expiryDays = max(1, (int) $settings->getSetting('mileage.expiry_days', 365));

        $rules = (array) $settings->getSetting('mileage.currency_rules', []);
        $krw = collect($rules)->firstWhere('currency_code', $this->baseCurrency()) ?? ($rules[0] ?? []);
        $this->useUnit = max(1, (int) ($krw['use_unit'] ?? 10));
        $this->minUseAmount = max(0, (int) ($krw['min_use_amount'] ?? 1000));
    }

    /**
     * 기존 마일리지 데이터 및 주문 사용/취소 흔적을 정리합니다 (재실행 멱등).
     */
    private function deleteExistingTransactions(): void
    {
        $existing = MileageTransaction::count();

        MileageTransaction::query()->delete();
        MileageBalance::query()->delete();

        // 시더가 주문에 기록했던 마일리지 사용 흔적 원복 (멱등) + 생성했던 취소 레코드 제거
        DB::table('ecommerce_order_cancels')->delete();
        OrderOption::query()->where('subtotal_points_used_amount', '>', 0)->update([
            'subtotal_points_used_amount' => 0,
            'mc_subtotal_points_used_amount' => null,
        ]);
        Order::query()->where('total_points_used_amount', '>', 0)->update([
            'total_points_used_amount' => 0,
            'mc_total_points_used_amount' => null,
        ]);

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
     * 단일 회원의 마일리지를 실주문 기반으로 생성합니다.
     *
     * 시간순서: 적립(과거 구매확정/배송완료) → 사용(이후 주문) → 취소복원 → 관리자/소멸.
     *
     * @param  User  $member  회원
     * @param  Collection<int, User>  $admins  관리자 컬렉션
     */
    private function buildMemberMileage(User $member, Collection $admins): void
    {
        $orders = Order::query()
            ->where('user_id', $member->id)
            ->with(['options'])
            ->orderBy('ordered_at')
            ->get();

        // 통화별 활성 lot 누적기: ['KRW' => MileageTransaction[]]
        $lots = [];

        // 1) 적립 — 구매확정/배송완료 주문의 옵션 적립액
        foreach ($orders as $order) {
            if (! in_array($order->order_status, [OrderStatusEnum::DELIVERED, OrderStatusEnum::CONFIRMED], true)) {
                continue;
            }
            $this->earnForOrder($member, $order, $lots);
        }

        // 1-b) 취소 주문 보유 회원에 적립 보장 (취소 복원 시나리오 노출)
        //   취소될 주문에 마일리지를 쓰려면 그 이전에 적립 잔액이 있어야 한다. 적립 주문이 없어
        //   잔액이 부족한 회원에게는, 가장 이른 취소 주문 직전 시점에 관리자 적립금(admin_earn)을
        //   보장한다 — 실제 출처 주문이 없는 잔액의 정직한 표현은 관리자 지급이다.
        $this->guaranteeBalanceForCancelledOrders($member, $orders, $admins, $lots);

        // 2) 사용 — 결제완료 이상(취소/결제전 제외) 주문 일부에 실제 사용 발생
        foreach ($orders as $order) {
            if (! $this->isUsableOrder($order)) {
                continue;
            }
            // 취소 주문은 사용 확률을 높여 "마일리지 쓴 주문이 취소됨 → 복원" 시나리오를 노출
            $usageChance = $order->order_status === OrderStatusEnum::CANCELLED
                ? self::CANCELLED_ORDER_USAGE_PERCENTAGE
                : self::USAGE_ORDER_PERCENTAGE;
            if (rand(1, 100) > $usageChance) {
                continue;
            }
            $used = $this->useForOrder($member, $order, $lots);

            // 3) 취소 복원 — 사용한 주문이 취소 상태면 일부 복원
            if ($used > 0
                && $order->order_status === OrderStatusEnum::CANCELLED
                && rand(1, 100) <= self::CANCEL_RESTORE_PERCENTAGE
            ) {
                $this->restoreForCancelledOrder($member, $order, $used, $lots);
            }
        }

        // 4) 관리자 지급/차감 (주문 무관 실제 경로)
        if (rand(1, 100) <= self::ADMIN_EARN_PERCENTAGE) {
            $this->adminEarn($member, $admins, $lots);

            if (rand(1, 100) <= self::ADMIN_DEDUCT_PERCENTAGE) {
                $this->adminDeduct($member, $admins, $lots);
            }
        }

        // 5) 소멸 — 보유 lot 일부를 과거 만료로 강제 후 소멸 처리
        if (rand(1, 100) <= self::EXPIRE_PERCENTAGE) {
            $this->expireOldestLot($member, $lots);
        }
    }

    /**
     * 주문이 마일리지 사용 가능 상태인지 판정합니다 (결제완료 이상, 결제전/주문대기 제외).
     *
     * @param  Order  $order  주문
     * @return bool 사용 가능 여부
     */
    private function isUsableOrder(Order $order): bool
    {
        return ! in_array($order->order_status, [
            OrderStatusEnum::PENDING_ORDER,
            OrderStatusEnum::PENDING_PAYMENT,
        ], true);
    }

    /**
     * 취소 주문을 가진 회원이 사용할 잔액이 부족하면 관리자 적립금(admin_earn)을 보장합니다.
     *
     * 적립 주문이 없거나 잔액이 취소 주문 사용분에 못 미치는 회원에게, 가장 이른 취소 주문 직전
     * 시점에 관리자 지급 lot 을 발행한다. 실제 출처 주문이 없는 잔액의 정직한 표현은 관리자
     * 지급이며(구매 적립은 항상 실주문에 연결), 이로써 "마일리지 쓴 주문이 취소됨 → 복원"
     * 시나리오가 화면에 노출된다.
     *
     * @param  User  $member  회원
     * @param  Collection<int, Order>  $orders  회원의 주문 (시간순)
     * @param  Collection<int, User>  $admins  관리자 컬렉션
     * @param  array  $lots  통화별 활성 lot 누적기 (참조)
     */
    private function guaranteeBalanceForCancelledOrders(User $member, Collection $orders, Collection $admins, array &$lots): void
    {
        $cancelledOrders = $orders->filter(
            fn (Order $o) => $o->order_status === OrderStatusEnum::CANCELLED && (int) $o->total_amount > 0
        );
        if ($cancelledOrders->isEmpty()) {
            return;
        }

        $earliestCancelled = $cancelledOrders->first();
        $currency = $earliestCancelled->currency ?: $this->baseCurrency();

        // 가장 이른 취소 주문에 사용할 수 있는 최소 잔액 (결제금액의 30% 또는 최소사용액 중 큰 값)
        $needed = max($this->minUseAmount, (int) ($earliestCancelled->total_amount * 0.3));
        $available = $this->availableBalance($lots, $currency);
        if ($available >= $needed) {
            return; // 이미 충분
        }

        // 부족분 + 여유를 관리자 지급으로 보장 (취소 주문 직전 시점)
        $grant = (float) (max($needed - (int) $available, $this->minUseAmount * 2));
        $grantedAt = ($earliestCancelled->ordered_at instanceof Carbon
            ? $earliestCancelled->ordered_at->copy()
            : now())->subDays(rand(5, 15));
        $admin = $admins->isNotEmpty() ? $admins->random() : null;

        $this->insertTransaction($member, $currency, MileageTransactionTypeEnum::ADMIN_EARN, $grant, $grantedAt, [
            'remaining_amount' => $grant,
            'expires_at' => $grantedAt->copy()->addDays($this->expiryDays),
            'granted_by' => $admin?->id,
            'memo' => '사전 적립금 지급',
            'description_key' => 'mileage_admin_earn',
            'description_amount' => $grant,
        ], $lots);

        $this->stats['admin_earn']++;

        // 시간순 보장: lot 목록을 created_at 기준 재정렬 (FIFO 정확성)
        usort($lots[$currency], fn ($a, $b) => $a->created_at <=> $b->created_at);
    }

    /**
     * 구매확정/배송완료 주문의 옵션 적립액에서 적립 거래를 생성합니다.
     *
     * @param  User  $member  회원
     * @param  Order  $order  주문
     * @param  array  $lots  통화별 활성 lot 누적기 (참조)
     */
    private function earnForOrder(User $member, Order $order, array &$lots): void
    {
        $currency = $order->currency ?: $this->baseCurrency();
        // 적립 시점: 구매확정/배송완료 시각에 근접 (없으면 주문일 + 5일)
        $earnedAt = $this->earnTimestamp($order);

        foreach ($order->options as $option) {
            $amount = round((float) $option->subtotal_earned_points_amount, 2);
            if ($amount <= 0) {
                continue;
            }

            $lot = $this->insertTransaction($member, $currency, MileageTransactionTypeEnum::PURCHASE_EARN, $amount, $earnedAt, [
                'remaining_amount' => $amount,
                'expires_at' => $earnedAt->copy()->addDays($this->expiryDays),
                'order_id' => $order->id,
                'order_option_id' => $option->id,
                'description_key' => 'mileage_earn',
                'description_amount' => $amount,
            ], $lots);

            $this->stats['earn']++;
            unset($lot);
        }
    }

    /**
     * 주문에 실제 마일리지 사용을 발생시킵니다 (주문 컬럼 갱신 + FIFO 차감).
     *
     * @param  User  $member  회원
     * @param  Order  $order  주문
     * @param  array  $lots  통화별 활성 lot 누적기 (참조)
     * @return int 실제 사용한 마일리지 (0 = 사용 안 함)
     */
    private function useForOrder(User $member, Order $order, array &$lots): int
    {
        $currency = $order->currency ?: $this->baseCurrency();
        $available = $this->availableBalance($lots, $currency);
        if ($available < $this->minUseAmount) {
            return 0;
        }

        // 결제금액 캡 (사용액은 결제금액을 넘을 수 없음)
        $payment = (int) $order->total_amount;
        if ($payment <= 0) {
            return 0;
        }

        // 잔액의 30~80% 를 사용단위 배수로, 결제금액 이내로
        $raw = (int) ($available * (rand(30, 80) / 100));
        $use = intdiv(min($raw, $payment), $this->useUnit) * $this->useUnit;
        if ($use < $this->minUseAmount) {
            return 0;
        }

        $usedAt = ($order->ordered_at instanceof Carbon ? $order->ordered_at->copy() : now());

        // FIFO 차감 + order_use 거래
        $this->consumeFifo($member, $currency, $use, MileageTransactionTypeEnum::ORDER_USE, $usedAt, [
            'order_id' => $order->id,
            'description_key' => 'mileage_use',
        ], $lots);

        // 주문/옵션에 사용액 정합 기록 (금액 비례 안분 — OrderProcessingService 와 동일 형태)
        $this->writeUsageToOrder($order, $use, $currency);

        $this->stats['use']++;

        return $use;
    }

    /**
     * 주문/주문옵션에 마일리지 사용액을 기록합니다 (옵션별 금액 비례 안분).
     *
     * @param  Order  $order  주문
     * @param  int  $usedAmount  총 사용액
     * @param  string  $currency  통화 코드
     */
    private function writeUsageToOrder(Order $order, int $usedAmount, string $currency): void
    {
        $options = $order->options;
        $base = (float) $options->sum('subtotal_paid_amount');
        if ($base <= 0) {
            $base = (float) $options->sum('subtotal_price');
        }

        $remaining = $usedAmount;
        $count = $options->count();

        foreach ($options as $index => $option) {
            if ($index === $count - 1) {
                $share = $remaining; // 마지막 옵션에 잔여 배분 (반올림 오차 흡수)
            } else {
                $optBase = (float) ($option->subtotal_paid_amount ?: $option->subtotal_price);
                $ratio = $base > 0 ? $optBase / $base : (1 / max($count, 1));
                $share = (int) floor($usedAmount * $ratio);
            }
            $remaining -= $share;

            $option->subtotal_points_used_amount = $share;
            $option->mc_subtotal_points_used_amount = [$currency => $share];
            $option->save();
        }

        $order->total_points_used_amount = $usedAmount;
        $order->mc_total_points_used_amount = [$currency => $usedAmount];
        $order->save();
    }

    /**
     * 취소된 주문의 마일리지 사용분을 복원합니다 (실제 order_cancel 레코드 생성 + 연결).
     *
     * @param  User  $member  회원
     * @param  Order  $order  취소 주문
     * @param  int  $usedAmount  복원 대상 사용액
     * @param  array  $lots  통화별 활성 lot 누적기 (참조)
     */
    private function restoreForCancelledOrder(User $member, Order $order, int $usedAmount, array &$lots): void
    {
        $currency = $order->currency ?: $this->baseCurrency();

        // 실제 취소 레코드 생성 (전체 취소)
        $cancelId = $this->createOrderCancelRecord($member, $order);

        $restoredAt = ($order->ordered_at instanceof Carbon ? $order->ordered_at->copy() : now())->addDays(rand(1, 3));

        // 복원 lot 은 신규 발행 (복원 시점 + 유효기간)
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
     * 실제 주문 취소 레코드를 생성합니다.
     *
     * @param  User  $member  회원
     * @param  Order  $order  취소 주문
     * @return int 생성된 취소 레코드 ID
     */
    private function createOrderCancelRecord(User $member, Order $order): int
    {
        $cancelNumber = app(SequenceService::class)->generateCode(SequenceType::CANCEL);
        $reasonCode = DB::table('ecommerce_claim_reasons')->value('code') ?? 'order_mistake';
        $cancelledAt = ($order->ordered_at instanceof Carbon ? $order->ordered_at->copy() : now())->addDays(rand(1, 2));

        $snapshot = $order->options->map(fn (OrderOption $opt) => [
            'order_option_id' => $opt->id,
            'quantity' => (int) $opt->quantity,
        ])->values()->all();

        // 배송지 스냅샷 (B5) — OrderCancellationService::buildShippingSnapshot 와 동일 형식.
        // 취소 시점 배송국가·우편번호를 독립 보존(환불 정책 재판단 복원용). 정책 상세는
        // 시더가 주문 스냅샷을 보유하지 않으므로 빈 배열로 둔다(폴백 복원 경로와 동치).
        $shippingAddress = $order->shippingAddress;
        $shippingSnapshot = [
            'country_code' => strtoupper((string) ($shippingAddress?->recipient_country_code ?: 'KR')),
            'zipcode' => $shippingAddress?->zipcode ?: ($shippingAddress?->intl_postal_code ?: null),
            'policies' => [],
        ];

        return (int) DB::table('ecommerce_order_cancels')->insertGetId([
            'order_id' => $order->id,
            'cancel_number' => $cancelNumber,
            'cancel_type' => CancelTypeEnum::FULL->value,
            'cancel_status' => CancelStatusEnum::COMPLETED->value,
            'cancel_reason_type' => $reasonCode,
            'cancel_reason' => null,
            'items_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'shipping_snapshot' => json_encode($shippingSnapshot, JSON_UNESCAPED_UNICODE),
            'cancelled_by' => $member->id,
            'cancelled_at' => $cancelledAt,
            'created_at' => $cancelledAt,
            'updated_at' => $cancelledAt,
        ]);
    }

    /**
     * 관리자 수동 지급 거래를 생성합니다.
     *
     * @param  User  $member  회원
     * @param  Collection<int, User>  $admins  관리자 컬렉션
     * @param  array  $lots  통화별 활성 lot 누적기 (참조)
     */
    private function adminEarn(User $member, Collection $admins, array &$lots): void
    {
        $admin = $admins->isNotEmpty() ? $admins->random() : null;
        $amount = (float) (rand(10, 50) * 100); // 1000 ~ 5000원
        $grantedAt = now()->subDays(rand(5, 60));

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
     * @param  array  $lots  통화별 활성 lot 누적기 (참조)
     */
    private function adminDeduct(User $member, Collection $admins, array &$lots): void
    {
        $available = $this->availableBalance($lots, $this->baseCurrency());
        if ($available < 100) {
            return;
        }

        $admin = $admins->isNotEmpty() ? $admins->random() : null;
        $deduct = (int) min((int) ($available * 0.3), 3000);
        $deduct = max(100, $deduct);

        $this->consumeFifo($member, $this->baseCurrency(), $deduct, MileageTransactionTypeEnum::ADMIN_DEDUCT, now()->subDays(rand(1, 4)), [
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
     * @param  User  $member  회원
     * @param  string  $currency  통화 코드
     * @param  int  $amount  차감액 (양수)
     * @param  MileageTransactionTypeEnum  $type  차감 유형
     * @param  Carbon  $createdAt  거래 시각
     * @param  array  $extra  추가 필드 (order_id/granted_by/memo/description_key)
     * @param  array  $lots  통화별 활성 lot 누적기 (참조)
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
            if ($lotRemaining <= 0 || $lot->expired_at !== null) {
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
        $balanceAfter = $this->availableBalance($lots, $currency);

        $descriptionAmount = (int) ($fields['description_amount'] ?? abs($signedAmount));
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
            'description' => __("sirsoft-ecommerce::activity_log.description.{$descriptionKey}", ['amount' => $descriptionAmount]),
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
     * 현재 통화의 활성 lot 잔여 합을 반환합니다 (상태 기준).
     *
     * @param  array  $lots  통화별 활성 lot 누적기
     * @param  string  $currency  통화 코드
     * @return float 사용 가능 잔액
     */
    private function availableBalance(array $lots, string $currency): float
    {
        $sum = 0.0;
        foreach (($lots[$currency] ?? []) as $lot) {
            if ($lot->expired_at === null && (float) $lot->remaining_amount > 0) {
                $sum += (float) $lot->remaining_amount;
            }
        }

        return round($sum, 2);
    }

    /**
     * 주문의 적립 시점(구매확정/배송완료 근접)을 반환합니다.
     *
     * @param  Order  $order  주문
     * @return Carbon 적립 시각
     */
    private function earnTimestamp(Order $order): Carbon
    {
        $base = $order->ordered_at instanceof Carbon ? $order->ordered_at->copy() : now()->subDays(15);

        // 배송완료/구매확정은 주문 후 며칠 경과 시점
        return $base->addDays(rand(3, 8));
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
     */
    private function reportStats(): void
    {
        $txCount = MileageTransaction::count();
        $balanceCount = MileageBalance::count();

        $this->command->line("  - 마일리지 보유 회원: {$this->stats['members']}명");
        $this->command->line("  - 적립(구매): {$this->stats['earn']}건 / 사용: {$this->stats['use']}건 / 취소복원: {$this->stats['cancel_restore']}건");
        $this->command->line("  - 관리자 지급: {$this->stats['admin_earn']}건 / 관리자 차감: {$this->stats['admin_deduct']}건 / 소멸: {$this->stats['expired']}건");
        $this->command->info("마일리지 거래 {$txCount}건, 잔액 캐시 {$balanceCount}건이 성공적으로 생성되었습니다.");
    }
}
