<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Console;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\ModuleSettingsInterface;
use App\Models\User;
use App\Services\ModuleSettingsService;
use Illuminate\Support\Carbon;
use Mockery;
use Modules\Sirsoft\Ecommerce\Database\Factories\CartFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductOptionFactory;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ModuleInterface + ModuleSettingsInterface 결합 스텁
 */
abstract class PruneExpiredCartsModuleStub implements ModuleInterface, ModuleSettingsInterface {}

/**
 * 보관기간 만료 장바구니 자동 정리 커맨드 테스트 (A2)
 */
class PruneExpiredCartsCommandTest extends ModuleTestCase
{
    private array $moduleSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->moduleSettings = [
            'order_settings.cart_expiry_days' => 30,
        ];

        $this->mockModuleSetting();
    }

    /**
     * module_setting() 헬퍼가 사용하는 설정 서비스를 mock합니다.
     */
    private function mockModuleSetting(): void
    {
        $mockModule = $this->createMock(PruneExpiredCartsModuleStub::class);
        $mockModule->method('getSetting')
            ->willReturnCallback(function (string $key, mixed $default = null) {
                return array_key_exists($key, $this->moduleSettings)
                    ? $this->moduleSettings[$key]
                    : $default;
            });

        $mockModuleManager = $this->createMock(ModuleManagerInterface::class);
        $mockModuleManager->method('getModule')
            ->with('sirsoft-ecommerce')
            ->willReturn($mockModule);

        $this->app->instance(ModuleManagerInterface::class, $mockModuleManager);
        $this->app->forgetInstance(ModuleSettingsService::class);

        $mockEcommerceSettings = Mockery::mock(EcommerceSettingsService::class)->makePartial();
        $mockEcommerceSettings->shouldReceive('getSetting')
            ->andReturnUsing(function (string $key, mixed $default = null) {
                return array_key_exists($key, $this->moduleSettings)
                    ? $this->moduleSettings[$key]
                    : $default;
            });
        $this->app->instance(EcommerceSettingsService::class, $mockEcommerceSettings);
    }

    /**
     * updated_at 을 백데이트한 장바구니 항목을 생성합니다.
     */
    private function makeCart(int $daysAgo, ?User $user = null): Cart
    {
        $product = ProductFactory::new()->create();
        $option = ProductOptionFactory::new()->forProduct($product)->create();
        $factory = CartFactory::new()->forOption($option);
        $factory = $user ? $factory->forUser($user) : $factory->guest();

        $cart = $factory->create();
        Cart::withoutTimestamps(fn () => $cart->forceFill([
            'updated_at' => Carbon::now()->subDays($daysAgo),
        ])->save());

        return $cart->fresh();
    }

    public function test_command_exists(): void
    {
        $this->artisan('sirsoft-ecommerce:prune-expired-carts --dry-run')
            ->assertSuccessful();
    }

    public function test_deletes_expired_items_and_preserves_fresh(): void
    {
        // Given: 30일 기준 — 31일전 삭제, 29일전 보존
        $old = $this->makeCart(31);
        $fresh = $this->makeCart(29);

        // When
        $this->artisan('sirsoft-ecommerce:prune-expired-carts')
            ->assertSuccessful();

        // Then
        $this->assertNull(Cart::find($old->id));
        $this->assertNotNull(Cart::find($fresh->id));
    }

    public function test_dry_run_reports_count_without_deleting(): void
    {
        // Given
        $this->makeCart(40);
        $this->makeCart(40);

        // When: dry-run
        $this->artisan('sirsoft-ecommerce:prune-expired-carts --dry-run')
            ->assertSuccessful();

        // Then: 실삭제 0
        $this->assertSame(2, Cart::count());
    }

    public function test_zero_expiry_days_is_noop(): void
    {
        // Given: cart_expiry_days = 0 → 만료 비활성
        $this->moduleSettings['order_settings.cart_expiry_days'] = 0;
        $this->makeCart(100);
        $this->makeCart(200);

        // When
        $this->artisan('sirsoft-ecommerce:prune-expired-carts')
            ->assertSuccessful();

        // Then: 한 건도 삭제 안 함 (전체삭제 사고 차단)
        $this->assertSame(2, Cart::count());
    }

    public function test_unset_expiry_days_falls_back_to_default(): void
    {
        // Given: 설정 미존재 → 기본 30
        unset($this->moduleSettings['order_settings.cart_expiry_days']);
        $old = $this->makeCart(40);
        $fresh = $this->makeCart(10);

        // When
        $this->artisan('sirsoft-ecommerce:prune-expired-carts')
            ->assertSuccessful();

        // Then: 기본 30일 기준 적용
        $this->assertNull(Cart::find($old->id));
        $this->assertNotNull(Cart::find($fresh->id));
    }
}
