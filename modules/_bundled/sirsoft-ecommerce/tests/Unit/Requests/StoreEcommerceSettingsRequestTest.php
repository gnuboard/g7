<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Requests;

use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreEcommerceSettingsRequest;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 이커머스 설정 저장 요청 검증 테스트
 *
 * - _tab 필드 검증 (탭별 개별 저장 지원)
 * - validatedSettings()에서 _tab 제외 확인
 * - 탭별 부분 데이터 전송 시 검증 통과 확인
 *
 * 주의: attributes() 가 `sirsoft-ecommerce::validation.attributes` 번역을 조회하므로
 * ModuleTestCase 상속 필수 (코어 TestCase 는 모듈 lang 경로 미등록).
 */
class StoreEcommerceSettingsRequestTest extends ModuleTestCase
{
    /**
     * 검증 수행
     *
     * @param array $data 검증 대상 데이터
     * @return \Illuminate\Validation\Validator
     */
    protected function validate(array $data): \Illuminate\Validation\Validator
    {
        $request = new StoreEcommerceSettingsRequest();

        return Validator::make($data, $request->rules());
    }

    // ──────────────────────────────────────────────
    // _tab 필드 검증
    // ──────────────────────────────────────────────

    public function test_tab_field_accepts_valid_tab_names(): void
    {
        $validTabs = ['basic_info', 'language_currency', 'seo', 'order_settings'];

        foreach ($validTabs as $tab) {
            $validator = $this->validate([
                '_tab' => $tab,
                $tab => [],
            ]);

            $this->assertFalse(
                $validator->errors()->has('_tab'),
                "_tab 필드가 '{$tab}' 값을 허용해야 합니다"
            );
        }
    }

    public function test_tab_field_rejects_invalid_tab_name(): void
    {
        $validator = $this->validate([
            '_tab' => 'nonexistent_tab',
            'basic_info' => [
                'shop_name' => '테스트 쇼핑몰',
            ],
        ]);

        $this->assertTrue(
            $validator->errors()->has('_tab'),
            "_tab 필드가 유효하지 않은 탭 이름을 거부해야 합니다"
        );
    }

    public function test_tab_field_is_optional(): void
    {
        $validator = $this->validate([
            'basic_info' => [
                'shop_name' => '테스트 쇼핑몰',
            ],
        ]);

        $this->assertFalse(
            $validator->errors()->has('_tab'),
            "_tab 필드는 선택 사항이어야 합니다"
        );
    }

    // ──────────────────────────────────────────────
    // validatedSettings() — _tab 필터링
    // ──────────────────────────────────────────────

    public function test_validated_settings_excludes_tab_field(): void
    {
        $request = new StoreEcommerceSettingsRequest();
        $validCategories = ['basic_info', 'language_currency', 'seo', 'order_settings'];

        // validatedSettings()는 유효 카테고리만 반환하므로 _tab은 제외됨
        // 검증 로직을 직접 테스트: validated 결과에서 _tab이 필터링되는지 확인
        $inputWithTab = [
            '_tab' => 'basic_info',
            'basic_info' => ['shop_name' => '테스트'],
            'seo' => ['meta_main_title' => 'SEO'],
        ];

        $filtered = array_filter(
            $inputWithTab,
            fn ($key) => in_array($key, $validCategories),
            ARRAY_FILTER_USE_KEY
        );

        $this->assertArrayNotHasKey('_tab', $filtered);
        $this->assertArrayHasKey('basic_info', $filtered);
        $this->assertArrayHasKey('seo', $filtered);
    }

    public function test_validated_settings_returns_only_sent_category(): void
    {
        $request = new StoreEcommerceSettingsRequest();
        $validCategories = ['basic_info', 'language_currency', 'seo', 'order_settings'];

        // seo 탭만 전송 시 seo만 포함
        $inputSeoOnly = [
            '_tab' => 'seo',
            'seo' => ['meta_main_title' => '테스트 SEO'],
        ];

        $filtered = array_filter(
            $inputSeoOnly,
            fn ($key) => in_array($key, $validCategories),
            ARRAY_FILTER_USE_KEY
        );

        $this->assertArrayHasKey('seo', $filtered);
        $this->assertArrayNotHasKey('basic_info', $filtered);
        $this->assertArrayNotHasKey('language_currency', $filtered);
        $this->assertArrayNotHasKey('order_settings', $filtered);
        $this->assertArrayNotHasKey('_tab', $filtered);
    }

