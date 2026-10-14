<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Sirsoft\Ecommerce\Listeners\CouponActivityLogListener;
use Modules\Sirsoft\Ecommerce\Listeners\EcommerceAdminActivityLogListener;
use Modules\Sirsoft\Ecommerce\Listeners\OrderActivityLogListener;
use Modules\Sirsoft\Ecommerce\Listeners\ProductActivityLogListener;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CouponRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Psr\Log\LoggerInterface;

/**
 * 이커머스 활동 로그 일괄 핸들러 원자화 테스트 (#139)
 *
 * 큐로 도는 일괄 핸들러가 항목별 기록을 한 트랜잭션 안에서 남기는지 — 기록 순간의 트랜잭션 깊이가 모두
 * 「호출 전 + 1」인지 — 로 확인한다. 리스너 클래스마다 대표 핸들러 하나씩.
 */
class BulkActivityLogAtomicTest extends ModuleTestCase
{
    /** @var array<int, int> 기록 순간의 트랜잭션 깊이 */
    private array $levels = [];

    /**
     * 활동 로그 채널을 가로채 기록 순간의 트랜잭션 깊이를 모읍니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance('request', Request::create('/api/admin/test'));
        $this->levels = [];
        $channel = Mockery::mock(LoggerInterface::class);
        $channel->shouldReceive('info')->andReturnUsing(function () {
            $this->levels[] = DB::transactionLevel();
        });
        Log::shouldReceive('channel')->with('activity')->andReturn($channel);
        Log::shouldReceive('error')->never();
    }

    /**
     * 주문 일괄 상태 변경.
     *
     * @scenario handler=order_bulk_status
     *
     * @effects bulk_activity_log_atomic
     *
     * @return void 반환값 없음
     */
    public function test_order_bulk_status_rows_are_written_in_one_transaction(): void
    {
        $repo = Mockery::mock(OrderRepositoryInterface::class);
        $repo->shouldReceive('findByIdsKeyed')->andReturn($this->keyed(Order::class, [41391, 41392]));
        $this->app->instance(OrderRepositoryInterface::class, $repo);
        $base = DB::transactionLevel();

        app(OrderActivityLogListener::class)->handleOrderAfterBulkStatusUpdate([41391, 41392], 2);

        $this->assertAtomic($base, 2);
    }

    /**
     * 상품 일괄 재고 변경.
     *
     * @scenario handler=product_bulk_stock
     *
     * @effects bulk_activity_log_atomic
     *
     * @return void 반환값 없음
     */
    public function test_product_bulk_stock_rows_are_written_in_one_transaction(): void
    {
        $repo = Mockery::mock(ProductRepositoryInterface::class);
        $repo->shouldReceive('findByIdsKeyed')->andReturn($this->keyed(Product::class, [51391, 51392, 51393]));
        $this->app->instance(ProductRepositoryInterface::class, $repo);
        $base = DB::transactionLevel();

        app(ProductActivityLogListener::class)->handleProductAfterBulkStockUpdate([51391, 51392, 51393], 3);

        $this->assertAtomic($base, 3);
    }

    /**
     * 쿠폰 일괄 상태 변경.
     *
     * @scenario handler=coupon_bulk_status
     *
     * @effects bulk_activity_log_atomic
     *
     * @return void 반환값 없음
     */
    public function test_coupon_bulk_status_rows_are_written_in_one_transaction(): void
    {
        $repo = Mockery::mock(CouponRepositoryInterface::class);
        $repo->shouldReceive('findByIdsKeyed')->andReturn($this->keyed(Coupon::class, [61391, 61392]));
        $this->app->instance(CouponRepositoryInterface::class, $repo);
        $base = DB::transactionLevel();

        app(CouponActivityLogListener::class)->handleAfterBulkStatus([61391, 61392], 'issuing', 2);

        $this->assertAtomic($base, 2);
    }

    /**
     * 장바구니 일괄 삭제(관리자 리스너).
     *
     * @scenario handler=cart_bulk_delete
     *
     * @effects bulk_activity_log_atomic
     *
     * @return void 반환값 없음
     */
    public function test_ecommerce_admin_bulk_delete_rows_are_written_in_one_transaction(): void
    {
        $base = DB::transactionLevel();

        app(EcommerceAdminActivityLogListener::class)->handleCartAfterBulkDelete([71391, 71392, 71393], 3);

        $this->assertAtomic($base, 3);
    }

    /**
     * id 키 컬렉션을 만듭니다(저장하지 않은 모델).
     *
     * @param  class-string  $class  모델 클래스
     * @param  array<int, int>  $ids  id 목록
     * @return Collection<int, mixed> id 키 컬렉션
     */
    private function keyed(string $class, array $ids): Collection
    {
        $collection = new Collection;
        foreach ($ids as $id) {
            $model = new $class;
            $model->id = $id;
            $collection->put($id, $model);
        }

        return $collection;
    }

    /**
     * 기대 행 수만큼 기록됐고 모두 「기준 + 1」 깊이였는지 확인합니다.
     *
     * @param  int  $base  호출 전 트랜잭션 깊이
     * @param  int  $expected  기대 행 수
     * @return void 반환값 없음
     */
    private function assertAtomic(int $base, int $expected): void
    {
        $this->assertSame(array_fill(0, $expected, $base + 1), $this->levels, '일괄 기록이 한 트랜잭션 안에서 일어나지 않았다');
    }
}
