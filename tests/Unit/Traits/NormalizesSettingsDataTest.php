<?php

namespace Tests\Unit\Traits;

use App\Traits\NormalizesSettingsData;
use Tests\TestCase;

/**
 * 트레이트 검증용 더미 클래스 (protected 메서드 노출)
 */
class NormalizesSettingsDataDummy
{
    use NormalizesSettingsData;

    public function normalize(array $settings, array $defaults): array
    {
        return $this->normalizeSettingsData($settings, $defaults);
    }

    public function normalizeCategory(array $settings, array $defaults): array
    {
        return $this->normalizeCategoryData($settings, $defaults);
    }
}

/**
 * 설정 데이터 정규화 트레이트 테스트
 *
 * 숫자 설정이 문자열로 영속되어도(HTML number 입력 → Laravel integer 규칙은
 * 숫자 문자열을 통과시키되 캐스트하지 않음) 조회 시 defaults 스키마의 스칼라 타입으로
 * 정규화되는지 검증한다. 정규화가 없으면 Carbon 등 strict 타입 경계에서 TypeError 가 난다.
 *
 * @effects settings_read_returns_schema_scalar_type
 */
class NormalizesSettingsDataTest extends TestCase
{
    private NormalizesSettingsDataDummy $dummy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dummy = new NormalizesSettingsDataDummy;
    }

    // ──────────────────────────────────────────────
    // 스칼라 숫자 정규화 (int)
    // ──────────────────────────────────────────────

    public function test_numeric_string_is_cast_to_int_when_default_is_int(): void
    {
        $result = $this->dummy->normalizeCategory(
            ['auto_cancel_days' => '5'],
            ['auto_cancel_days' => 3],
        );

        $this->assertSame(5, $result['auto_cancel_days']);
    }

    public function test_zero_padded_numeric_string_is_cast_to_int(): void
    {
        $result = $this->dummy->normalizeCategory(
            ['auto_cancel_days' => '007'],
            ['auto_cancel_days' => 3],
        );

        $this->assertSame(7, $result['auto_cancel_days']);
    }

    public function test_nested_category_settings_are_normalized(): void
    {
        $result = $this->dummy->normalize(
            ['order_settings' => ['auto_cancel_days' => '5', 'cart_expiry_days' => '30']],
            ['order_settings' => ['auto_cancel_days' => 3, 'cart_expiry_days' => 30]],
        );

        $this->assertSame(5, $result['order_settings']['auto_cancel_days']);
        $this->assertSame(30, $result['order_settings']['cart_expiry_days']);
    }

    // ──────────────────────────────────────────────
    // 스칼라 숫자 정규화 (float)
    // ──────────────────────────────────────────────

    public function test_numeric_string_is_cast_to_float_when_default_is_float(): void
    {
        $result = $this->dummy->normalizeCategory(
            ['point_value' => '0.5'],
            ['point_value' => 1.0],
        );

        $this->assertSame(0.5, $result['point_value']);
    }

    public function test_integer_string_is_cast_to_float_when_default_is_float(): void
    {
        $result = $this->dummy->normalizeCategory(
            ['point_value' => '2'],
            ['point_value' => 1.0],
        );

        $this->assertSame(2.0, $result['point_value']);
    }

    // ──────────────────────────────────────────────
    // 배열 항목 내부 정규화 (currencies 등)
    // ──────────────────────────────────────────────

    public function test_numeric_strings_inside_indexed_array_items_are_cast(): void
    {
        $result = $this->dummy->normalizeCategory(
            ['currencies' => [
                ['code' => 'KRW', 'decimal_places' => '0', 'exchange_rate' => '1'],
                ['code' => 'USD', 'decimal_places' => '2', 'exchange_rate' => '1350.5'],
            ]],
            ['currencies' => [
                ['code' => 'KRW', 'decimal_places' => 0, 'exchange_rate' => 1.0],
            ]],
        );

        $this->assertSame(0, $result['currencies'][0]['decimal_places']);
        $this->assertSame(1.0, $result['currencies'][0]['exchange_rate']);
        $this->assertSame(2, $result['currencies'][1]['decimal_places']);
        $this->assertSame(1350.5, $result['currencies'][1]['exchange_rate']);
    }

    // ──────────────────────────────────────────────
    // 비회귀 — 캐스트 대상이 아닌 값은 불변
    // ──────────────────────────────────────────────

    public function test_non_numeric_string_is_left_untouched(): void
    {
        $result = $this->dummy->normalizeCategory(
            ['auto_cancel_days' => 'abc'],
            ['auto_cancel_days' => 3],
        );

        $this->assertSame('abc', $result['auto_cancel_days']);
    }

    public function test_empty_string_is_left_untouched(): void
    {
        $result = $this->dummy->normalizeCategory(
            ['auto_cancel_days' => ''],
            ['auto_cancel_days' => 3],
        );

        $this->assertSame('', $result['auto_cancel_days']);
    }

    public function test_null_is_left_untouched(): void
    {
        $result = $this->dummy->normalizeCategory(
            ['auto_cancel_days' => null],
            ['auto_cancel_days' => 3],
        );

        $this->assertNull($result['auto_cancel_days']);
    }

    public function test_boolean_default_is_not_treated_as_numeric(): void
    {
        // PHP 에서 true 는 is_int 가 아니지만, 문자열 "1" 이 불리언 설정에 들어와도
        // int 로 바뀌면 안 된다 (기본값 타입이 bool 이므로 캐스트 대상 아님).
        $result = $this->dummy->normalizeCategory(
            ['auto_cancel_expired' => '1'],
            ['auto_cancel_expired' => true],
        );

        $this->assertSame('1', $result['auto_cancel_expired']);
    }

    public function test_string_default_keeps_numeric_string_as_string(): void
    {
        // 기본값이 문자열이면(예: 사업자번호 조각) 숫자처럼 보여도 문자열을 유지해야 한다.
        $result = $this->dummy->normalizeCategory(
            ['business_number_1' => '007'],
            ['business_number_1' => ''],
        );

        $this->assertSame('007', $result['business_number_1']);
    }

    public function test_default_absent_key_is_left_untouched(): void
    {
        $result = $this->dummy->normalizeCategory(
            ['unknown_key' => '5'],
            [],
        );

        $this->assertSame('5', $result['unknown_key']);
    }

    public function test_int_value_stays_int(): void
    {
        $result = $this->dummy->normalizeCategory(
            ['auto_cancel_days' => 5],
            ['auto_cancel_days' => 3],
        );

        $this->assertSame(5, $result['auto_cancel_days']);
    }

    public function test_multilingual_conversion_is_not_broken(): void
    {
        // 기존 다국어 분기(기본값 배열 + 값 문자열 → 로케일 배열)가 유지되어야 한다.
        $result = $this->dummy->normalizeCategory(
            ['shop_name' => '내 쇼핑몰'],
            ['shop_name' => ['ko' => '쇼핑몰', 'en' => 'Shop']],
        );

        $this->assertSame(['ko' => '내 쇼핑몰', 'en' => '내 쇼핑몰'], $result['shop_name']);
    }

    // ──────────────────────────────────────────────
    // 중첩 연관 배열(하위 설정 그룹) 정규화
    // ──────────────────────────────────────────────

    public function test_numeric_strings_inside_associative_group_are_cast(): void
    {
        // 카테고리 아래 한 단계 더 깊은 연관 배열도 정규화되어야 한다.
        // (인덱스 배열만 내려가던 시절에는 이 값이 문자열로 남아 Carbon 경계까지 갔다)
        $result = $this->dummy->normalizeCategory(
            ['mileage' => ['expiry_days' => '365', 'earn_rate' => '1.5']],
            ['mileage' => ['expiry_days' => 90, 'earn_rate' => 1.0]],
        );

        $this->assertSame(365, $result['mileage']['expiry_days']);
        $this->assertSame(1.5, $result['mileage']['earn_rate']);
    }

    public function test_associative_group_normalization_is_recursive(): void
    {
        $result = $this->dummy->normalizeCategory(
            ['a' => ['b' => ['c' => '7']]],
            ['a' => ['b' => ['c' => 1]]],
        );

        $this->assertSame(7, $result['a']['b']['c']);
    }

    public function test_multilingual_map_is_untouched_by_group_recursion(): void
    {
        // 다국어 필드도 연관 배열이지만 기본값이 문자열이라 캐스트 대상이 아니다.
        $result = $this->dummy->normalizeCategory(
            ['shop_name' => ['ko' => '5', 'en' => '5']],
            ['shop_name' => ['ko' => '쇼핑몰', 'en' => 'Shop']],
        );

        $this->assertSame(['ko' => '5', 'en' => '5'], $result['shop_name']);
    }

    public function test_group_without_matching_default_is_untouched(): void
    {
        $result = $this->dummy->normalizeCategory(
            ['unknown_group' => ['x' => '5']],
            [],
        );

        $this->assertSame(['x' => '5'], $result['unknown_group']);
    }

    public function test_numeric_strings_inside_nested_group_of_array_item_are_cast(): void
    {
        // 인덱스 배열 항목 안에 다시 연관 그룹이 있는 형태
        $result = $this->dummy->normalizeCategory(
            ['rules' => [['code' => 'KRW', 'limits' => ['use_unit' => '100']]]],
            ['rules' => [['code' => 'KRW', 'limits' => ['use_unit' => 1]]]],
        );

        $this->assertSame(100, $result['rules'][0]['limits']['use_unit']);
    }
}