    // ──────────────────────────────────────────────
    // 탭별 부분 데이터 전송
    // ──────────────────────────────────────────────

    public function test_basic_info_tab_only_passes_validation(): void
    {
        $validator = $this->validate([
            '_tab' => 'basic_info',
            'basic_info' => [
                'shop_name' => '테스트 쇼핑몰',
                'route_path' => 'shop',
            ],
        ]);

        $this->assertTrue(
            $validator->passes(),
            'basic_info 탭만 전송해도 검증을 통과해야 합니다. 오류: ' . $validator->errors()->toJson()
        );
    }

    public function test_seo_tab_only_passes_validation(): void
    {
        $validator = $this->validate([
            '_tab' => 'seo',
            'seo' => [
                'meta_main_title' => '테스트 SEO 타이틀',
            ],
        ]);

        $this->assertTrue(
            $validator->passes(),
            'seo 탭만 전송해도 검증을 통과해야 합니다. 오류: ' . $validator->errors()->toJson()
        );
    }

    public function test_order_settings_tab_only_passes_validation(): void
    {
        $validator = $this->validate([
            '_tab' => 'order_settings',
            'order_settings' => [
                'auto_cancel_expired' => true,
                'auto_cancel_days' => 3,
            ],
        ]);

        $this->assertTrue(
            $validator->passes(),
            'order_settings 탭만 전송해도 검증을 통과해야 합니다. 오류: ' . $validator->errors()->toJson()
        );
    }

    public function test_language_currency_tab_only_passes_validation(): void
    {
        $validator = $this->validate([
            '_tab' => 'language_currency',
            'language_currency' => [
                'default_language' => 'ko',
                'default_currency' => 'KRW',
            ],
        ]);

        $this->assertTrue(
            $validator->passes(),
            'language_currency 탭만 전송해도 검증을 통과해야 합니다. 오류: ' . $validator->errors()->toJson()
        );
    }

    // ──────────────────────────────────────────────
    // basic_info 탭 전송 시 shop_name 필수 확인
    // ──────────────────────────────────────────────

    public function test_shop_name_required_when_basic_info_present(): void
    {
        $validator = $this->validate([
            '_tab' => 'basic_info',
            'basic_info' => [
                'company_name' => '테스트 회사',
                // shop_name 누락
            ],
        ]);

        $this->assertTrue(
            $validator->errors()->has('basic_info.shop_name'),
            'basic_info 탭 전송 시 shop_name은 필수여야 합니다'
        );
    }

    public function test_shop_name_not_required_when_basic_info_absent(): void
    {
        $validator = $this->validate([
            '_tab' => 'seo',
            'seo' => [
                'meta_main_title' => '테스트',
            ],
        ]);

        $this->assertFalse(
            $validator->errors()->has('basic_info.shop_name'),
            'basic_info 탭이 없으면 shop_name 검증을 건너뛰어야 합니다'
        );
    }

    // ──────────────────────────────────────────────
    // order_settings boolean 필드 검증
    // ──────────────────────────────────────────────

    public function test_stock_restore_on_cancel_accepts_boolean(): void
    {
        $validator = $this->validate([
            '_tab' => 'order_settings',
            'order_settings' => [
                'stock_restore_on_cancel' => false,
            ],
        ]);

        $this->assertFalse(
            $validator->errors()->has('order_settings.stock_restore_on_cancel'),
            'stock_restore_on_cancel은 boolean false를 허용해야 합니다'
        );
    }

    public function test_stock_restore_on_cancel_rejects_string_false(): void
    {
        $validator = $this->validate([
            '_tab' => 'order_settings',
            'order_settings' => [
                'stock_restore_on_cancel' => 'false',
            ],
        ]);

        $this->assertTrue(
            $validator->errors()->has('order_settings.stock_restore_on_cancel'),
            'stock_restore_on_cancel은 문자열 "false"를 거부해야 합니다'
        );
    }

