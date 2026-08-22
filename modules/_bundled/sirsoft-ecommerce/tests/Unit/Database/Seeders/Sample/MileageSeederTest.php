<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Database\Seeders\Sample;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ClaimReasonSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\MileageSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\SequenceSeeder;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageBalance;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageBalanceRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageTransactionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * 이커머스 MileageSeeder 통합 테스트 (실주문 기반).
 *
 * 마일리지 거래가 실제 주문에 정합되는지(사용 거래의 order_id 연결, 주문 사용액 컬럼 갱신,
 * 취소 복원의 실제 order_cancel 연결), 캐시가 원장과 정합한지 검증합니다.
 */
class MileageSeederTest extends ModuleTestCase
{
    /**
     * 테스트 주문번호 시퀀스 (Factory 충돌 회피)
     */
    private int $orderSequence = 0;

    /**
     * 회원/관리자 + 실제 주문(적립 가능/취소 포함)을 준비합니다.
     *
     * @param  int  $memberCount  생성할 일반 회원 수
     */
    private function bootstrap(int $memberCount = 12): void
    {
        MileageTransaction::query()->delete();
        MileageBalance::query()->delete();
        DB::table('ecommerce_order_cancels')->delete();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SequenceSeeder::class);
        $this->seed(ClaimReasonSeeder::class);
        $this->writeMileageSettings();

        $userRole = Role::query()->where('identifier', 'user')->firstOrFail();
        $adminRole = Role::query()->where('identifier', 'admin')->firstOrFail();

        $members = User::factory()->count($memberCount)->create();
        $members->each(fn (User $u) => $u->roles()->attach($userRole->id, ['assigned_at' => now()]));

        User::factory()->create()->roles()->attach($adminRole->id, ['assigned_at' => now()]);

