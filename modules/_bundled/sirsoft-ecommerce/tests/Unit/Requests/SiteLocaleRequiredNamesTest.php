<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Requests;

use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreBanksRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreEcommerceSettingsRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreShippingPolicyRequest;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 카탈로그 이름의 필수 언어 = 사이트 기본 언어
 *
 * 코어 `LocaleRequiredTranslatable` 이 요청 언어 대신 사이트 기본 언어(`app.site_locale`)를 필수로 보도록
 * 바뀌었다(제품 결정 2026-10-08, 후속 B 세션 B3). 규칙 밖에서 요청 언어로 필수를 따로 판정하던 자리
 * (은행·택배사·배송유형·클레임 사유·직접 배송명)가 그대로면 다른 언어 관리자에게 「사이트 언어 + 보는 언어」
 * 둘 다 필수가 되는 모순이 생기므로 같은 기준으로 맞춘다.
 */
class SiteLocaleRequiredNamesTest extends ModuleTestCase
{
    /**
     * 사이트 기본 언어 ko · 요청 언어 en 으로 시작한다
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.site_locale' => 'ko']);
        app()->setLocale('en');
    }

    /**
     * 요청 하나를 규칙 + 지정한 after 검사로 검증한다.
     *
     * @param  object  $request  FormRequest  검증할 FormRequest
     * @param  array<string, mixed>  $data  입력
     * @param  string|null  $afterMethod  after 검사 메서드 이름
     * @param  array<int, mixed>  $afterArgs  after 검사 추가 인자
     * @return \Illuminate\Validation\Validator 검증기
     */
    private function check(object $request, array $data, ?string $afterMethod = null, array $afterArgs = []): \Illuminate\Validation\Validator
    {
        $request->merge($data);
        $validator = Validator::make($request->all(), $request->rules());

        if ($afterMethod !== null) {
            $validator->after(function ($v) use ($request, $afterMethod, $afterArgs) {
                $method = new \ReflectionMethod($request, $afterMethod);
                $method->setAccessible(true);
                $method->invoke($request, $v, ...$afterArgs);
            });
        }

        $validator->passes();

        return $validator;
    }

    /**
     * 은행 관리 창: 영어로 보는 관리자도 ko 은행명이 필수이고 en 만으로는 막힌다
     *
     * @scenario area=site_locale
     *
     * @effects catalog_names_require_site_locale
     *
     * @return void 반환값 없음
     */
    public function test_banks_require_site_locale_name(): void
    {
        $enOnly = $this->check(new StoreBanksRequest, ['banks' => [['code' => '004', 'name' => ['en' => 'Kookmin']]]]);
        $this->assertTrue($enOnly->errors()->has('banks.0.name.ko'), $enOnly->errors()->toJson());

        $koOnly = $this->check(new StoreBanksRequest, ['banks' => [['code' => '004', 'name' => ['ko' => '국민은행']]]]);
        $this->assertFalse($koOnly->errors()->has('banks.0.name.en'), $koOnly->errors()->toJson());
        $this->assertTrue($koOnly->errors()->isEmpty(), $koOnly->errors()->toJson());
    }

    /**
     * 환경설정 주문설정 탭의 은행 목록도 같은 기준이다
     *
     * @scenario area=site_locale
     *
     * @effects catalog_names_require_site_locale
     *
     * @return void 반환값 없음
     */
    public function test_settings_banks_require_site_locale_name(): void
    {
        $enOnly = $this->check(new StoreEcommerceSettingsRequest, ['_tab' => 'order_settings', 'order_settings' => ['banks' => [['code' => '004', 'name' => ['en' => 'Kookmin']]]]]);
        $this->assertTrue($enOnly->errors()->has('order_settings.banks.0.name.ko'), $enOnly->errors()->toJson());

        $koOnly = $this->check(new StoreEcommerceSettingsRequest, ['_tab' => 'order_settings', 'order_settings' => ['banks' => [['code' => '004', 'name' => ['ko' => '국민은행']]]]]);
        $this->assertFalse($koOnly->errors()->has('order_settings.banks.0.name.en'), $koOnly->errors()->toJson());
    }

