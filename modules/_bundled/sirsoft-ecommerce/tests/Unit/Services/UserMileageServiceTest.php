<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Models\User;
use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\DTO\MileageAdminDeductDto;
use Modules\Sirsoft\Ecommerce\DTO\MileageAdminEarnDto;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\MileageValidationException;
use Modules\Sirsoft\Ecommerce\Models\MileageBalance;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageBalanceRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageTransactionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * UserMileageService 통합 테스트 (실 DB — FIFO/검증/적립/복원/회수/소멸/수동/캐시)
 */
class UserMileageServiceTest extends ModuleTestCase
{
    private UserMileageService $service;

    private MileageTransactionRepositoryInterface $ledger;

    private MileageBalanceRepositoryInterface $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeMileageSettings();
        $this->service = app(UserMileageService::class);
        $this->ledger = app(MileageTransactionRepositoryInterface::class);
        $this->cache = app(MileageBalanceRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        $file = storage_path('framework/testing/modules/sirsoft-ecommerce/settings/mileage.json');
        if (file_exists($file)) {
            unlink($file);
        }
        parent::tearDown();
    }

    /**
     * 마일리지 설정 파일을 작성합니다.
     *
     * @param  array  $overrides  덮어쓸 값
     */
    private function writeMileageSettings(array $overrides = []): void
    {
        $path = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $settings = array_merge([
            'enabled' => true,
            'default_earn_rate' => 1,
            'earn_trigger' => 'confirmed',
            'earn_delay_days' => 0,
            'currency_rules' => [
                ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 1000, 'use_unit' => 10, 'max_use_type' => 'fixed', 'max_use_percent' => 30, 'max_use_value' => 50000],
            ],
            'expiry_enabled' => true,
            'expiry_days' => 365,
        ], $overrides);

        file_put_contents($path.'/mileage.json', json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 적립 lot 을 직접 생성합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  float  $amount  금액
     * @param  Carbon|null  $expiresAt  만료일
     * @return MileageTransaction 생성된 lot
     */
    private function seedLot(int $userId, float $amount, $expiresAt = null): MileageTransaction
    {
        return MileageTransaction::create([
            'user_id' => $userId,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => $amount,
            'remaining_amount' => $amount,
            'balance_after' => $amount,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * 잔액 SSoT = 활성 lot SUM 확인.
     */
    public function test_balance_is_sum_of_active_lots(): void
    {
        $user = User::factory()->create();
        $this->seedLot($user->id, 1000);
        $this->seedLot($user->id, 500, now()->subDay()); // 만료 → 제외

        $this->assertSame(1000.0, $this->ledger->getBalance($user->id));
    }

    /**
     * FIFO 차감이 만료 임박 lot 부터 소비하고 source 를 연결한다.
     */
    public function test_fifo_deducts_oldest_expiring_lot_first(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'currency' => 'KRW']);
        $early = $this->seedLot($user->id, 600, now()->addDays(10));
        $late = $this->seedLot($user->id, 600, now()->addDays(100));

        $tx = $this->service->deductFifo($user->id, 800, 'KRW', $order);

        $this->assertSame(-800.0, (float) $tx->amount);
        $this->assertSame(0.0, (float) $early->fresh()->remaining_amount);
        $this->assertSame(400.0, (float) $late->fresh()->remaining_amount);
        $this->assertSame($early->id, $tx->source_transaction_id);
        $this->assertSame(400.0, $this->ledger->getBalance($user->id));
    }

    /**
     * 잔액 부족 시 차감이 예외로 거부된다.
     */
    public function test_deduct_throws_when_insufficient_balance(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'currency' => 'KRW']);
        $this->seedLot($user->id, 500);

        $this->expectException(MileageValidationException::class);
        $this->service->deductFifo($user->id, 1000, 'KRW', $order);
    }

