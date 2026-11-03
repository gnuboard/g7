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

    /**
     * 트레이트의 전체 설정 정규화(normalizeSettingsData)를 시험에서 부를 수 있게 드러낸다.
     *
     * @param  array  $settings  저장된 설정 값
     * @param  array  $defaults  defaults 스키마
     * @return array 스키마 타입으로 정규화한 설정
     */
    public function normalize(array $settings, array $defaults): array
    {
        return $this->normalizeSettingsData($settings, $defaults);
    }

    /**
     * 트레이트의 카테고리 단위 정규화(normalizeCategoryData)를 시험에서 부를 수 있게 드러낸다.
     *
     * @param  array  $settings  저장된 카테고리 설정 값
     * @param  array  $defaults  카테고리 defaults 스키마
     * @return array 스키마 타입으로 정규화한 카테고리 설정
     */
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
 */
class NormalizesSettingsDataTest extends TestCase
{
    private NormalizesSettingsDataDummy $dummy;

    /**
     * 트레이트를 노출한 더미 객체를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->dummy = new NormalizesSettingsDataDummy;
    }

    // ──────────────────────────────────────────────
    // 스칼라 숫자 정규화 (int)
    // ──────────────────────────────────────────────

    /**
     * 기본값이 정수인 키에 저장된 숫자 문자열이 정수로 캐스트되는지 확인한다.
     *
     * @effects settings_read_returns_schema_scalar_type
     *
     * @return void 반환값 없음
     */
    public function test_numeric_string_is_cast_to_int_when_default_is_int(): void
    {
        $result = $this->dummy->normalizeCategory(
            ['auto_cancel_days' => '5'],
            ['auto_cancel_days' => 3],
        );

        $this->assertSame(5, $result['auto_cancel_days']);
    }

    /**
     * 앞에 0 이 붙은 숫자 문자열도 기본값이 정수이면 정수로 캐스트되는지 확인한다.
     *
     * @scenario value_shape=scalar_int
     *
     * @effects settings_read_returns_schema_scalar_type
     *
     * @return void 반환값 없음
     */
    public function test_zero_padded_numeric_string_is_cast_to_int(): void
    {
        $result = $this->dummy->normalizeCategory(
            ['auto_cancel_days' => '007'],
            ['auto_cancel_days' => 3],
        );

        $this->assertSame(7, $result['auto_cancel_days']);
    }

    /**
     * 전체 정규화가 카테고리 아래 설정까지 내려가 숫자 문자열을 정수로 캐스트하는지 확인한다.
     *
     * @effects settings_read_returns_schema_scalar_type
     *
     * @return void 반환값 없음
     */
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

    /**
     * 기본값이 실수인 키에 저장된 소수 문자열이 실수로 캐스트되는지 확인한다.
     *
     * @scenario value_shape=scalar_float
     *
     * @effects settings_read_returns_schema_scalar_type
     *
     * @return void 반환값 없음
     */
    public function test_numeric_string_is_cast_to_float_when_default_is_float(): void
    {
        $result = $this->dummy->normalizeCategory(
            ['point_value' => '0.5'],
            ['point_value' => 1.0],
        );

        $this->assertSame(0.5, $result['point_value']);
    }

    /**
     * 기본값이 실수이면 정수 모양의 문자열도 실수로 캐스트되는지 확인한다.
     *
     * @scenario value_shape=scalar_float
     *
     * @effects settings_read_returns_schema_scalar_type
     *
     * @return void 반환값 없음
     */
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

    /**
     * 통화 목록처럼 인덱스 배열 항목 안의 숫자 문자열이 첫 항목 기본값의 타입으로 캐스트되는지 확인한다.
     *
     * @scenario value_shape=indexed_array_item
     *
     * @effects numeric_strings_inside_nested_settings_are_cast
     *
     * @return void 반환값 없음
     */
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

    /**
     * 기본값이 정수여도 숫자가 아닌 문자열은 그대로 두는지 확인한다.
     *
     * @scenario value_shape=untouched
     *
     * @effects non_castable_values_are_left_untouched
     *
     * @return void 반환값 없음
     */
    public function test_non_numeric_string_is_left_untouched(): void
    {
        $result = $this->dummy->normalizeCategory(
            ['auto_cancel_days' => 'abc'],
            ['auto_cancel_days' => 3],
        );

        $this->assertSame('abc', $result['auto_cancel_days']);
    }

    /**
     * 빈 문자열은 숫자로 캐스트하지 않고 그대로 두는지 확인한다.
     *
     * @scenario value_shape=untouched
     *
     * @effects non_castable_values_are_left_untouched
     *
     * @return void 반환값 없음
     */
    public function test_empty_string_is_left_untouched(): void
    {
        $result = $this->dummy->normalizeCategory(
            ['auto_cancel_days' => ''],
            ['auto_cancel_days' => 3],
        );

        $this->assertSame('', $result['auto_cancel_days']);
    }

