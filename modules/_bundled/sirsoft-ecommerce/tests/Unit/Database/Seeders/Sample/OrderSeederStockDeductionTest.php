<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Database\Seeders\Sample;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\OrderSeeder;
use Modules\Sirsoft\Ecommerce\Database\Seeders\SequenceSeeder;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * OrderSeeder 재고 차감 플래그(is_stock_deducted) 정합성 테스트.
 *
 * 회귀 배경(이슈 #44): 시더가 옵션 상태만 직접 세팅하고 StockService::deductStock 라이프사이클을
 * 거치지 않아, 결제완료 이후 상태(상품준비중 등)인 옵션도 is_stock_deducted=false 로 남아
 * 관리자 주문상세에 "재고 미차감"으로 표시되던 데이터 부정합을 차단한다.
 *
 * 불변식:
 *   - 결제 전(주문대기/결제대기) + 취소 옵션 → 미차감(false)
 *   - 결제완료 이후(결제완료/상품준비중/배송준비/배송중/배송완료/구매확정/배송보류) → 차감(true)
 */
class OrderSeederStockDeductionTest extends ModuleTestCase
{
    /**
     * 회원 풀 + 선행 시더(권한/시퀀스)를 준비합니다.
     *
     * @return void
     */
    private function bootstrap(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SequenceSeeder::class);

        $userRole = Role::query()->where('identifier', 'user')->firstOrFail();
        User::factory()->count(10)->create()->each(
            fn (User $u) => $u->roles()->attach($userRole->id, ['assigned_at' => now()])
        );

        // OrderSeeder 가 실상품 옵션 경로(is_stock_deducted 보정 대상)를 타도록 상품 옵션을 준비한다.
        // (옵션 팩토리가 Product 를 자동 생성)
        ProductOptionFactory::new()->count(8)->create();
    }

    /**
     * 소수 주문으로 OrderSeeder 를 실행합니다 (상품 없이 더미 옵션 경로).
     *
     * @return void
     */
    private function runOrderSeeder(): void
    {
        $seeder = new OrderSeeder;
        $seeder->setCommand($this->makeSilentCommand());
        $seeder->setSeederCounts(['orders' => 60]);
        $seeder->run();
    }

    /**
     * 진행 출력이 없는 콘솔 커맨드를 만듭니다.
     *
     * @return \Illuminate\Console\Command
     */
    private function makeSilentCommand(): \Illuminate\Console\Command
    {
        $command = new class extends \Illuminate\Console\Command
        {
            protected $signature = 'test:order-seeder-silent';
        };
        $command->setLaravel($this->app);
        $command->setOutput(new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new NullOutput
        ));

        return $command;
    }

    /**
     * 결제완료 이후 상태 옵션은 모두 재고 차감(true)으로 시드된다.
     *
     * @return void
     */
    public function test_paid_and_later_status_options_are_stock_deducted(): void
    {
        $this->bootstrap();
        $this->runOrderSeeder();

        $deductedStatuses = [
            OrderStatusEnum::PAYMENT_COMPLETE->value,
            OrderStatusEnum::SHIPPING_HOLD->value,
            OrderStatusEnum::PREPARING->value,
            OrderStatusEnum::SHIPPING_READY->value,
            OrderStatusEnum::SHIPPING->value,
            OrderStatusEnum::DELIVERED->value,
            OrderStatusEnum::CONFIRMED->value,
        ];

        $notDeducted = OrderOption::whereIn('option_status', $deductedStatuses)
            ->where('is_stock_deducted', false)
            ->count();

        $this->assertSame(
            0,
            $notDeducted,
            '결제완료 이후 상태인데 재고 미차감(is_stock_deducted=false)인 옵션이 존재해서는 안 된다.'
        );
    }

    /**
     * 결제 전(주문대기/결제대기) 및 취소 옵션은 재고 미차감(false)으로 시드된다.
     *
     * @return void
     */
    public function test_before_payment_and_cancelled_options_are_not_stock_deducted(): void
    {
        $this->bootstrap();
        $this->runOrderSeeder();

        $notDeductedStatuses = [
            OrderStatusEnum::PENDING_ORDER->value,
            OrderStatusEnum::PENDING_PAYMENT->value,
            OrderStatusEnum::CANCELLED->value,
        ];

        $deducted = OrderOption::whereIn('option_status', $notDeductedStatuses)
            ->where('is_stock_deducted', true)
            ->count();

        $this->assertSame(
            0,
            $deducted,
            '결제 전/취소 상태인데 재고 차감(is_stock_deducted=true)인 옵션이 존재해서는 안 된다.'
        );
    }

    /**
     * 모든 옵션이 컬럼 기본값(false) 그대로가 아니라 상태에 따라 차감 여부가 분기된다.
     *
     * 회귀 핵심: 보정 전에는 전 옵션이 false 였다. 차감(true) 옵션이 1건 이상 존재해야 한다.
     *
     * @return void
     */
    public function test_seeder_produces_both_deducted_and_undeducted_options(): void
    {
        $this->bootstrap();
        $this->runOrderSeeder();

        $this->assertGreaterThan(
            0,
            OrderOption::where('is_stock_deducted', true)->count(),
            '재고 차감된 옵션이 최소 1건 이상 시드되어야 한다 (전 옵션 false 회귀 차단).'
        );
        $this->assertGreaterThan(
            0,
            OrderOption::where('is_stock_deducted', false)->count(),
            '재고 미차감 옵션도 최소 1건 이상 시드되어야 한다 (결제 전/취소 상태).'
        );
    }
}