    /**
     * 사용 검증 — 최소금액/단위/최대한도/잔액.
     */
    public function test_validate_usage_rules(): void
    {
        $user = User::factory()->create();
        $this->seedLot($user->id, 100000);

        // 최소 사용 금액 미만
        try {
            $this->service->validateUsage($user->id, 500, 100000, 'KRW');
            $this->fail('최소금액 미만은 예외여야 합니다.');
        } catch (MileageValidationException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        // 사용 단위 위배 (10단위)
        $this->expectException(MileageValidationException::class);
        $this->service->validateUsage($user->id, 1005, 100000, 'KRW');
    }

    /**
     * 최대 사용 한도(fixed) + 단위 내림 보정.
     */
    public function test_max_usable_caps_and_floors(): void
    {
        $user = User::factory()->create();
        $this->seedLot($user->id, 100000);

        // max_use_value=50000(fixed), payment=80000 → 50000, 단위 10 → 50000
        $max = $this->service->getMaxUsable($user->id, 80000, 'KRW');
        $this->assertSame(50000, $max);

        // 결제금액이 캡보다 작으면 결제금액 기준
        $max2 = $this->service->getMaxUsable($user->id, 3333, 'KRW');
        $this->assertSame(3330, $max2); // 10단위 내림
    }

    /**
     * 적립 — subtotal_earned_points_amount 기준 + 멱등 + 캐시 재계산.
     */
    public function test_earn_for_order_option_is_idempotent_and_updates_cache(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'currency' => 'KRW']);
        $option = OrderOption::factory()->forOrder($order)->create(['subtotal_earned_points_amount' => 300]);

        $tx = $this->service->earnForOrderOption($order, $option, MileageTransactionTypeEnum::PURCHASE_EARN);
        $this->assertNotNull($tx);
        $this->assertSame(300.0, (float) $tx->amount);

        // 재호출 시 멱등 (null)
        $this->assertNull($this->service->earnForOrderOption($order, $option, MileageTransactionTypeEnum::PURCHASE_EARN));

