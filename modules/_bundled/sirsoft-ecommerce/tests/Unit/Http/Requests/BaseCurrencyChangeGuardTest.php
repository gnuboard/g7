<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Requests;

use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreEcommerceSettingsRequest;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * base 통화 변경 가드 테스트 (A2 — D-BASE-1)
 *
 * 상품/주문이 1건이라도 존재하면 default_currency 변경을 422 로 차단(영구 잠금).
 * 0건이면 변경 통과, 동일 통화 재저장은 통과(변경 아님).
 * (DB 격리는 ModuleTestCase 의 RefreshDatabase 가 처리)
 */
class BaseCurrencyChangeGuardTest extends ModuleTestCase
{
    /**
     * 현재 설정의 기본 통화를 반환합니다(환경 독립 — 운영/테스트 설정 무엇이든).
     */
    private function currentCurrency(): string
    {
        return (string) (app(EcommerceSettingsService::class)
            ->getSetting('language_currency.default_currency') ?? 'KRW');
    }

    /**
     * 현재 기본 통화와 "다른" 통화를 반환합니다(변경 시도가 항상 실제 변경이 되도록).
     */
    private function differentCurrency(): string
    {
        return $this->currentCurrency() === 'USD' ? 'KRW' : 'USD';
    }

    /**
     * withValidator 가드만 실행해 base 통화 에러 존재 여부를 반환합니다.
     */
    private function hasBaseLockError(string $newCurrency): bool
    {
        $request = StoreEcommerceSettingsRequest::create('/', 'POST', [
            'language_currency' => ['default_currency' => $newCurrency],
        ]);
        $request->setContainer($this->app);

        $validator = ValidatorFacade::make($request->all(), $request->rules());
        $request->withValidator($validator);
        $validator->passes();

        return $validator->errors()->has('language_currency.default_currency')
            && str_contains(
                (string) $validator->errors()->first('language_currency.default_currency'),
                __('sirsoft-ecommerce::validation.custom.language_currency.base_locked_after_data')
            );
    }

    public function test_change_allowed_when_no_product_and_no_order(): void
    {
        $this->assertFalse(
            app(ProductRepositoryInterface::class)->existsAny(),
            '사전 조건: 상품 0건이어야 함'
        );
        $this->assertFalse(app(OrderRepositoryInterface::class)->existsAny());

        // 데이터 0건 → 현재와 다른 통화로 변경 통과
        $this->assertFalse($this->hasBaseLockError($this->differentCurrency()));
    }

    public function test_change_blocked_when_product_exists(): void
    {
        Product::factory()->create();

        $this->assertTrue(app(ProductRepositoryInterface::class)->existsAny());

        // 상품 1건 → 현재와 다른 통화로의 base 변경 차단(422 메시지)
        $this->assertTrue($this->hasBaseLockError($this->differentCurrency()));
    }

    public function test_change_blocked_when_soft_deleted_product_only(): void
    {
        $product = Product::factory()->create();
        $product->delete(); // soft delete

        $this->assertTrue(
            app(ProductRepositoryInterface::class)->existsAny(),
            '소프트삭제된 상품도 existsAny 가 true 여야 함(withTrashed)'
        );
        $this->assertTrue($this->hasBaseLockError($this->differentCurrency()));
    }

    public function test_same_currency_resave_passes_even_with_data(): void
    {
        Product::factory()->create();

        // 동일 통화(현재 default) 재저장은 변경이 아니므로 통과
        $this->assertFalse($this->hasBaseLockError($this->currentCurrency()));
    }
}