        // 회원별 실제 주문: 구매확정(적립용) + 취소(복원용) + 배송완료
        foreach ($members as $member) {
            $this->makeOrder($member, OrderStatusEnum::CONFIRMED, 3000, 300);
            $this->makeOrder($member, OrderStatusEnum::DELIVERED, 5000, 500);
            $this->makeOrder($member, OrderStatusEnum::CANCELLED, 4000, 0);
        }
    }

    /**
     * 마일리지 설정 파일을 작성합니다.
     */
    private function writeMileageSettings(): void
    {
        $path = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $settings = [
            'enabled' => true,
            'default_earn_rate' => 1,
            'earn_trigger' => 'confirmed',
            'earn_delay_days' => 0,
            'currency_rules' => [['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 100, 'use_unit' => 10, 'max_use_type' => 'percent', 'max_use_percent' => 100, 'max_use_value' => 0]],
            'expiry_enabled' => true,
            'expiry_days' => 365,
            'expiry_notification_days_before' => 7,
        ];

        file_put_contents($path.'/mileage.json', json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
     * 단일 주문 + 옵션을 생성합니다.
     *
     * @param  User  $member  회원
     * @param  OrderStatusEnum  $status  주문 상태
     * @param  int  $totalAmount  주문 총액 (결제금액 캡)
     * @param  float  $earnAmount  옵션 적립 예정액
     * @return Order 생성된 주문
     */
    private function makeOrder(User $member, OrderStatusEnum $status, int $totalAmount, float $earnAmount): Order
    {
        // 고유 주문번호 보장 (Factory 랜덤 충돌 회피 — 다량 생성 시 중복 가능)
        $this->orderSequence++;

        $order = Order::factory()->create([
            'user_id' => $member->id,
            'order_number' => 'ORD-TEST-'.str_pad((string) $this->orderSequence, 6, '0', STR_PAD_LEFT),
            'currency' => 'KRW',
            'order_status' => $status,
            'total_amount' => $totalAmount,
            'total_points_used_amount' => 0,
            'ordered_at' => now()->subDays(rand(20, 200)),
        ]);

        OrderOption::factory()->forOrder($order)->create([
            'option_status' => $status,
            'subtotal_price' => $totalAmount,
            'subtotal_paid_amount' => $totalAmount,
            'subtotal_points_used_amount' => 0,
            'subtotal_earned_points_amount' => $earnAmount,
        ]);

        return $order->fresh('options');
    }

    public function test_seeder_creates_transactions_and_balance_cache(): void
    {
        $this->bootstrap();

        $this->seed(MileageSeeder::class);

        $this->assertGreaterThan(0, MileageTransaction::count(), '마일리지 거래가 생성되어야 합니다');
        $this->assertGreaterThan(0, MileageBalance::count(), '잔액 캐시 행이 생성되어야 합니다');
    }

    public function test_purchase_earn_links_real_order_and_option(): void
    {
        $this->bootstrap();

        $this->seed(MileageSeeder::class);

        $earns = MileageTransaction::query()
            ->where('type', MileageTransactionTypeEnum::PURCHASE_EARN->value)
            ->get();

        $this->assertGreaterThan(0, $earns->count(), '구매 적립이 하나 이상 존재해야 합니다');

        foreach ($earns as $tx) {
            $this->assertNotNull($tx->order_id, '구매 적립은 실제 주문에 연결되어야 합니다');
            $this->assertNotNull($tx->order_option_id, '구매 적립은 주문 옵션에 연결되어야 합니다');
            $this->assertDatabaseHas('ecommerce_orders', ['id' => $tx->order_id]);
            $this->assertDatabaseHas('ecommerce_order_options', ['id' => $tx->order_option_id]);
        }
    }

    public function test_order_use_links_real_order_and_updates_order_columns(): void
    {
        $this->bootstrap();

        $this->seed(MileageSeeder::class);

        $uses = MileageTransaction::query()
            ->where('type', MileageTransactionTypeEnum::ORDER_USE->value)
            ->get();

        $this->assertGreaterThan(0, $uses->count(), '주문 사용 거래가 하나 이상 존재해야 합니다 (실주문 기반)');

        foreach ($uses as $tx) {
            // 사용 거래는 실제 주문에 연결
            $this->assertNotNull($tx->order_id, '주문 사용 거래는 order_id 를 가져야 합니다');
            $order = Order::find($tx->order_id);
            $this->assertNotNull($order, '연결된 주문이 실제로 존재해야 합니다');

            // 주문의 total_points_used_amount 가 사용액과 정합
            $this->assertEqualsWithDelta(
                abs((float) $tx->amount),
                (float) $order->total_points_used_amount,
                0.01,
                '주문 total_points_used_amount 가 사용 거래액과 정합해야 합니다',
            );

            // 옵션 사용액 합 = 주문 사용액 (안분 정합)
            $optionSum = (float) OrderOption::where('order_id', $order->id)->sum('subtotal_points_used_amount');
            $this->assertEqualsWithDelta(
                (float) $order->total_points_used_amount,
                $optionSum,
                0.01,
                '옵션 사용액 합이 주문 사용액과 정합해야 합니다 (안분)',
            );

            // FIFO source 연결
            $this->assertNotNull($tx->source_transaction_id, '사용 거래는 FIFO source 를 가져야 합니다');
        }
    }

    public function test_cancel_restore_links_real_order_cancel_record(): void
    {
        $this->bootstrap();

        $this->seed(MileageSeeder::class);

        $restores = MileageTransaction::query()
            ->where('type', MileageTransactionTypeEnum::ORDER_CANCEL_RESTORE->value)
            ->get();

        // 부트스트랩이 회원마다 취소 주문 + 잔액을 보장하므로 복원이 하나 이상 나와야 한다
        $this->assertGreaterThan(0, $restores->count(), '취소 복원 거래가 하나 이상 존재해야 합니다');

        foreach ($restores as $tx) {
            $this->assertNotNull($tx->order_id, '복원은 실제 주문에 연결되어야 합니다');
            $this->assertNotNull($tx->order_cancel_id, '복원은 order_cancel_id 를 가져야 합니다');

            // 실제 취소 레코드 존재
            $this->assertDatabaseHas('ecommerce_order_cancels', ['id' => $tx->order_cancel_id]);

            // 취소 레코드는 동일 주문에 속함
            $cancel = DB::table('ecommerce_order_cancels')->where('id', $tx->order_cancel_id)->first();
            $this->assertSame((int) $tx->order_id, (int) $cancel->order_id, '취소 레코드가 복원 거래의 주문과 일치해야 합니다');

            // 운영 모양 정합: 그 주문의 원 사용 거래에 동일 order_cancel_id 가 역주입되어
            // 복원 거래를 펼치면 연결 거래로 조회되어야 한다 (연결 거래 결함 회귀 방지)
            $linkedUse = MileageTransaction::query()
                ->where('order_id', $tx->order_id)
                ->where('type', MileageTransactionTypeEnum::ORDER_USE->value)
                ->where('order_cancel_id', $tx->order_cancel_id)
                ->exists();
            $this->assertTrue($linkedUse, '복원의 원 사용 거래에 동일 cancel_id 가 역주입되어야 합니다 (연결 거래)');

            $linked = app(MileageTransactionRepositoryInterface::class)
                ->getLinkedTransactions($tx);
            $this->assertTrue($linked->isNotEmpty(), '복원 거래의 연결 거래가 비어 있으면 안 됩니다');
        }
    }

    public function test_balance_cache_matches_ledger_sum(): void
    {
        $this->bootstrap();

        $this->seed(MileageSeeder::class);

        foreach (MileageBalance::query()->get() as $balance) {
            $ledgerAvailable = (float) MileageTransaction::query()
                ->forUserCurrency($balance->user_id, $balance->currency)
                ->active()
                ->sum('remaining_amount');

            $this->assertEqualsWithDelta(
                $ledgerAvailable,
                (float) $balance->available,
                0.01,
                "캐시 available 이 원장 합과 일치해야 합니다 (user={$balance->user_id})",
            );
        }
    }

    public function test_no_negative_remaining_or_balance(): void
    {
        $this->bootstrap();

        $this->seed(MileageSeeder::class);

        $this->assertSame(
            0,
            MileageTransaction::query()->where('remaining_amount', '<', 0)->count(),
            'remaining_amount 음수 거래가 없어야 합니다',
        );
        $this->assertSame(
            0,
            MileageBalance::query()->where('available', '<', 0)->count(),
            'available 음수 캐시가 없어야 합니다',
        );
    }

    public function test_usage_never_exceeds_payment_amount(): void
    {
        $this->bootstrap();

        $this->seed(MileageSeeder::class);

        // 사용액은 주문 결제금액(total_amount)을 초과할 수 없다
        foreach (Order::query()->where('total_points_used_amount', '>', 0)->get() as $order) {
            $this->assertLessThanOrEqual(
                (float) $order->total_amount,
                (float) $order->total_points_used_amount,
                "사용액이 결제금액을 초과하면 안 됩니다 (order={$order->id})",
            );
        }
    }

    public function test_seeder_is_idempotent_and_resets_order_usage(): void
    {
        $this->bootstrap();

        $this->seed(MileageSeeder::class);
        $firstTx = MileageTransaction::count();
        $firstCancels = DB::table('ecommerce_order_cancels')->count();

        // 재실행: 기존 거래/취소/주문 사용 흔적을 정리하고 새로 생성
        $this->seed(MileageSeeder::class);

        $orphanTx = MileageTransaction::query()
            ->whereNotIn('user_id', User::query()->pluck('id'))
            ->count();
        $this->assertSame(0, $orphanTx, '고아 거래가 남지 않아야 합니다');

        // 취소 레코드는 누적되지 않는다 — 매 실행 전 전량 삭제 후 취소 주문당 최대 1건 재생성하므로
        // 취소 주문 수(12)를 절대 넘지 않아야 한다 (두 실행 모두 동일 상한).
        $cancelledOrderCount = Order::query()->where('order_status', OrderStatusEnum::CANCELLED->value)->count();
        $secondCancels = DB::table('ecommerce_order_cancels')->count();
        $this->assertLessThanOrEqual($cancelledOrderCount, $firstCancels, '취소 레코드는 취소 주문 수를 넘지 않아야 합니다');
        $this->assertLessThanOrEqual($cancelledOrderCount, $secondCancels, '재실행 후에도 취소 레코드가 누적되지 않아야 합니다');

        $this->assertGreaterThan(0, $firstTx);
    }

    /**
     * 시드로 생성된 소멸 거래는 모두 인과가 보장돼야 한다 (존재 시).
     *
     * 소멸은 확률 기반이라 0건일 수 있으므로, 이 통합 테스트는 "존재하면 인과 보장"만
     * 확인한다. 결정적 인과 검증은 test_expire_oldest_lot_enforces_causal_order 가 담당.
     */
    public function test_seeded_expired_transactions_never_predate_their_source_earn(): void
    {
        $this->bootstrap();

        $this->seed(MileageSeeder::class);

        $expires = MileageTransaction::query()
            ->where('type', MileageTransactionTypeEnum::EXPIRED->value)
            ->whereNotNull('source_transaction_id')
            ->get();

        foreach ($expires as $tx) {
            $source = MileageTransaction::find($tx->source_transaction_id);
            $this->assertNotNull($source, '소멸 거래의 원본 적립건이 존재해야 합니다');
            $this->assertTrue(
                $tx->created_at->greaterThanOrEqualTo($source->created_at),
                "소멸일({$tx->created_at}) 이 적립일({$source->created_at}) 보다 과거이면 안 됩니다 (시간 역전)",
            );
        }

        // 통합 흐름이 정상 종료됐음을 명시 (소멸 0건이어도 risky 가 아니도록)
        $this->assertTrue(true, '시드 소멸 인과 검증 완료');
    }

    /**
     * expireOldestLot 결정적 인과 검증 — 만료 예정일이 지난 lot 만 소멸시키고,
     * 소멸 시점이 적립일/만료예정일 이후이며 현재 시각을 넘지 않음.
     */
    public function test_expire_oldest_lot_enforces_causal_order(): void
    {
        $this->bootstrap(1);

        $member = User::query()
            ->whereHas('roles', fn ($q) => $q->where('identifier', 'user'))
            ->firstOrFail();

        $expiryDays = 365;

        // 만료 예정일이 이미 지난 적립 lot (적립일 = 400일 전 → 만료예정 = 35일 전)
        $earnedAt = now()->subDays($expiryDays + 35);
        $lot = MileageTransaction::create([
            'user_id' => $member->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => 1000, 'remaining_amount' => 1000, 'balance_after' => 1000,
            'expires_at' => $earnedAt->copy()->addDays($expiryDays),
        ]);
        $lot->created_at = $earnedAt;
        $lot->save();

        $seeder = $this->app->make(MileageSeeder::class);
        $seeder->setCommand($this->createMockCommand());

        // private 프로퍼티/메서드 reflection
        $ref = new \ReflectionClass($seeder);
        $expiryProp = $ref->getProperty('expiryDays');
        $expiryProp->setAccessible(true);
        $expiryProp->setValue($seeder, $expiryDays);

        $method = $ref->getMethod('expireOldestLot');
        $method->setAccessible(true);
        $lots = ['KRW' => [$lot]];
        $method->invokeArgs($seeder, [$member, &$lots]);

        $expire = MileageTransaction::query()
            ->where('type', MileageTransactionTypeEnum::EXPIRED->value)
            ->where('source_transaction_id', $lot->id)
            ->first();

        $this->assertNotNull($expire, '만료 예정일이 지난 lot 은 소멸돼야 합니다');
        $this->assertTrue($expire->created_at->greaterThanOrEqualTo($lot->fresh()->created_at), '소멸일이 적립일 이후');
        $this->assertTrue($expire->created_at->greaterThanOrEqualTo($lot->fresh()->expires_at), '소멸일이 만료예정일 이후');
        $this->assertTrue($expire->created_at->lessThanOrEqualTo(now()->addSecond()), '소멸일이 현재 시각을 넘지 않음');
    }

    /**
     * expireOldestLot 은 만료 예정일이 아직 미래인 lot 을 소멸시키지 않는다 (시간 역전 차단).
     */
    public function test_expire_oldest_lot_skips_lot_not_yet_due(): void
    {
        $this->bootstrap(1);

        $member = User::query()
            ->whereHas('roles', fn ($q) => $q->where('identifier', 'user'))
            ->firstOrFail();

        $expiryDays = 365;

        // 최근 적립 → 만료 예정일이 미래 (소멸 대상 아님)
        $earnedAt = now()->subDays(10);
        $lot = MileageTransaction::create([
            'user_id' => $member->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => 1000, 'remaining_amount' => 1000, 'balance_after' => 1000,
            'expires_at' => $earnedAt->copy()->addDays($expiryDays),
        ]);
        $lot->created_at = $earnedAt;
        $lot->save();

        $seeder = $this->app->make(MileageSeeder::class);
        $seeder->setCommand($this->createMockCommand());

        $ref = new \ReflectionClass($seeder);
        $expiryProp = $ref->getProperty('expiryDays');
        $expiryProp->setAccessible(true);
        $expiryProp->setValue($seeder, $expiryDays);

        $method = $ref->getMethod('expireOldestLot');
        $method->setAccessible(true);
        $lots = ['KRW' => [$lot]];
        $method->invokeArgs($seeder, [$member, &$lots]);

        $expire = MileageTransaction::query()
            ->where('type', MileageTransactionTypeEnum::EXPIRED->value)
            ->where('source_transaction_id', $lot->id)
            ->first();

        $this->assertNull($expire, '만료 예정일이 아직 미래인 lot 은 소멸시키지 않아야 합니다');
        $this->assertNull($lot->fresh()->expired_at, '미만료 lot 의 expired_at 이 채워지면 안 됩니다');
    }

    public function test_admin_grants_have_granted_by(): void
    {
        $this->bootstrap();

        $this->seed(MileageSeeder::class);

        $adminId = User::query()
            ->whereHas('roles', fn ($q) => $q->where('identifier', 'admin'))
            ->value('id');

        foreach (MileageTransaction::query()->where('type', MileageTransactionTypeEnum::ADMIN_EARN->value)->get() as $tx) {
            $this->assertSame($adminId, $tx->granted_by, '관리자 지급은 granted_by 가 채워져야 합니다');
            $this->assertNotNull($tx->memo, '관리자 지급은 메모를 가져야 합니다');
        }
    }

    public function test_balance_cache_recalculation_is_stable(): void
    {
        $this->bootstrap();

        $this->seed(MileageSeeder::class);

        $before = MileageBalance::query()->orderBy('user_id')->orderBy('currency')->get()
            ->map(fn ($b) => [$b->user_id, $b->currency, (string) $b->available, (string) $b->total_earned, (string) $b->total_used])
            ->all();

        app(MileageBalanceRepositoryInterface::class)->recalculateAll();

        $after = MileageBalance::query()->orderBy('user_id')->orderBy('currency')->get()
            ->map(fn ($b) => [$b->user_id, $b->currency, (string) $b->available, (string) $b->total_earned, (string) $b->total_used])
            ->all();

        $this->assertEquals($before, $after, '재계산 후 캐시가 동일해야 합니다 (정합성)');
    }

    public function test_no_orders_no_transactions(): void
    {
        // 회원은 있으나 주문이 없으면 마일리지를 만들지 않는다
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SequenceSeeder::class);
        $this->seed(ClaimReasonSeeder::class);
        $this->writeMileageSettings();
        MileageTransaction::query()->delete();
        MileageBalance::query()->delete();
        Order::query()->forceDelete();

        $userRole = Role::query()->where('identifier', 'user')->firstOrFail();
        User::factory()->count(3)->create()->each(fn (User $u) => $u->roles()->attach($userRole->id, ['assigned_at' => now()]));

        $seeder = $this->app->make(MileageSeeder::class);
        $seeder->setCommand($this->createMockCommand());
        $seeder->run();

        $this->assertSame(0, MileageTransaction::count(), '주문이 없으면 마일리지 거래를 만들지 않아야 합니다');
    }

    /**
     * 시더 run() 내부의 콘솔 출력/진행바 호출용 더미 커맨드.
     *
     * @return Command 더미 커맨드 인스턴스
     */
    private function createMockCommand(): Command
    {
        $command = new class extends Command
        {
            protected $signature = 'test:mileage-dummy';

            public function info($string, $verbosity = null): void {}

            public function warn($string, $verbosity = null): void {}

            public function line($string, $style = null, $verbosity = null): void {}

            public function newLine($count = 1): void {}
        };

        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));

        return $command;
    }
}
