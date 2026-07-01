<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\ShippingPolicyResolver;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 배송정책 폴백 해석기 Unit 테스트
 *
 * 상품에 배송정책이 부여되지 않은 경우(shipping_policy_id=null) 기본 배송정책으로
 * 폴백하는 ShippingPolicyResolver 의 동작을 검증합니다.
 * - 상품 정책이 있으면 그 정책을 사용
 * - 상품 정책이 없으면 기본 배송정책(is_default=true)으로 폴백
 * - 기본 배송정책도 없으면 국내(KR) 기본 배송으로 간주
 * - 폴백된 기본정책의 국가 설정으로 배송가능 판정
 */
class ShippingPolicyResolverTest extends ModuleTestCase
{
    protected ShippingPolicyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(ShippingPolicyResolver::class);
    }

    /**
     * 다국가 설정을 가진 배송정책을 생성합니다.
     *
     * @param  bool  $isDefault  기본 배송정책 여부
     * @param  array  $countries  국가코드 목록 (각 국가에 FIXED 600 설정)
     * @return ShippingPolicy 생성된 배송정책
     */
    protected function makePolicy(bool $isDefault, array $countries = ['KR']): ShippingPolicy
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '배송정책', 'en' => 'Policy'],
            'is_default' => $isDefault,
            'is_active' => true,
        ]);

        foreach ($countries as $code) {
            $policy->countrySettings()->create([
                'country_code' => $code,
                'shipping_method' => 'parcel',
                'currency_code' => 'KRW',
                'charge_policy' => ChargePolicyEnum::FIXED,
                'base_fee' => 600,
                'is_active' => true,
            ]);
        }

        return $policy->load('countrySettings');
    }

    /**
     * 상품에 배송정책이 부여되어 있으면 그 정책을 그대로 사용한다.
     */
    public function test_it_uses_product_own_policy_when_assigned(): void
    {
        $assigned = $this->makePolicy(isDefault: false, countries: ['KR']);
        $this->makePolicy(isDefault: true, countries: ['KR', 'JP']);

        $product = ProductFactory::new()->create(['shipping_policy_id' => $assigned->id]);

        $this->assertSame($assigned->id, $this->resolver->resolveForProduct($product)?->id);
    }

    /**
     * 상품에 배송정책이 없으면(null) 기본 배송정책으로 폴백한다.
     */
    public function test_it_falls_back_to_default_policy_when_product_has_none(): void
    {
        $default = $this->makePolicy(isDefault: true, countries: ['KR', 'JP']);

        $product = ProductFactory::new()->create(['shipping_policy_id' => null]);

        $this->assertSame($default->id, $this->resolver->resolveForProduct($product)?->id);
    }

    /**
     * 정책이 없는 상품이 기본 배송정책의 국가 설정(JP)으로 배송 가능하다.
     *
     * 회귀: 기본정책 폴백 없이 KR 만 허용하던 결함(일본 선택 시 배송불가) 차단.
     */
    public function test_null_policy_product_is_shippable_to_default_policy_country(): void
    {
        $this->makePolicy(isDefault: true, countries: ['KR', 'JP']);

        $product = ProductFactory::new()->create(['shipping_policy_id' => null]);

        $this->assertTrue($this->resolver->isShippableToCountry($product, 'JP'));
        $this->assertTrue($this->resolver->isShippableToCountry($product, 'KR'));
    }

    /**
     * 기본 배송정책에 설정되지 않은 국가는 배송 불가로 판정한다.
     */
    public function test_null_policy_product_is_not_shippable_to_country_absent_from_default(): void
    {
        $this->makePolicy(isDefault: true, countries: ['KR', 'JP']);

        $product = ProductFactory::new()->create(['shipping_policy_id' => null]);

        $this->assertFalse($this->resolver->isShippableToCountry($product, 'US'));
    }

    /**
     * 기본 배송정책 자체가 없으면 국내(KR) 기본 배송으로 간주한다.
     */
    public function test_it_treats_as_domestic_when_no_default_policy_exists(): void
    {
        $product = ProductFactory::new()->create(['shipping_policy_id' => null]);

        $this->assertNull($this->resolver->resolveForProduct($product));
        $this->assertTrue($this->resolver->isShippableToCountry($product, 'KR'));
        $this->assertFalse($this->resolver->isShippableToCountry($product, 'JP'));
    }

    /**
     * 기본 배송정책 조회는 요청 내 1회만 수행되어 캐시된다.
     */
    public function test_it_caches_default_policy_lookup(): void
    {
        $default = $this->makePolicy(isDefault: true, countries: ['KR']);

        $first = $this->resolver->getDefaultPolicy();
        $second = $this->resolver->getDefaultPolicy();

        $this->assertSame($default->id, $first?->id);
        $this->assertSame($first, $second);
    }
}
