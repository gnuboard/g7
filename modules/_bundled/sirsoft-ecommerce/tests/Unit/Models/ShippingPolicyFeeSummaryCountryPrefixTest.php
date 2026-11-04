<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 배송비 요약의 국가 코드 표기 (2026-11-03)
 *
 * 배송 국가가 하나뿐인 정책은 요약 앞에 국가 코드(「KR: 」)를 붙이지 않는다 — 국내만 배송하는 쇼핑몰의 상품 상세 배송비에
 * 「KR: 70,000원 미만 3,500원」처럼 쓸모없는 코드가 보였다. 국가가 둘 이상이면 종전처럼 국가별로 구분한다.
 *
 * @scenario-manifest tests/scenarios/shipping-fee-summary-country-prefix.yaml
 */
class ShippingPolicyFeeSummaryCountryPrefixTest extends ModuleTestCase
{
    /**
     * 국가별 설정 한 건 데이터
     *
     * @param  string  $country  국가 코드
     * @param  array<string, mixed>  $overrides  덮어쓸 값
     * @return array<string, mixed> 국가별 설정
     */
    private function setting(string $country, array $overrides = []): array
    {
        return array_merge([
            'country_code' => $country,
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => 'fixed',
            'base_fee' => 3000,
            'free_threshold' => null,
            'ranges' => null,
            'api_endpoint' => null,
            'api_request_fields' => null,
            'api_response_fee_field' => null,
            'extra_fee_enabled' => false,
            'extra_fee_settings' => null,
            'extra_fee_multiply' => false,
            'is_active' => true,
        ], $overrides);
    }

    /**
     * 정책과 국가별 설정을 만든다
     *
     * @param  array<int, array<string, mixed>>  $settings  국가별 설정
     * @return ShippingPolicy 정책
     */
    private function policy(array $settings): ShippingPolicy
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '기본 택배', 'en' => 'Standard'],
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 1,
        ]);
        foreach ($settings as $setting) {
            $policy->countrySettings()->create($setting);
        }

        return $policy->load('countrySettings');
    }

    /**
     * 배송 국가가 하나면 국가 코드 없이 요약만
     *
     * @scenario active_countries=one
     *
     * @effects fee_summary_omits_country_code_when_single
     *
     * @return void 반환값 없음
     */
    public function test_single_country_summary_has_no_country_prefix(): void
    {
        $policy = $this->policy([$this->setting('KR')]);
        $countrySummary = $policy->countrySettings->first()->getFeeSummary();

        $this->assertSame($countrySummary, $policy->getFeeSummary());
        $this->assertStringNotContainsString('KR:', $policy->getFeeSummary());
    }

    /**
     * 국가가 둘 이상이면 종전처럼 「KR: … | US: …」
     *
     * @scenario active_countries=many
     *
     * @effects fee_summary_omits_country_code_when_single
     *
     * @return void 반환값 없음
     */
    public function test_multiple_countries_keep_country_prefix(): void
    {
        $many = $this->policy([$this->setting('KR'), $this->setting('US', ['currency_code' => 'USD', 'base_fee' => 20])]);
        $this->assertStringStartsWith('KR: ', $many->getFeeSummary());
        $this->assertStringContainsString(' | US: ', $many->getFeeSummary());
    }

    /**
     * 쉬는(비활성) 국가는 세지 않는다 — 활성 국가가 하나면 코드 없이 요약만
     *
     * @scenario active_countries=one_active_one_inactive
     *
     * @effects fee_summary_omits_country_code_when_single
     *
     * @return void 반환값 없음
     */
    public function test_inactive_country_is_not_counted(): void
    {
        $oneActive = $this->policy([$this->setting('KR'), $this->setting('US', ['currency_code' => 'USD', 'is_active' => false])]);
        $this->assertStringNotContainsString('KR:', $oneActive->getFeeSummary());
        $this->assertStringNotContainsString('US', $oneActive->getFeeSummary());
    }
}
