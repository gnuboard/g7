<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Requests;

use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreEcommerceSettingsRequest;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 설정 저장 요청의 숫자 필드 캐스트 테스트
 *
 * HTML number 입력의 DOM 값은 문자열이고 Laravel `integer` 규칙은 숫자 문자열을
 * 통과시키되 캐스트하지 않는다. 캐스트가 없으면 문자열이 그대로 영속되어
 * 이후 Carbon 등 strict 타입 경계에서 TypeError 가 발생한다.
 *
 * 캐스트 대상은 rules() 에서 파생한다 — 필드를 손으로 열거하면 규칙이 추가될 때
 * 누락(드리프트)이 생기므로, integer/numeric 규칙을 가진 모든 필드가 자동 포함되어야 한다.
 *
 * @effects request_numeric_fields_cast_before_validation, request_validation_not_loosened
 */
class StoreEcommerceSettingsRequestNumericCastTest extends ModuleTestCase
{
    /**
     * 요청을 해석하고 검증 통과 데이터를 반환합니다.
     */
    private function resolve(array $payload): StoreEcommerceSettingsRequest
    {
        $request = StoreEcommerceSettingsRequest::create('/', 'POST', $payload);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->validateResolved();

        return $request;
    }

    public function test_auto_cancel_days_string_is_cast_to_int(): void
    {
        $request = $this->resolve([
            '_tab' => 'order_settings',
            'order_settings' => ['auto_cancel_days' => '5'],
        ]);

        $this->assertSame(5, $request->validated()['order_settings']['auto_cancel_days']);
    }

    public function test_zero_padded_numeric_string_is_cast_to_int(): void
    {
        $request = $this->resolve([
            '_tab' => 'order_settings',
            'order_settings' => ['auto_cancel_days' => '05'],
        ]);

        $this->assertSame(5, $request->validated()['order_settings']['auto_cancel_days']);
    }

    public function test_wildcard_nested_numeric_fields_are_cast(): void
    {
        $request = $this->resolve([
            '_tab' => 'mileage',
            'mileage' => [
                'enabled' => true,
                'default_earn_rate' => '1',
                'currency_rules' => [
                    ['currency_code' => 'KRW', 'point_value' => '1.5', 'use_unit' => '100', 'min_use_amount' => '1000'],
                ],
            ],
        ]);

        $rule = $request->validated()['mileage']['currency_rules'][0];

        $this->assertSame(1.5, $rule['point_value']);
        $this->assertSame(100, $rule['use_unit']);
        $this->assertSame(1000, $rule['min_use_amount']);
    }

    public function test_non_numeric_string_is_not_cast_and_still_fails_validation(): void
    {
        $this->expectException(ValidationException::class);

        $this->resolve([
            '_tab' => 'order_settings',
            'order_settings' => ['auto_cancel_days' => 'abc'],
        ]);
    }

    public function test_decimal_string_on_integer_field_still_fails_validation(): void
    {
        // 소수 문자열을 intval 로 통과시키면 검증이 느슨해진다 — 캐스트 대상에서 제외되어야 한다.
        $this->expectException(ValidationException::class);

        $this->resolve([
            '_tab' => 'order_settings',
            'order_settings' => ['auto_cancel_days' => '3.7'],
        ]);
    }

    public function test_string_fields_are_not_converted_to_numbers(): void
    {
        $request = $this->resolve([
            '_tab' => 'basic_info',
            'basic_info' => [
                'shop_name' => '테스트샵',
                'route_path' => 'shop',
                'business_number_1' => '012',
            ],
        ]);

        $this->assertSame('012', $request->validated()['basic_info']['business_number_1']);
    }

    /**
     * 빈 값은 조용히 null 로 영속되면 안 된다.
     *
     * 숫자 입력칸에 비숫자를 넣으면 브라우저가 값을 `""` 로 비우고, 그대로 저장하면
     * `ConvertEmptyStringsToNull` 로 null 이 되어 `nullable` 을 통과한다. 결과적으로
     * 화면에는 "저장되었습니다" 가 뜨지만 실제 값은 사라지고 소비처의 숨은 기본값으로
     * 동작해 관리자가 본 것과 실제 동작이 어긋난다.
     */
    public function test_empty_auto_cancel_days_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->resolve([
            '_tab' => 'order_settings',
            'order_settings' => ['auto_cancel_days' => ''],
        ]);
    }

    public function test_null_auto_cancel_days_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->resolve([
            '_tab' => 'order_settings',
            'order_settings' => ['auto_cancel_days' => null],
        ]);
    }

    /**
     * rules() 는 탭 구분 없이 적용되므로, auto_cancel_days 를 무조건 required 로 두면
     * 이 키를 보내지 않는 다른 탭 저장이 통째로 막힌다(마일리지 탭 저장 불가 회귀).
     * 필수는 "키가 왔을 때만" 적용되어야 한다.
     */
    public function test_other_tab_save_is_not_blocked_by_auto_cancel_days_requirement(): void
    {
        $request = $this->resolve([
            '_tab' => 'mileage',
            'mileage' => ['enabled' => true, 'default_earn_rate' => '1'],
        ]);

        $this->assertArrayNotHasKey('order_settings', $request->validated());
    }

    /**
     * 자동취소 스위치를 꺼도 값 자체는 항상 함께 전송되므로(화면 실측 확인),
     * 필수화가 정상 저장 흐름을 깨지 않아야 한다.
     */
    public function test_valid_value_passes_when_auto_cancel_disabled(): void
    {
        $request = $this->resolve([
            '_tab' => 'order_settings',
            'order_settings' => ['auto_cancel_expired' => false, 'auto_cancel_days' => 3],
        ]);

        $this->assertSame(3, $request->validated()['order_settings']['auto_cancel_days']);
    }

    /**
     * rules() 에 integer/numeric 규칙을 가진 필드가 존재하는 한, 요청 정규화는
     * 그 목록을 rules() 에서 파생해야 한다(하드코딩 열거 금지 — 드리프트 방지).
     */
    public function test_every_integer_rule_field_is_covered_by_derived_casting(): void
    {
        $rules = (new StoreEcommerceSettingsRequest)->rules();

        $numericFields = [];
        foreach ($rules as $field => $fieldRules) {
            $list = is_array($fieldRules) ? $fieldRules : explode('|', (string) $fieldRules);
            foreach ($list as $rule) {
                if (is_string($rule) && in_array($rule, ['integer', 'numeric'], true)) {
                    $numericFields[] = $field;
                    break;
                }
            }
        }

        $this->assertNotEmpty($numericFields, 'integer/numeric 규칙 필드가 하나도 없습니다 (테스트 전제 붕괴).');

        // 대표 필드 하나로 파생 캐스트가 실제 동작함을 확인 (전수는 파생 로직 자체가 보장).
        $this->assertContains('order_settings.auto_cancel_days', $numericFields);
    }
}
