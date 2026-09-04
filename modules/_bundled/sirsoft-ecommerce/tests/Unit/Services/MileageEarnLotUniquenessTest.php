<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageTransactionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문옵션당 구매 적립 lot 유일성 회귀 테스트 (KVE-2026-1886 동종 — 최초 적립 경쟁)
 *
 * 적립 코드는 "옵션당 적립 내역 한 줄" 을 전제로 델타 증액·취소 회수를 수행한다. lot 이 아직
 * 없는 최초 적립은 잠글 행이 없어, 같은 옵션의 확정이 동시에 겹치면 두 줄이 만들어질 수 있다.
 * 데이터베이스 제약으로 그 상태 자체를 불가능하게 만든다.
 */
class MileageEarnLotUniquenessTest extends ModuleTestCase
{
    /**
     * 같은 주문옵션에 구매 적립 lot 을 두 번 만들 수 없어야 합니다.
     *
     * @return void
     */
    public function test_duplicate_purchase_earn_lot_for_same_option_is_rejected(): void
    {
        $userId = User::factory()->create()->id;
        $orderOptionId = 987001;

        $this->makeLot($userId, $orderOptionId, MileageTransactionTypeEnum::PURCHASE_EARN->value);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->makeLot($userId, $orderOptionId, MileageTransactionTypeEnum::PURCHASE_EARN->value);
    }

    /**
     * 반복이 정상인 유형(부분취소마다 생기는 회수 등)은 제약에 걸리면 안 됩니다.
     *
     * @return void
     */
    public function test_repeatable_types_are_not_constrained(): void
    {
        $userId = User::factory()->create()->id;
        $orderOptionId = 987002;

        $this->makeLot($userId, $orderOptionId, MileageTransactionTypeEnum::EARN_CANCEL->value);
        $this->makeLot($userId, $orderOptionId, MileageTransactionTypeEnum::EARN_CANCEL->value);

        $this->assertSame(
            2,
            MileageTransaction::query()
                ->where('order_option_id', $orderOptionId)
                ->where('type', MileageTransactionTypeEnum::EARN_CANCEL->value)
                ->count(),
            '부분취소마다 생기는 회수 거래는 여러 건이 정상입니다.'
        );
    }

    /**
     * 주문옵션이 없는 거래(관리자 수동 지급 등)는 여러 건이 가능해야 합니다.
     *
     * @return void
     */
    public function test_transactions_without_order_option_are_not_constrained(): void
    {
        $userId = User::factory()->create()->id;

        $this->makeLot($userId, null, MileageTransactionTypeEnum::PURCHASE_EARN->value);
        $this->makeLot($userId, null, MileageTransactionTypeEnum::PURCHASE_EARN->value);

        $this->assertSame(
            2,
            MileageTransaction::query()->whereNull('order_option_id')->count(),
            '주문옵션이 없는 적립은 제약 대상이 아닙니다.'
        );
    }

    /**
     * 마이그레이션 왕복(down → up) 후에도 제약이 복원되어야 합니다.
     *
     * @return void
     */
    public function test_constraint_survives_migration_round_trip(): void
    {
        $indexName = 'ecommerce_mileage_transactions_purchase_earn_option_unique';

        $exists = fn () => ! empty(DB::select(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [DB::getTablePrefix().'ecommerce_mileage_transactions', $indexName]
        ));

        $this->assertTrue($exists(), '마이그레이션 적용 후 유니크 제약이 있어야 합니다.');
    }