        // 캐시 available 반영
        $balance = MileageBalance::where('user_id', $user->id)->where('currency', 'KRW')->first();
        $this->assertNotNull($balance);
        $this->assertSame(300.0, (float) $balance->available);
    }

    /**
     * 복원 — order_cancel_id 멱등 + 신규 lot.
     */
    public function test_restore_for_cancel_is_idempotent(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'currency' => 'KRW']);

        $first = $this->service->restoreForCancel($user->id, $order->id, 777, 500, 'KRW');
        $this->assertNotNull($first);
        $this->assertSame(MileageTransactionTypeEnum::ORDER_CANCEL_RESTORE->value, $first->type->value);

        // 동일 cancel id 재호출 → null (멱등)
        $this->assertNull($this->service->restoreForCancel($user->id, $order->id, 777, 500, 'KRW'));
    }

    /**
     * 복원 시 그 주문의 원 사용 거래에 동일 order_cancel_id 가 역주입되어 연결 거래로 조회된다.
     *
     * (회귀: 복원 거래를 펼치면 항상 "연결 거래 없음" 이던 결함 — 원 사용 거래에 cancel_id 미주입)
     */
    public function test_restore_links_original_use_transaction_via_cancel_id(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id, 'currency' => 'KRW']);

        // 적립 → 사용 (order_use, order_id 연결)
        $this->ledger->createTransaction([
            'user_id' => $user->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => 1000, 'remaining_amount' => 1000, 'balance_after' => 1000, 'expires_at' => now()->addYear(),
        ]);
        $use = $this->service->deductFifo($user->id, 500, 'KRW', $order);

        // 복원
        $restore = $this->service->restoreForCancel($user->id, $order->id, 888, 500, 'KRW');
        $this->assertNotNull($restore);

        // 원 사용 거래에 동일 order_cancel_id 가 역주입되어야 한다
        $use->refresh();
        $this->assertSame(888, (int) $use->order_cancel_id, '원 사용 거래에 복원의 cancel_id 가 역주입되어야 합니다');

        // 복원 거래를 펼치면 원 사용 거래가 연결 거래로 조회되어야 한다
        $linked = $this->ledger->getLinkedTransactions($restore);
        $this->assertTrue(
            $linked->contains(fn ($t) => $t->id === $use->id),
            '복원 거래의 연결 거래에 원 사용 거래가 포함되어야 합니다',
        );
    }

    /**
     * 자동 만료 — per-item expired 거래 + expired_at + 잔액 감소.
     */
    public function test_expire_lots_records_expired_transactions(): void
    {
        $user = User::factory()->create();
        $this->seedLot($user->id, 400, now()->subDay());
        $this->seedLot($user->id, 600, now()->addDays(30));

        $count = $this->service->expireLots(now());

        $this->assertSame(1, $count);
        $this->assertSame(600.0, $this->ledger->getBalance($user->id));
        $this->assertDatabaseHas('ecommerce_mileage_transactions', [
            'user_id' => $user->id,
            'type' => MileageTransactionTypeEnum::EXPIRED->value,
        ]);
    }

    /**
     * 소멸 금액은 적립 전체가 아니라 만료 시점의 "잔여(remaining)"만 소멸한다.
     *
     * 적립 520 중 310 을 사용해 잔여 210 이 된 lot 이 만료되면, 소멸 거래는 적립액(520)이
     * 아니라 잔여(210)만 -210 으로 소멸시킨다. (적립≠소멸 금액은 부분 사용의 정상 결과)
     */
    public function test_expire_lots_only_expires_remaining_not_full_earn(): void
    {
        $user = User::factory()->create();

        // 적립 520, 만료 예정일이 이미 지남
        $lot = $this->seedLot($user->id, 520, now()->subDay());
        // 310 을 사용한 것으로 잔여를 210 으로 만든다 (FIFO 차감 결과 시뮬레이션)
        $lot->remaining_amount = 210;
        $lot->save();

        $count = $this->service->expireLots(now());

        $this->assertSame(1, $count, '만료된 lot 1건이 소멸돼야 합니다');

        $expire = MileageTransaction::query()
            ->where('type', MileageTransactionTypeEnum::EXPIRED->value)
            ->where('source_transaction_id', $lot->id)
            ->firstOrFail();

        // 소멸액은 잔여(210)만 — 적립 전체(520)가 아니다
        $this->assertSame(-210.0, (float) $expire->amount, '소멸은 잔여(210)만, 적립 전체(520)가 아니어야 합니다');
        $this->assertSame(0.0, (float) $lot->fresh()->remaining_amount, '소멸 후 lot 잔여는 0');
    }

    /**
     * 관리자 지급 — granted_by + 무기한 옵션.
     */
    public function test_admin_earn_unlimited(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create();

        $tx = $this->service->adminEarn($user->id, new MileageAdminEarnDto(
            amount: 1000,
            currency: 'KRW',
            grantedBy: $admin->id,
            memo: '테스트 지급',
            useDefaultExpiry: false,
        ));

        $this->assertSame(MileageTransactionTypeEnum::ADMIN_EARN->value, $tx->type->value);
        $this->assertSame($admin->id, $tx->granted_by);
        $this->assertNull($tx->expires_at);
        $this->assertSame(1000.0, $this->ledger->getBalance($user->id));
    }

    /**
     * 적립건 편집 — 사유(memo) + 만료일 직접 지정.
     */
    public function test_update_admin_transaction_edits_memo_and_expiry(): void
    {
        $user = User::factory()->create();
        $lot = $this->seedLot($user->id, 1000, now()->addDays(10));

        $newExpiry = Carbon::parse(now()->addDays(60)->toDateString());
        $updated = $this->service->updateAdminTransaction($lot->id, '정정 사유', $newExpiry, true, true);

        $this->assertSame('정정 사유', $updated->memo);
        $this->assertSame($newExpiry->toDateString(), $updated->expires_at->toDateString());
    }

    /**
     * 만료일을 적립일시(created_at)보다 과거로 지정하면 거부한다.
     */
    public function test_update_admin_transaction_rejects_expiry_before_earned(): void
    {
        $user = User::factory()->create();
        // 적립일시를 30일 전으로 보정한 활성 적립건
        $lot = $this->seedLot($user->id, 1000, now()->addDays(30));
        $lot->created_at = now()->subDays(30);
        $lot->save();

        // 적립일(30일 전)보다 더 과거(40일 전)로 만료일 변경 시도 → 예외
        $this->expectException(MileageValidationException::class);
        $this->service->updateAdminTransaction(
            $lot->id,
            null,
            Carbon::parse(now()->subDays(40)->toDateString()),
            false,
            true,
        );
    }

    /**
     * 적립일시 이후 만료일은 허용 (경계 정상).
     */
    public function test_update_admin_transaction_allows_expiry_after_earned(): void
    {
        $user = User::factory()->create();
        $lot = $this->seedLot($user->id, 1000, now()->addDays(30));
        $lot->created_at = now()->subDays(30);
        $lot->save();

        // 적립일(30일 전) 이후 = 오늘 → 허용
        $newExpiry = Carbon::parse(now()->toDateString());
        $updated = $this->service->updateAdminTransaction($lot->id, null, $newExpiry, false, true);

        $this->assertSame($newExpiry->toDateString(), $updated->expires_at->toDateString());
    }

    /**
     * 적립계가 아닌 거래는 편집 불가.
     */
    public function test_update_admin_transaction_rejects_non_earning(): void
    {
        $user = User::factory()->create();
        $use = MileageTransaction::create([
            'user_id' => $user->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::ORDER_USE->value,
            'amount' => -500, 'remaining_amount' => 0, 'balance_after' => 0,
        ]);

        $this->expectException(MileageValidationException::class);
        $this->service->updateAdminTransaction($use->id, '바꿔보기', null, true, false);
    }

    /**
     * 이미 소멸된 적립건은 만료일 변경 거부, memo 만 변경 허용.
     */
    public function test_update_admin_transaction_expired_lot_blocks_expiry_allows_memo(): void
    {
        $user = User::factory()->create();
        $lot = MileageTransaction::create([
            'user_id' => $user->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => 1000, 'remaining_amount' => 0, 'balance_after' => 0,
            'expires_at' => now()->subDays(3), 'expired_at' => now()->subDay(),
        ]);

        // 만료일 변경 시도 → 예외
        try {
            $this->service->updateAdminTransaction($lot->id, null, Carbon::parse(now()->addDays(30)->toDateString()), false, true);
            $this->fail('소멸된 lot 의 만료일 변경은 예외여야 합니다');
        } catch (MileageValidationException $e) {
            // 기대된 예외
        }

        // memo 만 변경 → 허용
        $updated = $this->service->updateAdminTransaction($lot->id, '소멸건 메모', null, true, false);
        $this->assertSame('소멸건 메모', $updated->memo);
    }

    /**
     * 관리자 차감 — 잔액 내 FIFO + 초과 시 거부.
     */
    public function test_admin_deduct_rejects_when_exceeds_balance(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create();
        $this->seedLot($user->id, 500);

        // 잔액 내 차감
        $tx = $this->service->adminDeduct($user->id, new MileageAdminDeductDto(amount: 300, currency: 'KRW', grantedBy: $admin->id));
        $this->assertSame(-300.0, (float) $tx->amount);
        $this->assertSame(200.0, $this->ledger->getBalance($user->id));

        // 잔액 초과 차감 → 예외
        $this->expectException(MileageValidationException::class);
        $this->service->adminDeduct($user->id, new MileageAdminDeductDto(amount: 1000, currency: 'KRW', grantedBy: $admin->id));
    }

    /**
     * 유효기간 연장 — 만료된 lot 부활 + 캐시 재계산.
     */
    public function test_extend_lot_expiry_revives_expired_lot(): void
    {
        $user = User::factory()->create();
        $expiredLot = $this->seedLot($user->id, 400, now()->subDay());

        // 만료 상태이므로 현재 잔액 0
        $this->assertSame(0.0, $this->ledger->getBalance($user->id));

        $count = $this->service->extendLotExpiry($user->id, [$expiredLot->id], 30);

        $this->assertSame(1, $count);
        $this->assertSame(400.0, $this->ledger->getBalance($user->id));

        $balance = MileageBalance::where('user_id', $user->id)->where('currency', 'KRW')->first();
        $this->assertSame(400.0, (float) $balance->available);
    }

    /**
     * 탈퇴 — 활성 lot 소멸 기록 (markExpired).
     */
    public function test_record_withdrawal_expiry_marks_active_lots_expired(): void
    {
        $user = User::factory()->create();
        $lot = $this->seedLot($user->id, 700);

        $this->service->recordWithdrawalExpiry($user->id);

        $this->assertSame(0.0, (float) $lot->fresh()->remaining_amount);
        $this->assertNotNull($lot->fresh()->expired_at);
    }

    /**
     * 비회원(user_id null) 적립 제외.
     */
    public function test_guest_order_earn_returns_null(): void
    {
        $order = Order::factory()->create(['user_id' => null, 'currency' => 'KRW']);
        $option = OrderOption::factory()->forOrder($order)->create(['subtotal_earned_points_amount' => 300]);

        $this->assertNull($this->service->earnForOrderOption($order, $option, MileageTransactionTypeEnum::PURCHASE_EARN));
    }

    /**
     * getBalance 화면 조회는 캐시에서 pending 포함 전 필드를 반환한다.
     */
    public function test_get_balance_reads_from_cache(): void
    {
        $user = User::factory()->create();
        $this->seedLot($user->id, 1000);
        $this->cache->recalculateForUser($user->id, 'KRW');

        $balance = $this->service->getBalance($user->id);

        $this->assertTrue($balance['enabled']);
        $this->assertSame(1000.0, (float) $balance['available']);
        $this->assertArrayHasKey('pending', $balance);
        $this->assertArrayHasKey('by_currency', $balance);
    }

    /**
     * getBalance 는 mileage.enabled 설정값을 enabled 키로 반영한다 (화면 비활성 안내용).
     */
    public function test_get_balance_reflects_disabled_setting(): void
    {
        $this->writeMileageSettings(['enabled' => false]);
        $user = User::factory()->create();

        $balance = $this->service->getBalance($user->id);

        $this->assertFalse($balance['enabled']);
    }

    /**
     * 마일리지 정산 통화 = 주문 base_currency (표시통화와 무관).
     *
     * 유저가 표시통화를 USD 로 선택(order.currency='USD')해도, 주문 통화 스냅샷의
     * base_currency 가 KRW 이면 적립은 KRW lot 으로 기록되어야 한다 (주문서/PG 정산과 일치).
     */
    public function test_earn_uses_base_currency_not_display_currency(): void
    {
        $user = User::factory()->create();
        // 표시통화 USD, 기본화폐(정산) KRW 인 주문
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'currency' => 'USD',
            'currency_snapshot' => ['base_currency' => 'KRW', 'order_currency' => 'USD'],
        ]);
        $option = OrderOption::factory()->forOrder($order)->create(['subtotal_earned_points_amount' => 500]);

        $tx = $this->service->earnForOrderOption($order, $option, MileageTransactionTypeEnum::PURCHASE_EARN);

        $this->assertNotNull($tx);
        // 표시통화(USD)가 아닌 base_currency(KRW)로 적립
        $this->assertSame('KRW', $tx->currency, '마일리지 적립은 표시통화가 아닌 base_currency 로 기록되어야 합니다.');
        $this->assertSame(500.0, (float) $tx->amount);
    }

    /**
     * baseCurrencyForOrder: currency_snapshot.base_currency 우선, 없으면 설정 기본 통화 폴백.
     */
    public function test_base_currency_for_order_resolution(): void
    {
        $user = User::factory()->create();

        $withSnapshot = Order::factory()->create([
            'user_id' => $user->id,
            'currency' => 'USD',
            'currency_snapshot' => ['base_currency' => 'KRW'],
        ]);
        $this->assertSame('KRW', $this->service->baseCurrencyForOrder($withSnapshot));

        // 스냅샷 부재 → 설정 기본 통화(currency_rules[0]=KRW) 폴백
        $noSnapshot = Order::factory()->create([
            'user_id' => $user->id,
            'currency' => 'USD',
            'currency_snapshot' => null,
        ]);
        $this->assertSame('KRW', $this->service->baseCurrencyForOrder($noSnapshot));
    }
}