    /**
     * null 값은 캐스트하지 않고 그대로 두는지 확인한다.
     *
     * @scenario value_shape=untouched
     *
     * @effects non_castable_values_are_left_untouched
     *
     * @return void 반환값 없음
     */
    public function test_null_is_left_untouched(): void
    {
        $result = $this->dummy->normalizeCategory(
            ['auto_cancel_days' => null],
            ['auto_cancel_days' => 3],
        );

        $this->assertNull($result['auto_cancel_days']);
    }

    /**
     * 기본값이 불리언인 키의 문자열 1 은 정수로 바뀌지 않는지 확인한다.
     *
     * @scenario value_shape=untouched
     *
     * @effects non_castable_values_are_left_untouched
     *
     * @return void 반환값 없음
     */
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

    /**
     * 기본값이 문자열인 키는 숫자처럼 보이는 값도 문자열로 유지하는지 확인한다.
     *
     * @scenario value_shape=untouched
     *
     * @effects non_castable_values_are_left_untouched
     *
     * @return void 반환값 없음
     */
    public function test_string_default_keeps_numeric_string_as_string(): void
    {
        // 기본값이 문자열이면(예: 사업자번호 조각) 숫자처럼 보여도 문자열을 유지해야 한다.
        $result = $this->dummy->normalizeCategory(
            ['business_number_1' => '007'],
            ['business_number_1' => ''],
        );

        $this->assertSame('007', $result['business_number_1']);
    }

    /**
     * defaults 에 없는 키의 값은 손대지 않는지 확인한다.
     *
     * @scenario value_shape=untouched
     *
     * @effects non_castable_values_are_left_untouched
     *
     * @return void 반환값 없음
     */
    public function test_default_absent_key_is_left_untouched(): void
    {
        $result = $this->dummy->normalizeCategory(
            ['unknown_key' => '5'],
            [],
        );

        $this->assertSame('5', $result['unknown_key']);
    }

    /**
     * 이미 정수인 값은 정수 그대로 남는지 확인한다.
     *
     * @scenario value_shape=untouched
     *
     * @effects non_castable_values_are_left_untouched
     *
     * @return void 반환값 없음
     */
    public function test_int_value_stays_int(): void
    {
        $result = $this->dummy->normalizeCategory(
            ['auto_cancel_days' => 5],
            ['auto_cancel_days' => 3],
        );

        $this->assertSame(5, $result['auto_cancel_days']);
    }

    /**
     * 기본값이 다국어 맵이고 저장값이 문자열이면 기존처럼 로케일마다 같은 값을 가진 맵으로 바뀌는지 확인한다.
     *
     * @effects multilingual_settings_are_not_broken_by_normalization
     *
     * @return void 반환값 없음
     */
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

    /**
     * 카테고리 아래 한 단계 더 깊은 연관 배열 그룹 안의 숫자 문자열도 캐스트되는지 확인한다.
     *
     * @scenario value_shape=associative_group
     *
     * @effects numeric_strings_inside_nested_settings_are_cast
     *
     * @return void 반환값 없음
     */
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

    /**
     * 여러 단계로 중첩된 연관 배열 그룹도 재귀로 내려가 캐스트되는지 확인한다.
     *
     * @scenario value_shape=associative_group
     *
     * @effects numeric_strings_inside_nested_settings_are_cast
     *
     * @return void 반환값 없음
     */
    public function test_associative_group_normalization_is_recursive(): void
    {
        $result = $this->dummy->normalizeCategory(
            ['a' => ['b' => ['c' => '7']]],
            ['a' => ['b' => ['c' => 1]]],
        );

        $this->assertSame(7, $result['a']['b']['c']);
    }

    /**
     * 그룹 재귀가 다국어 맵을 만나도 기본값이 문자열이라 숫자 모양 값을 캐스트하지 않는지 확인한다.
     *
     * @effects multilingual_settings_are_not_broken_by_normalization
     *
     * @return void 반환값 없음
     */
    public function test_multilingual_map_is_untouched_by_group_recursion(): void
    {
        // 다국어 필드도 연관 배열이지만 기본값이 문자열이라 캐스트 대상이 아니다.
        $result = $this->dummy->normalizeCategory(
            ['shop_name' => ['ko' => '5', 'en' => '5']],
            ['shop_name' => ['ko' => '쇼핑몰', 'en' => 'Shop']],
        );

        $this->assertSame(['ko' => '5', 'en' => '5'], $result['shop_name']);
    }

    /**
     * defaults 에 없는 그룹은 안의 값까지 그대로 두는지 확인한다.
     *
     * @scenario value_shape=untouched
     *
     * @effects non_castable_values_are_left_untouched
     *
     * @return void 반환값 없음
     */
    public function test_group_without_matching_default_is_untouched(): void
    {
        $result = $this->dummy->normalizeCategory(
            ['unknown_group' => ['x' => '5']],
            [],
        );

        $this->assertSame(['x' => '5'], $result['unknown_group']);
    }

    /**
     * 인덱스 배열 항목 안에 다시 들어 있는 연관 그룹의 숫자 문자열도 캐스트되는지 확인한다.
     *
     * @scenario value_shape=indexed_array_item
     *
     * @effects numeric_strings_inside_nested_settings_are_cast
     *
     * @return void 반환값 없음
     */
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