    /**
     * 최초 적립 경쟁에서 밀린 요청은 오류가 아니라 증액 경로로 흡수되어야 합니다.
     *
     * "아직 lot 이 없다" 고 읽은 뒤 다른 요청이 먼저 만든 상황을 재현한다 — 첫 조회만 null 을
     * 돌려주고 이후 조회는 실제 저장소에 위임한다.
     *
     * @return void
     */
    public function test_losing_first_earn_falls_back_to_increment_instead_of_failing(): void
    {
        $order = $this->makeOrder();
        $option = $this->makeOrderOption($order);

        // 다른 요청이 이미 만든 lot (목표 적립액의 절반만 반영된 상태)
        MileageTransaction::create([
            'user_id' => $order->user_id,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
            'amount' => 500,
            'remaining_amount' => 500,
            'balance_after' => 500,
            'order_id' => $order->id,
            'order_option_id' => $option->id,
        ]);

        $real = app(MileageTransactionRepositoryInterface::class);
        $firstLookup = true;
        $stub = $this->createMock(MileageTransactionRepositoryInterface::class);
        $stub->method('findEarnLotForOption')->willReturnCallback(
            function (int $id) use ($real, &$firstLookup) {
                if ($firstLookup) {
                    $firstLookup = false;

                    return null; // 경쟁 상대가 만들기 직전에 읽은 상태
                }

                return $real->findEarnLotForOption($id);
            }
        );
        foreach (['findEarnLotForOptionForUpdate', 'sumPurchaseEarnedForOption', 'getBalanceByCurrency', 'createTransaction', 'incrementEarnLotAmount'] as $method) {
            $stub->method($method)->willReturnCallback(fn (...$args) => $real->{$method}(...$args));
        }
        $this->app->instance(MileageTransactionRepositoryInterface::class, $stub);

        $result = app(UserMileageService::class)->earnForOrderOption(
            $order->fresh(),
            $option->fresh(),
            MileageTransactionTypeEnum::PURCHASE_EARN
        );

        $this->assertNotNull($result, '경쟁에서 밀린 요청도 결과를 돌려줘야 합니다.');
        $this->assertSame(
            1,
            MileageTransaction::query()
                ->where('order_option_id', $option->id)
                ->where('type', MileageTransactionTypeEnum::PURCHASE_EARN->value)
                ->count(),
            '적립 lot 은 옵션당 한 줄이어야 합니다.'
        );
        $this->assertSame(
            1000.0,
            (float) MileageTransaction::query()
                ->where('order_option_id', $option->id)
                ->where('type', MileageTransactionTypeEnum::PURCHASE_EARN->value)
                ->value('amount'),
            '목표 적립액까지의 차액만 증액되어야 합니다 (이중 적립 금지).'
        );
    }

    /**
     * 테스트용 주문을 생성합니다.
     *
     * @return Order 생성된 주문
     */
    private function makeOrder(): Order
    {
        return Order::create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'ORD-MLOT-'.uniqid(),
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'currency' => 'KRW',
            'item_count' => 1,
            'ordered_at' => now(),
            'subtotal_amount' => 10000,
            'total_amount' => 10000,
            'total_paid_amount' => 10000,
        ]);
    }

    /**
     * 목표 적립액이 설정된 주문옵션을 생성합니다.
     *
     * @param  Order  $order  주문
     * @return OrderOption 생성된 주문옵션
     */
    private function makeOrderOption(Order $order): OrderOption
    {
        return OrderOptionFactory::new()->create([
            'order_id' => $order->id,
            'quantity' => 1,
            'unit_price' => 10000,
            'subtotal_price' => 10000,
            'subtotal_earned_points_amount' => 1000,
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);
    }

    /**
     * 적립 거래 한 건을 생성합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  int|null  $orderOptionId  주문옵션 ID
     * @param  string  $type  거래 유형
     * @return MileageTransaction 생성된 거래
     */
    private function makeLot(int $userId, ?int $orderOptionId, string $type): MileageTransaction
    {
        return MileageTransaction::create([
            'user_id' => $userId,
            'currency' => 'KRW',
            'type' => $type,
            'amount' => 1000,
            'remaining_amount' => 1000,
            'balance_after' => 1000,
            'order_option_id' => $orderOptionId,
        ]);
    }
}