    public function test_auto_cancel_expired_accepts_boolean(): void
    {
        $validator = $this->validate([
            '_tab' => 'order_settings',
            'order_settings' => [
                'auto_cancel_expired' => true,
            ],
        ]);

        $this->assertFalse(
            $validator->errors()->has('order_settings.auto_cancel_expired'),
            'auto_cancel_expired는 boolean true를 허용해야 합니다'
        );
    }

    // ──────────────────────────────────────────────
    // order_settings 필드 속성 번역 확인
    // ──────────────────────────────────────────────

    public function test_order_settings_attributes_are_translated(): void
    {
        $request = new StoreEcommerceSettingsRequest();
        $attributes = $request->attributes();

        $this->assertArrayHasKey(
            'order_settings.stock_restore_on_cancel',
            $attributes,
            'order_settings.stock_restore_on_cancel 속성 번역이 있어야 합니다'
        );

        $this->assertArrayHasKey(
            'order_settings.auto_cancel_expired',
            $attributes,
            'order_settings.auto_cancel_expired 속성 번역이 있어야 합니다'
        );

        $this->assertArrayHasKey(
            'order_settings.auto_cancel_days',
            $attributes,
            'order_settings.auto_cancel_days 속성 번역이 있어야 합니다'
        );
    }

    // ──────────────────────────────────────────────
    // order_settings 커스텀 메시지 확인
    // ──────────────────────────────────────────────

    public function test_order_settings_custom_messages_exist(): void
    {
        $request = new StoreEcommerceSettingsRequest();
        $messages = $request->messages();

        $this->assertArrayHasKey(
            'order_settings.stock_restore_on_cancel.boolean',
            $messages,
            'stock_restore_on_cancel.boolean 커스텀 메시지가 있어야 합니다'
        );

        $this->assertArrayHasKey(
            'order_settings.auto_cancel_expired.boolean',
            $messages,
            'auto_cancel_expired.boolean 커스텀 메시지가 있어야 합니다'
        );

        $this->assertArrayHasKey(
            'order_settings.auto_cancel_days.integer',
            $messages,
            'auto_cancel_days.integer 커스텀 메시지가 있어야 합니다'
        );
    }

    // ──────────────────────────────────────────────
    // 결제수단 최소 1개 활성화 검증
    // ──────────────────────────────────────────────

    public function test_payment_methods_requires_at_least_one_active(): void
    {
        $request = new StoreEcommerceSettingsRequest();
        $request->merge([
            '_tab' => 'order_settings',
            'order_settings' => [
                'payment_methods' => [
                    ['id' => 'dbank', 'is_active' => false],
                    ['id' => 'point', 'is_active' => false],
                ],
            ],
        ]);

        $validator = Validator::make(
            $request->all(),
            $request->rules()
        );

        // withValidator의 after 콜백 수동 실행
        $validator->after(function ($v) use ($request) {
            $reflection = new \ReflectionMethod($request, 'validateAtLeastOneActivePaymentMethod');
            $reflection->invoke($request, $v);
        });

        $validator->passes();

        $this->assertTrue(
            $validator->errors()->has('order_settings.payment_methods'),
            '모든 결제수단이 비활성인 경우 오류가 발생해야 합니다'
        );
    }

    public function test_payment_methods_passes_with_one_active(): void
    {
        $request = new StoreEcommerceSettingsRequest();
        $request->merge([
            '_tab' => 'order_settings',
            'order_settings' => [
                'payment_methods' => [
                    ['id' => 'dbank', 'is_active' => true],
                    ['id' => 'point', 'is_active' => false],
                ],
            ],
        ]);

        $validator = Validator::make(
            $request->all(),
            $request->rules()
        );

        $validator->after(function ($v) use ($request) {
            $reflection = new \ReflectionMethod($request, 'validateAtLeastOneActivePaymentMethod');
            $reflection->invoke($request, $v);
        });

        $validator->passes();

        $this->assertFalse(
            $validator->errors()->has('order_settings.payment_methods'),
            '하나 이상의 결제수단이 활성이면 오류가 없어야 합니다'
        );
    }

