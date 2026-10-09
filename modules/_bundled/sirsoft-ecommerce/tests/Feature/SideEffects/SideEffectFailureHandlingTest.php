<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\SideEffects;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\PurgeCashReceiptIdentifierListener;
use Modules\Sirsoft\Ecommerce\Listeners\SyncProductFromOptionListener;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\CashReceiptService;
use Modules\Sirsoft\Ecommerce\Services\CouponService;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;
use Modules\Sirsoft\Ecommerce\Support\ConcurrencyErrors;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 부가 작업 실패의 처리 — 삼키지 않거나, 삼키더라도 흔적과 복구 경로를 남긴다 (C9 · 동시성 · B6 · B7 · B8 · B10)
 *
 * 시나리오 매니페스트: `tests/scenarios/side-effect-failure-handling.yaml`
 */
class SideEffectFailureHandlingTest extends ModuleTestCase
{
    /**
     * 교착(SQLSTATE 40001)은 다시 던지고, 그 밖의 예외는 호출부 판단에 맡긴다.
     *
     * @scenario item=concurrency
     *
     * @effects concurrency_errors_are_never_swallowed
     *
     * @return void 반환값 없음
     */
    public function test_concurrency_errors_are_rethrown(): void
    {
        $deadlock = new QueryException('mysql', 'update x', [], new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock'));

        try {
            ConcurrencyErrors::rethrowIfConcurrencyError($deadlock);
            $this->fail('교착을 삼켰다');
        } catch (QueryException $e) {
            $this->assertSame($deadlock, $e);
        }

        ConcurrencyErrors::rethrowIfConcurrencyError(new \RuntimeException('일반 실패'));
        $this->addToAssertionCount(1);
    }

    /**
     * 상품 재고 합계 동기화 실패는 흐름을 멈추지 않지만 오류 보고로 올린다(B6).
     *
     * @scenario item=b6_stock_sync
     *
     * @effects stock_sum_sync_failure_is_reported
     *
     * @return void 반환값 없음
     */
    public function test_stock_sum_sync_failure_is_reported(): void
    {
        Exceptions::fake();
        $repository = $this->createMock(ProductOptionRepositoryInterface::class);
        $repository->method('pluckProductIds')->willThrowException(new \RuntimeException('sum failed'));
        $this->app->instance(ProductOptionRepositoryInterface::class, $repository);

        app(SyncProductFromOptionListener::class)->syncProductStockFromOptions([1, 2], 2);

        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'sum failed');
    }

    /**
     * 구매확정 뒤 현금영수증 식별번호 폐기 실패는 삼키지 않는다 — 큐 재시도에 맡긴다(B7).
     *
     * @scenario item=b7_identifier_purge
     *
     * @effects identifier_purge_failure_is_retried_by_the_queue
     *
     * @return void 반환값 없음
     */
    public function test_identifier_purge_failure_propagates(): void
    {
        $service = $this->createMock(CashReceiptService::class);
        $service->method('purgeIdentifier')->willThrowException(new \RuntimeException('purge failed'));
        $this->app->instance(CashReceiptService::class, $service);

        $this->expectException(\RuntimeException::class);

        app(PurgeCashReceiptIdentifierListener::class)->purgeIdentifier(Order::factory()->create());
    }

    /**
     * 직접 발급 중 한 회원의 후속 처리가 실패하면 그 회원의 발급만 되돌리고, 발급 수는 실제 발급과 맞는다(B8).
     *
     * @scenario item=b8_direct_issue
     *
     * @effects direct_issue_failure_rolls_back_only_that_member
     *
     * @return void 반환값 없음
     */
    public function test_direct_issue_failure_rolls_back_only_that_member(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.promotion-coupon.update']);
        $this->actingAs($admin);
        $coupon = Coupon::create([
            'name' => ['ko' => '직접발급 쿠폰', 'en' => 'Direct Coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT->value,
            'discount_type' => CouponDiscountType::FIXED->value,
            'discount_value' => 1000,
            'min_order_amount' => 0,
            'issue_method' => CouponIssueMethod::DIRECT->value,
            'issue_condition' => CouponIssueCondition::MANUAL->value,
            'issue_status' => CouponIssueStatus::ISSUING->value,
            'per_user_limit' => 1,
            'valid_type' => 'days_from_issue',
            'valid_days' => 30,
            'is_combinable' => true,
            'target_scope' => CouponTargetScope::ALL->value,
            'created_by' => $admin->id,
        ]);
        $ok = User::factory()->create();
        $broken = User::factory()->create();

        HookManager::addAction('sirsoft-ecommerce.coupon.after_direct_issue', function ($c, $issue, $userId) use ($broken) {
            if ((int) $userId === (int) $broken->id) {
                throw new \RuntimeException('activity log failed');
            }
        });

        $result = app(CouponService::class)->issueDirectly($coupon->id, [$ok->id, $broken->id]);

        $this->assertSame(1, $result['issued']);
        $this->assertSame([(int) $broken->id], array_column($result['skipped'], 'user_id'));
        $this->assertSame(0, CouponIssue::where('coupon_id', $coupon->id)->where('user_id', $broken->id)->count(), '건너뛴 회원의 발급이 남았다');
        $this->assertSame(1, (int) $coupon->fresh()->issued_count, '발급 수가 실제 발급과 다르다');
    }

    /**
     * 적립 배치는 한 행의 실패가 뒤의 행을 막지 않는다 — 실패 행은 보고하고 다음 행을 적립한다(B10).
     *
     * @scenario item=b10_earn_batch
     *
     * @effects earn_batch_isolates_failing_rows
     *
     * @return void 반환값 없음
     */
    public function test_earn_batch_isolates_a_failing_row(): void
    {
        Exceptions::fake();
        $path = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
        file_put_contents($path.'/mileage.json', json_encode(['enabled' => true, 'earn_trigger' => 'confirmed', 'earn_delay_days' => 0]));

        $order = Order::factory()->create(['user_id' => User::factory()->create()->id, 'currency' => 'KRW']);
        $first = OrderOption::factory()->forOrder($order)->create(['option_status' => OrderStatusEnum::CONFIRMED, 'confirmed_at' => now()->subDay(), 'subtotal_earned_points_amount' => 100]);
        $second = OrderOption::factory()->forOrder($order)->create(['option_status' => OrderStatusEnum::CONFIRMED, 'confirmed_at' => now()->subDay(), 'subtotal_earned_points_amount' => 100]);
        $earned = [];

        $this->mock(UserMileageService::class, function ($mock) use ($first, &$earned) {
            $mock->shouldReceive('earnForOrderOption')->andReturnUsing(function ($o, $option) use ($first, &$earned) {
                if ((int) $option->id === (int) $first->id) {
                    throw new \RuntimeException('earn failed');
                }
                $earned[] = (int) $option->id;

                return null;
            });
        });

        try {
            Artisan::call('sirsoft-ecommerce:earn-mileage');
        } finally {
            @unlink($path.'/mileage.json');
        }

        $this->assertSame([(int) $second->id], $earned, '앞 행의 실패가 뒤 행의 적립을 막았다');
        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'earn failed');
    }
}
