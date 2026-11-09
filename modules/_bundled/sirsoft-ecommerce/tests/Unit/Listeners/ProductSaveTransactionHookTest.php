<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

require_once __DIR__.'/../../Fixtures/ProductTransactionHookProbeListener.php';

use App\Extension\HookListenerRegistrar;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Tests\Fixtures\ProductTransactionHookProbeListener;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 저장 트랜잭션 안 훅 `before_create_commit`·`before_update_commit` (#883 3단계)
 *
 * 실제 등록 경로(HookListenerRegistrar)로 sync 리스너를 붙여, 트랜잭션 안·마지막 저장 뒤에 돌고(바뀐 값이 보인다)
 * 예외를 던지면 저장 전체가 되돌아가는지 본다. 기존 `after_*` 는 그대로 커밋 뒤다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/product-save-transaction-hooks.yaml
 */
class ProductSaveTransactionHookTest extends ModuleTestCase
{
    /**
     * 리스너와 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        ProductTransactionHookProbeListener::$seen = [];
        ProductTransactionHookProbeListener::$throw = false;
        HookListenerRegistrar::clear();
        HookListenerRegistrar::register(ProductTransactionHookProbeListener::class, 'test');

        $this->actingAs($this->createAdminUser(['sirsoft-ecommerce.products.update', 'sirsoft-ecommerce.products.create']));
    }

    /**
     * 리스너를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookListenerRegistrar::clear();
        ProductTransactionHookProbeListener::$throw = false;

        parent::tearDown();
    }

    /**
     * 수정 훅은 트랜잭션 안에서 바뀐 값과 수정 전 스냅샷을 함께 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=update_inside_transaction
     *
     * @effects product_hook_runs_inside_transaction
     */
    public function test_update_hook_runs_inside_transaction_with_snapshot(): void
    {
        $product = Product::factory()->create(['name' => ['ko' => '이전 이름', 'en' => 'Old']]);

        app(ProductService::class)->update($product, ['name' => ['ko' => '새 이름', 'en' => 'New']]);

        $this->assertCount(1, ProductTransactionHookProbeListener::$seen);
        [$hook, $id, $nameInside, $level, $snapshotName] = ProductTransactionHookProbeListener::$seen[0];
        $this->assertSame('update', $hook);
        $this->assertSame($product->id, $id);
        $this->assertSame('새 이름', $nameInside);
        $this->assertGreaterThan(1, $level, '테스트 트랜잭션 위의 저장 트랜잭션 안');
        $this->assertSame('이전 이름', $snapshotName);
    }

    /**
     * 수정 훅이 예외를 던지면 수정 전체가 되돌아간다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=update_listener_throws
     *
     * @effects product_hook_exception_rolls_back_save
     */
    public function test_update_hook_exception_rolls_back_the_save(): void
    {
        $product = Product::factory()->create(['name' => ['ko' => '이전 이름', 'en' => 'Old']]);
        ProductTransactionHookProbeListener::$throw = true;

        try {
            app(ProductService::class)->update($product, ['name' => ['ko' => '새 이름', 'en' => 'New']]);
            $this->fail('리스너 예외가 올라와야 한다');
        } catch (\RuntimeException $e) {
            $this->assertSame('product probe failure', $e->getMessage());
        }

        $this->assertSame('이전 이름', Product::query()->find($product->id)->name['ko']);
    }
}