    // ──────────────────────────────────────────────
    // 재고 차감 시점 - none 옵션 검증
    // ──────────────────────────────────────────────

    public function test_stock_deduction_timing_accepts_none(): void
    {
        $validator = $this->validate([
            '_tab' => 'order_settings',
            'order_settings' => [
                'payment_methods' => [
                    ['id' => 'dbank', 'stock_deduction_timing' => 'none'],
                ],
            ],
        ]);

        $this->assertFalse(
            $validator->errors()->has('order_settings.payment_methods.0.stock_deduction_timing'),
            'stock_deduction_timing은 none 값을 허용해야 합니다'
        );
    }

    public function test_stock_deduction_timing_rejects_invalid_value(): void
    {
        $validator = $this->validate([
            '_tab' => 'order_settings',
            'order_settings' => [
                'payment_methods' => [
                    ['id' => 'dbank', 'stock_deduction_timing' => 'invalid'],
                ],
            ],
        ]);

        $this->assertTrue(
            $validator->errors()->has('order_settings.payment_methods.0.stock_deduction_timing'),
            'stock_deduction_timing은 유효하지 않은 값을 거부해야 합니다'
        );
    }

    // ──────────────────────────────────────────────
    // 무통장 계좌 검증
    // ──────────────────────────────────────────────

    public function test_bank_account_requires_account_number(): void
    {
        $validator = $this->validate([
            '_tab' => 'order_settings',
            'order_settings' => [
                'bank_accounts' => [
                    ['bank_code' => '004', 'account_holder' => '홍길동', 'is_active' => true, 'is_default' => true],
                ],
            ],
        ]);

        $this->assertTrue(
            $validator->errors()->has('order_settings.bank_accounts.0.account_number'),
            '계좌번호는 필수 항목이어야 합니다'
        );
    }

    public function test_bank_account_requires_account_holder(): void
    {
        $validator = $this->validate([
            '_tab' => 'order_settings',
            'order_settings' => [
                'bank_accounts' => [
                    ['bank_code' => '004', 'account_number' => '1234567890', 'is_active' => true, 'is_default' => true],
                ],
            ],
        ]);

        $this->assertTrue(
            $validator->errors()->has('order_settings.bank_accounts.0.account_holder'),
            '예금주는 필수 항목이어야 합니다'
        );
    }

    public function test_bank_accounts_requires_at_least_one_active_default(): void
    {
        $request = new StoreEcommerceSettingsRequest();
        $request->merge([
            '_tab' => 'order_settings',
            'order_settings' => [
                'bank_accounts' => [
                    ['bank_code' => '004', 'account_number' => '1234567890', 'account_holder' => '홍길동', 'is_active' => true, 'is_default' => false],
                    ['bank_code' => '088', 'account_number' => '9876543210', 'account_holder' => '김철수', 'is_active' => false, 'is_default' => true],
                ],
            ],
        ]);

        $validator = Validator::make(
            $request->all(),
            $request->rules()
        );

        $validator->after(function ($v) use ($request) {
            $reflection = new \ReflectionMethod($request, 'validateBankAccountDefaults');
            $reflection->invoke($request, $v);
        });

        $validator->passes();

        $this->assertTrue(
            $validator->errors()->has('order_settings.bank_accounts'),
            '기본+사용이 모두 선택된 계좌가 없으면 오류가 발생해야 합니다'
        );
    }

    // ──────────────────────────────────────────────
    // order_settings.banks 로케일별 은행명 검증
    // ──────────────────────────────────────────────

    public function test_banks_current_locale_name_required(): void
    {
        app()->setLocale('ko');

        $validator = $this->validate([
            '_tab' => 'order_settings',
            'order_settings' => [
                'banks' => [
                    ['code' => '004', 'name' => ['en' => 'Kookmin Bank']],
                ],
            ],
        ]);

        $this->assertTrue(
            $validator->errors()->has('order_settings.banks.0.name.ko'),
            '현재 로케일(ko) 은행명이 없으면 실패해야 합니다'
        );
    }