    /**
     * 새 택배사 이름: en 만이면 ko 칸에 오류, ko 만이면 en 을 요구하지 않는다
     *
     * @scenario area=site_locale
     *
     * @effects catalog_names_require_site_locale
     *
     * @return void 반환값 없음
     */
    public function test_new_carrier_requires_site_locale_name(): void
    {
        $enOnly = $this->check(new StoreEcommerceSettingsRequest, ['_tab' => 'shipping', 'shipping' => ['carriers' => [['code' => 'b3cj', 'name' => ['en' => 'CJ'], 'type' => 'domestic']]]], 'validateCarrierNames');
        $this->assertTrue($enOnly->errors()->has('shipping.carriers.0.name.ko'), $enOnly->errors()->toJson());

        $koOnly = $this->check(new StoreEcommerceSettingsRequest, ['_tab' => 'shipping', 'shipping' => ['carriers' => [['code' => 'b3cj', 'name' => ['ko' => '씨제이'], 'type' => 'domestic']]]], 'validateCarrierNames');
        $this->assertFalse($koOnly->errors()->has('shipping.carriers.0.name.en'), $koOnly->errors()->toJson());
    }

    /**
     * 새 배송유형 이름도 같은 기준이다
     *
     * @scenario area=site_locale
     *
     * @effects catalog_names_require_site_locale
     *
     * @return void 반환값 없음
     */
    public function test_new_shipping_type_requires_site_locale_name(): void
    {
        $enOnly = $this->check(new StoreEcommerceSettingsRequest, ['_tab' => 'shipping', 'shipping' => ['types' => [['code' => 'b3type', 'name' => ['en' => 'Quick'], 'category' => 'domestic', 'is_active' => true]]]], 'validateShippingTypeNames');
        $this->assertTrue($enOnly->errors()->has('shipping.types.0.name.ko'), $enOnly->errors()->toJson());

        $koOnly = $this->check(new StoreEcommerceSettingsRequest, ['_tab' => 'shipping', 'shipping' => ['types' => [['code' => 'b3type', 'name' => ['ko' => '퀵'], 'category' => 'domestic', 'is_active' => true]]]], 'validateShippingTypeNames');
        $this->assertFalse($koOnly->errors()->has('shipping.types.0.name.en'), $koOnly->errors()->toJson());
    }

    /**
     * 새 클레임 사유 이름도 같은 기준이다
     *
     * @scenario area=site_locale
     *
     * @effects catalog_names_require_site_locale
     *
     * @return void 반환값 없음
     */
    public function test_new_claim_reason_requires_site_locale_name(): void
    {
        $row = fn (array $name) => ['code' => 'b3_reason', 'name' => $name, 'fault_type' => 'customer', 'is_user_selectable' => true, 'is_active' => true, 'sort_order' => 0];

        $enOnly = $this->check(new StoreEcommerceSettingsRequest, ['_tab' => 'claim', 'claim' => ['exchange_reasons' => [$row(['en' => 'Changed mind'])]]], 'validateClaimReasonNames', ['exchange']);
        $this->assertTrue($enOnly->errors()->has('claim.exchange_reasons.0.name.ko'), $enOnly->errors()->toJson());

        $koOnly = $this->check(new StoreEcommerceSettingsRequest, ['_tab' => 'claim', 'claim' => ['exchange_reasons' => [$row(['ko' => '단순 변심'])]]], 'validateClaimReasonNames', ['exchange']);
        $this->assertFalse($koOnly->errors()->has('claim.exchange_reasons.0.name.en'), $koOnly->errors()->toJson());
    }

    /**
     * 배송정책 국가별 직접 배송명도 같은 기준이다
     *
     * @scenario area=site_locale
     *
     * @effects catalog_names_require_site_locale
     *
     * @return void 반환값 없음
     */
    public function test_custom_shipping_name_requires_site_locale_name(): void
    {
        $cs = fn (array $name) => ['country_code' => 'KR', 'shipping_method' => 'custom', 'custom_shipping_name' => $name];

        $enOnly = $this->check(new StoreShippingPolicyRequest, ['country_settings' => [$cs(['en' => 'Own delivery'])]], 'validateCustomShippingName');
        $this->assertTrue($enOnly->errors()->has('country_settings.0.custom_shipping_name.ko'), $enOnly->errors()->toJson());

        $koOnly = $this->check(new StoreShippingPolicyRequest, ['country_settings' => [$cs(['ko' => '직접 배송'])]], 'validateCustomShippingName');
        $this->assertFalse($koOnly->errors()->has('country_settings.0.custom_shipping_name.en'), $koOnly->errors()->toJson());
    }
}
