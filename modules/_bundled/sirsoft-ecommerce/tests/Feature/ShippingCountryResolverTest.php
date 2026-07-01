<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\ShippingCountryResolver;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ShippingCountryResolver 테스트 (MP08 후속 — D2)
 *
 * - allowedShippingCountryCodes: active 필터
 * - isAllowed: 활성 국가만 true
 * - resolve: 저장 > GeoIP > default 우선순위 (각 후보 isAllowed)
 * - 해외배송 OFF → KR 만 활성 collapse
 */
class ShippingCountryResolverTest extends ModuleTestCase
{
    private function resolver(): ShippingCountryResolver
    {
        return app(ShippingCountryResolver::class);
    }

    private function setShipping(bool $intl, array $activeCodes, string $default = 'KR'): void
    {
        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('shipping.international_shipping_enabled', $intl);
        $settings->setSetting('shipping.default_country', $default);
        $settings->setSetting('shipping.available_countries', [
            ['code' => 'KR', 'name' => ['ko' => '대한민국'], 'is_active' => in_array('KR', $activeCodes, true)],
            ['code' => 'US', 'name' => ['ko' => '미국'], 'is_active' => in_array('US', $activeCodes, true)],
            ['code' => 'JP', 'name' => ['ko' => '일본'], 'is_active' => in_array('JP', $activeCodes, true)],
        ]);
    }

    public function test_allowed_codes_only_includes_active_countries(): void
    {
        $this->setShipping(true, ['KR', 'US']);

        $codes = $this->resolver()->allowedShippingCountryCodes();

        sort($codes);
        $this->assertSame(['KR', 'US'], $codes);
    }

    public function test_is_allowed_rejects_inactive_country(): void
    {
        $this->setShipping(true, ['KR', 'US']);

        $this->assertTrue($this->resolver()->isAllowed('US'));
        $this->assertTrue($this->resolver()->isAllowed('us'));
        $this->assertFalse($this->resolver()->isAllowed('JP'));
        $this->assertFalse($this->resolver()->isAllowed(null));
    }

    public function test_resolve_prefers_saved_country_when_active(): void
    {
        $this->setShipping(true, ['KR', 'US']);
        $user = User::factory()->create();
        app(EcommerceUserProfileRepositoryInterface::class)->setPreferredShippingCountry($user->id, 'US');

        $this->assertSame('US', $this->resolver()->resolve($user->id, null));
    }

    public function test_resolve_falls_back_to_default_when_saved_inactive(): void
    {
        $this->setShipping(true, ['KR', 'US'], 'KR');
        $user = User::factory()->create();
        // JP 저장돼 있으나 비활성 → default(KR) 로 폴백
        app(EcommerceUserProfileRepositoryInterface::class)->setPreferredShippingCountry($user->id, 'JP');

        $this->assertSame('KR', $this->resolver()->resolve($user->id, null));
    }

    public function test_resolve_collapses_to_kr_when_international_off(): void
    {
        // 해외배송 OFF → KR 만 활성
        $this->setShipping(false, ['KR'], 'KR');

        $this->assertSame(['KR'], $this->resolver()->allowedShippingCountryCodes());
        $this->assertSame('KR', $this->resolver()->resolve(null, null));
    }
}