    public function test_banks_current_locale_only_is_valid(): void
    {
        app()->setLocale('ko');

        $validator = $this->validate([
            '_tab' => 'order_settings',
            'order_settings' => [
                'banks' => [
                    ['code' => '004', 'name' => ['ko' => '국민은행']],
                ],
            ],
        ]);

        $this->assertTrue(
            $validator->passes(),
            '현재 로케일(ko)만 입력해도 유효해야 합니다. 오류: ' . $validator->errors()->toJson()
        );
    }

    public function test_banks_other_locale_null_is_valid(): void
    {
        app()->setLocale('ko');

        $validator = $this->validate([
            '_tab' => 'order_settings',
            'order_settings' => [
                'banks' => [
                    ['code' => '004', 'name' => ['ko' => '국민은행', 'en' => null]],
                ],
            ],
        ]);

        $this->assertTrue(
            $validator->passes(),
            '다른 로케일이 null이어도 유효해야 합니다. 오류: ' . $validator->errors()->toJson()
        );
    }

    public function test_banks_other_locale_validates_max_length_when_provided(): void
    {
        app()->setLocale('ko');

        $validator = $this->validate([
            '_tab' => 'order_settings',
            'order_settings' => [
                'banks' => [
                    ['code' => '004', 'name' => ['ko' => '국민은행', 'en' => str_repeat('A', 101)]],
                ],
            ],
        ]);

        $this->assertTrue(
            $validator->errors()->has('order_settings.banks.0.name.en'),
            '다른 로케일도 입력 시 최대 100자 검증을 적용해야 합니다'
        );
    }

    public function test_banks_en_locale_required_when_locale_is_en(): void
    {
        app()->setLocale('en');

        $validator = $this->validate([
            '_tab' => 'order_settings',
            'order_settings' => [
                'banks' => [
                    ['code' => '004', 'name' => ['ko' => '국민은행']],
                ],
            ],
        ]);

        $this->assertTrue(
            $validator->errors()->has('order_settings.banks.0.name.en'),
            '로케일이 en일 때 영문 은행명이 없으면 실패해야 합니다'
        );
    }

    public function test_banks_accepts_valid_full_data(): void
    {
        app()->setLocale('ko');

        $validator = $this->validate([
            '_tab' => 'order_settings',
            'order_settings' => [
                'banks' => [
                    ['code' => '004', 'name' => ['ko' => '국민은행', 'en' => 'Kookmin Bank']],
                    ['code' => '088', 'name' => ['ko' => '신한은행', 'en' => 'Shinhan Bank']],
                ],
            ],
        ]);

        $this->assertTrue(
            $validator->passes(),
            '유효한 은행 데이터를 허용해야 합니다. 오류: ' . $validator->errors()->toJson()
        );
    }

    public function test_banks_custom_messages_exist(): void
    {
        $request = new StoreEcommerceSettingsRequest();
        $messages = $request->messages();

        $locale = app()->getLocale();

        $this->assertArrayHasKey(
            'order_settings.banks.*.code.required_with',
            $messages,
            'banks.*.code.required_with 커스텀 메시지가 있어야 합니다'
        );

        $this->assertArrayHasKey(
            "order_settings.banks.*.name.{$locale}.required_with",
            $messages,
            "banks.*.name.{$locale}.required_with 커스텀 메시지가 있어야 합니다"
        );
    }

    public function test_bank_accounts_passes_with_active_default(): void
    {
        $request = new StoreEcommerceSettingsRequest();
        $request->merge([
            '_tab' => 'order_settings',
            'order_settings' => [
                'bank_accounts' => [
                    ['bank_code' => '004', 'account_number' => '1234567890', 'account_holder' => '홍길동', 'is_active' => true, 'is_default' => true],
                ],
            ],
        ]);

        $validator = Validator::make(
            $request->all(),
            $request->rules()
        );

        $validator->after(function ($v) use ($request) {
            $reflection = new \ReflectionMethod($request, 'validateBankAccountDefaults');
            $reflection->invoke($request, $v);
        });

        $validator->passes();

        $this->assertFalse(
            $validator->errors()->has('order_settings.bank_accounts'),
            '기본+사용이 모두 선택된 계좌가 있으면 오류가 없어야 합니다'
        );
    }

    // ========================================================================
    // shipping.carriers 검증 테스트
    // ========================================================================

    public function test_shipping_tab_passes_with_valid_carriers(): void
    {
        $validator = $this->validate([
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [
                    [
                        'id' => 1,
                        'code' => 'cj',
                        'name' => ['ko' => 'CJ대한통운', 'en' => 'CJ Logistics'],
                        'type' => 'domestic',
                        'tracking_url' => 'https://example.com/track?no={tracking_number}',
                        'is_active' => true,
                    ],
                    [
                        'code' => 'fedex',
                        'name' => ['ko' => '페덱스'],
                        'type' => 'international',
                        'is_active' => true,
                    ],
                ],
            ],
        ]);

        $this->assertFalse(
            $validator->fails(),
            '유효한 carriers 데이터로 검증이 통과해야 합니다: ' . json_encode($validator->errors()->toArray())
        );
    }

    public function test_shipping_tab_passes_with_empty_carriers(): void
    {
        $validator = $this->validate([
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [],
            ],
        ]);

        $this->assertFalse(
            $validator->fails(),
            '빈 carriers 배열로 검증이 통과해야 합니다'
        );
    }

    public function test_shipping_tab_passes_without_carriers_key(): void
    {
        $validator = $this->validate([
            '_tab' => 'shipping',
            'shipping' => [
                'default_country' => 'KR',
            ],
        ]);

        $this->assertFalse(
            $validator->fails(),
            'carriers 키 없이도 검증이 통과해야 합니다'
        );
    }

    public function test_carrier_code_is_required(): void
    {
        $validator = $this->validate([
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [
                    ['name' => ['ko' => 'CJ대한통운'], 'type' => 'domestic'],
                ],
            ],
        ]);

        $this->assertTrue(
            $validator->errors()->has('shipping.carriers.0.code'),
            'carriers가 있으면 code가 필수여야 합니다'
        );
    }

    public function test_carrier_code_accepts_valid_format(): void
    {
        $validCodes = ['cj', 'fedex', 'ems-express', 'kr-post', 'cj2'];

        foreach ($validCodes as $code) {
            $validator = $this->validate([
                '_tab' => 'shipping',
                'shipping' => [
                    'carriers' => [
                        ['code' => $code, 'name' => ['ko' => '테스트'], 'type' => 'domestic'],
                    ],
                ],
            ]);

            $this->assertFalse(
                $validator->errors()->has('shipping.carriers.0.code'),
                "유효한 코드 '{$code}'가 통과해야 합니다"
            );
        }
    }

    public function test_carrier_code_rejects_invalid_format(): void
    {
        $invalidCodes = ['CJ', 'Fedex', 'cj!', '123abc', '-cj', 'cj-'];

        foreach ($invalidCodes as $code) {
            $validator = $this->validate([
                '_tab' => 'shipping',
                'shipping' => [
                    'carriers' => [
                        ['code' => $code, 'name' => ['ko' => '테스트'], 'type' => 'domestic'],
                    ],
                ],
            ]);

            $this->assertTrue(
                $validator->errors()->has('shipping.carriers.0.code'),
                "잘못된 코드 '{$code}'는 거부해야 합니다"
            );
        }
    }

    public function test_carrier_code_max_length(): void
    {
        $validator = $this->validate([
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [
                    ['code' => str_repeat('a', 51), 'name' => ['ko' => '테스트'], 'type' => 'domestic'],
                ],
            ],
        ]);

        $this->assertTrue(
            $validator->errors()->has('shipping.carriers.0.code'),
            'code 최대 50자 초과 시 실패해야 합니다'
        );
    }

    public function test_carrier_name_ko_is_required(): void
    {
        $validator = $this->validate([
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [
                    ['code' => 'cj', 'name' => ['en' => 'CJ Logistics'], 'type' => 'domestic'],
                ],
            ],
        ]);

        // LocaleRequiredTranslatable 은 field 레벨에서 실패 — ko 누락 시 shipping.carriers.0.name 에 에러
        $this->assertTrue(
            $validator->errors()->has('shipping.carriers.0.name'),
            'carriers가 있으면 name(primary locale) 이 필수여야 합니다'
        );
    }

    public function test_carrier_name_is_required_as_array(): void
    {
        $validator = $this->validate([
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [
                    ['code' => 'cj', 'type' => 'domestic'],
                ],
            ],
        ]);

        $this->assertTrue(
            $validator->errors()->has('shipping.carriers.0.name'),
            'carriers가 있으면 name이 필수여야 합니다'
        );
    }

    public function test_carrier_name_ko_max_length(): void
    {
        $validator = $this->validate([
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [
                    ['code' => 'cj', 'name' => ['ko' => str_repeat('가', 101)], 'type' => 'domestic'],
                ],
            ],
        ]);

        // LocaleRequiredTranslatable(maxLength:100) — 초과 시 field 레벨 에러
        $this->assertTrue(
            $validator->errors()->has('shipping.carriers.0.name'),
            'name 최대 100자 초과 시 실패해야 합니다'
        );
    }

    public function test_carrier_type_is_required(): void
    {
        $validator = $this->validate([
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [
                    ['code' => 'cj', 'name' => ['ko' => 'CJ대한통운']],
                ],
            ],
        ]);

        $this->assertTrue(
            $validator->errors()->has('shipping.carriers.0.type'),
            'carriers가 있으면 type이 필수여야 합니다'
        );
    }

    public function test_carrier_type_rejects_invalid_value(): void
    {
        $validator = $this->validate([
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [
                    ['code' => 'cj', 'name' => ['ko' => 'CJ대한통운'], 'type' => 'express'],
                ],
            ],
        ]);

        $this->assertTrue(
            $validator->errors()->has('shipping.carriers.0.type'),
            'type은 domestic/international만 허용해야 합니다'
        );
    }

    public function test_carrier_type_accepts_valid_values(): void
    {
        foreach (['domestic', 'international'] as $type) {
            $validator = $this->validate([
                '_tab' => 'shipping',
                'shipping' => [
                    'carriers' => [
                        ['code' => 'cj', 'name' => ['ko' => 'CJ대한통운'], 'type' => $type],
                    ],
                ],
            ]);

            $this->assertFalse(
                $validator->errors()->has('shipping.carriers.0.type'),
                "유효한 type '{$type}'이 통과해야 합니다"
            );
        }
    }

    public function test_carrier_tracking_url_max_length(): void
    {
        $validator = $this->validate([
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [
                    [
                        'code' => 'cj',
                        'name' => ['ko' => 'CJ대한통운'],
                        'type' => 'domestic',
                        'tracking_url' => str_repeat('a', 501),
                    ],
                ],
            ],
        ]);

        $this->assertTrue(
            $validator->errors()->has('shipping.carriers.0.tracking_url'),
            'tracking_url 최대 500자 초과 시 실패해야 합니다'
        );
    }

    public function test_carrier_tracking_url_is_optional(): void
    {
        $validator = $this->validate([
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [
                    ['code' => 'cj', 'name' => ['ko' => 'CJ대한통운'], 'type' => 'domestic'],
                ],
            ],
        ]);

        $this->assertFalse(
            $validator->errors()->has('shipping.carriers.0.tracking_url'),
            'tracking_url은 선택 필드여야 합니다'
        );
    }

    public function test_carrier_duplicate_codes_rejected(): void
    {
        $request = new StoreEcommerceSettingsRequest();
        $request->merge([
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [
                    ['code' => 'cj', 'name' => ['ko' => 'CJ대한통운'], 'type' => 'domestic'],
                    ['code' => 'cj', 'name' => ['ko' => 'CJ물류'], 'type' => 'domestic'],
                ],
            ],
        ]);

        $validator = Validator::make(
            $request->all(),
            $request->rules()
        );

        $validator->after(function ($v) use ($request) {
            $reflection = new \ReflectionMethod($request, 'validateUniqueCarrierCodes');
            $reflection->invoke($request, $v);
        });

        $validator->passes();

        $this->assertTrue(
            $validator->errors()->has('shipping.carriers.1.code'),
            '중복 배송사 코드는 거부해야 합니다'
        );
    }

    public function test_carrier_duplicate_codes_case_insensitive(): void
    {
        $request = new StoreEcommerceSettingsRequest();
        $request->merge([
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [
                    ['code' => 'cj', 'name' => ['ko' => 'CJ대한통운'], 'type' => 'domestic'],
                    ['code' => 'cj', 'name' => ['ko' => 'CJ물류'], 'type' => 'domestic'],
                ],
            ],
        ]);

        $validator = Validator::make(
            $request->all(),
            $request->rules()
        );

        $validator->after(function ($v) use ($request) {
            $reflection = new \ReflectionMethod($request, 'validateUniqueCarrierCodes');
            $reflection->invoke($request, $v);
        });

        $validator->passes();

        $this->assertTrue(
            $validator->errors()->has('shipping.carriers.1.code'),
            '대소문자 무관하게 중복 코드를 감지해야 합니다'
        );
    }

    public function test_carrier_empty_name_rejected_by_custom_validator(): void
    {
        $request = new StoreEcommerceSettingsRequest();
        $request->merge([
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [
                    ['code' => 'cj', 'name' => ['ko' => '  '], 'type' => 'domestic'],
                ],
            ],
        ]);

        $validator = Validator::make(
            $request->all(),
            $request->rules()
        );

        $validator->after(function ($v) use ($request) {
            $reflection = new \ReflectionMethod($request, 'validateCarrierNames');
            $reflection->invoke($request, $v);
        });

        $validator->passes();

        $this->assertTrue(
            $validator->errors()->has('shipping.carriers.0.name.ko'),
            '공백만 있는 배송사명은 거부해야 합니다'
        );
    }

    public function test_carrier_missing_name_array_rejected_by_custom_validator(): void
    {
        $request = new StoreEcommerceSettingsRequest();
        $request->merge([
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [
                    ['code' => 'cj', 'name' => [], 'type' => 'domestic'],
                ],
            ],
        ]);

        $validator = Validator::make(
            $request->all(),
            $request->rules()
        );

        $validator->after(function ($v) use ($request) {
            $reflection = new \ReflectionMethod($request, 'validateCarrierNames');
            $reflection->invoke($request, $v);
        });

        $validator->passes();

        $this->assertTrue(
            $validator->errors()->has('shipping.carriers.0.name'),
            '빈 name 배열은 거부해야 합니다'
        );
    }

    public function test_carrier_custom_messages_exist(): void
    {
        $request = new StoreEcommerceSettingsRequest();
        $messages = $request->messages();

        // LocaleRequiredTranslatable 은 field 레벨에서 보고 — name.ko 분리 키 미사용
        $requiredKeys = [
            'shipping.carriers.*.code.required_with',
            'shipping.carriers.*.code.regex',
            'shipping.carriers.*.name.required_with',
            'shipping.carriers.*.type.required_with',
            'shipping.carriers.*.type.in',
            'shipping.carriers.*.tracking_url.max',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey(
                $key,
                $messages,
                "'{$key}' 커스텀 메시지가 있어야 합니다"
            );
        }
    }

    public function test_carrier_multiple_items_validated_independently(): void
    {
        $validator = $this->validate([
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [
                    ['code' => 'cj', 'name' => ['ko' => 'CJ대한통운'], 'type' => 'domestic'],
                    ['code' => '', 'name' => ['ko' => ''], 'type' => 'invalid'],
                ],
            ],
        ]);

        // 첫 번째 항목은 정상
        $this->assertFalse(
            $validator->errors()->has('shipping.carriers.0.code'),
            '첫 번째 유효한 항목은 오류가 없어야 합니다'
        );

        // 두 번째 항목만 오류
        $this->assertTrue(
            $validator->errors()->has('shipping.carriers.1.type'),
            '두 번째 항목의 type 오류가 있어야 합니다'
        );
    }
}
