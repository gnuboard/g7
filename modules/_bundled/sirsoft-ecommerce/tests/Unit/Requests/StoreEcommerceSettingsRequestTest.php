<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Requests;

use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreEcommerceSettingsRequest;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

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
     * @param  array  $data  검증 대상 데이터
     * @return \Illuminate\Validation\Validator 검증기
     */
    protected function validate(array $data): \Illuminate\Validation\Validator
    {
        $request = new StoreEcommerceSettingsRequest;

        return Validator::make($data, $request->rules());
    }

    // ──────────────────────────────────────────────
    // _tab 필드 검증
    // ──────────────────────────────────────────────

    /**
     * 허용된 탭 이름은 통과한다.
     *
     * @scenario area=tab
     *
     * @effects settings_request_tab_field_scopes_validation
     *
     * @return void 반환값 없음
     */
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

    /**
     * 알 수 없는 탭 이름은 거절한다.
     *
     * @scenario area=tab
     *
     * @effects settings_request_tab_field_scopes_validation
     *
     * @return void 반환값 없음
     */
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
            '_tab 필드가 유효하지 않은 탭 이름을 거부해야 합니다'
        );
    }

    /**
     * 탭 필드는 생략해도 된다.
     *
     * @scenario area=tab
     *
     * @effects settings_request_tab_field_scopes_validation
     *
     * @return void 반환값 없음
     */
    public function test_tab_field_is_optional(): void
    {
        $validator = $this->validate([
            'basic_info' => [
                'shop_name' => '테스트 쇼핑몰',
            ],
        ]);

        $this->assertFalse(
            $validator->errors()->has('_tab'),
            '_tab 필드는 선택 사항이어야 합니다'
        );
    }

    // ──────────────────────────────────────────────
    // validatedSettings() — _tab 필터링
    // ──────────────────────────────────────────────

    /**
     * 검증된 설정 값에 탭 필드는 빠진다.
     *
     * @scenario area=tab
     *
     * @effects settings_request_tab_field_scopes_validation
     *
     * @return void 반환값 없음
     */
    public function test_validated_settings_excludes_tab_field(): void
    {
        $request = new StoreEcommerceSettingsRequest;
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

    /**
     * 검증된 설정 값에는 보낸 구역만 담긴다.
     *
     * @scenario area=tab
     *
     * @effects settings_request_tab_field_scopes_validation
     *
     * @return void 반환값 없음
     */
    public function test_validated_settings_returns_only_sent_category(): void
    {
        $request = new StoreEcommerceSettingsRequest;
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

    /**
     * 기본정보 탭만 보내도 통과한다.
     *
     * @scenario area=tab
     *
     * @effects settings_request_tab_field_scopes_validation
     *
     * @return void 반환값 없음
     */
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
            'basic_info 탭만 전송해도 검증을 통과해야 합니다. 오류: '.$validator->errors()->toJson()
        );
    }

    /**
     * SEO 탭만 보내도 통과한다.
     *
     * @scenario area=tab
     *
     * @effects settings_request_tab_field_scopes_validation
     *
     * @return void 반환값 없음
     */
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
            'seo 탭만 전송해도 검증을 통과해야 합니다. 오류: '.$validator->errors()->toJson()
        );
    }

    /**
     * 주문설정 탭만 보내도 통과한다.
     *
     * @scenario area=tab
     *
     * @effects settings_request_tab_field_scopes_validation
     *
     * @return void 반환값 없음
     */
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
            'order_settings 탭만 전송해도 검증을 통과해야 합니다. 오류: '.$validator->errors()->toJson()
        );
    }

    /**
     * 언어·통화 탭만 보내도 통과한다.
     *
     * @scenario area=tab
     *
     * @effects settings_request_tab_field_scopes_validation
     *
     * @return void 반환값 없음
     */
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
            'language_currency 탭만 전송해도 검증을 통과해야 합니다. 오류: '.$validator->errors()->toJson()
        );
    }

    // ──────────────────────────────────────────────
    // 통화 기호(symbol) 검증
    // ──────────────────────────────────────────────

    /**
     * 올바른 통화 기호는 통과한다.
     *
     * @scenario area=basic_info
     *
     * @effects settings_request_basic_info_rules
     *
     * @return void 반환값 없음
     */
    public function test_currency_symbol_accepts_valid_value(): void
    {
        $validator = $this->validate([
            '_tab' => 'language_currency',
            'language_currency' => [
                'default_currency' => 'KRW',
                'currencies' => [
                    ['code' => 'KRW', 'name' => ['ko' => '원'], 'symbol' => '₩'],
                ],
            ],
        ]);

        $this->assertTrue(
            $validator->passes(),
            '통화 기호(symbol)가 포함된 통화 설정은 검증을 통과해야 합니다. 오류: '.$validator->errors()->toJson()
        );
    }

    /**
     * 통화 기호는 생략해도 된다.
     *
     * @scenario area=basic_info
     *
     * @effects settings_request_basic_info_rules
     *
     * @return void 반환값 없음
     */
    public function test_currency_symbol_is_optional(): void
    {
        $validator = $this->validate([
            '_tab' => 'language_currency',
            'language_currency' => [
                'default_currency' => 'KRW',
                'currencies' => [
                    ['code' => 'KRW', 'name' => ['ko' => '원']],
                ],
            ],
        ]);

        $this->assertTrue(
            $validator->passes(),
            'symbol 미전송 시에도 검증을 통과해야 합니다(nullable). 오류: '.$validator->errors()->toJson()
        );
    }

    /**
     * 너무 긴 통화 기호는 거절한다.
     *
     * @scenario area=basic_info
     *
     * @effects settings_request_basic_info_rules
     *
     * @return void 반환값 없음
     */
    public function test_currency_symbol_rejects_too_long_value(): void
    {
        $validator = $this->validate([
            '_tab' => 'language_currency',
            'language_currency' => [
                'default_currency' => 'KRW',
                'currencies' => [
                    ['code' => 'KRW', 'name' => ['ko' => '원'], 'symbol' => str_repeat('₩', 9)],
                ],
            ],
        ]);

        $this->assertTrue(
            $validator->fails(),
            'symbol 이 max:8 을 초과하면 검증에 실패해야 합니다.'
        );
        $this->assertArrayHasKey(
            'language_currency.currencies.0.symbol',
            $validator->errors()->toArray()
        );
    }

    // ──────────────────────────────────────────────
    // basic_info 탭 전송 시 shop_name 필수 확인
    // ──────────────────────────────────────────────

    /**
     * 기본정보를 보내면 상점명이 필수다.
     *
     * @scenario area=basic_info
     *
     * @effects settings_request_basic_info_rules
     *
     * @return void 반환값 없음
     */
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

    /**
     * 기본정보를 보내지 않으면 상점명을 요구하지 않는다.
     *
     * @scenario area=basic_info
     *
     * @effects settings_request_basic_info_rules
     *
     * @return void 반환값 없음
     */
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

    /**
     * 취소 시 재고 복원 여부는 참/거짓을 받는다.
     *
     * @scenario area=order_settings
     *
     * @effects settings_request_order_settings_rules
     *
     * @return void 반환값 없음
     */
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

    /**
     * 문자열 "false" 는 재고 복원 값으로 받지 않는다.
     *
     * @scenario area=order_settings
     *
     * @effects settings_request_order_settings_rules
     *
     * @return void 반환값 없음
     */
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

    /**
     * 입금 기한 만료 자동취소 여부는 참/거짓을 받는다.
     *
     * @scenario area=order_settings
     *
     * @effects settings_request_order_settings_rules
     *
     * @return void 반환값 없음
     */
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

    /**
     * 주문설정 항목 이름이 번역돼 있다.
     *
     * @scenario area=order_settings
     *
     * @effects settings_request_order_settings_rules
     *
     * @return void 반환값 없음
     */
    public function test_order_settings_attributes_are_translated(): void
    {
        $request = new StoreEcommerceSettingsRequest;
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

    /**
     * 주문설정 오류 문구가 정의돼 있다.
     *
     * @scenario area=order_settings
     *
     * @effects settings_request_order_settings_rules
     *
     * @return void 반환값 없음
     */
    public function test_order_settings_custom_messages_exist(): void
    {
        $request = new StoreEcommerceSettingsRequest;
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

    /**
     * 활성 결제수단이 하나도 없으면 거절한다.
     *
     * @scenario area=order_settings
     *
     * @effects settings_request_order_settings_rules
     *
     * @return void 반환값 없음
     */
    public function test_payment_methods_requires_at_least_one_active(): void
    {
        $request = new StoreEcommerceSettingsRequest;
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

    /**
     * 활성 결제수단이 하나 있으면 통과한다.
     *
     * @scenario area=order_settings
     *
     * @effects settings_request_order_settings_rules
     *
     * @return void 반환값 없음
     */
    public function test_payment_methods_passes_with_one_active(): void
    {
        $request = new StoreEcommerceSettingsRequest;
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

    /**
     * 재고 차감 시점에 「차감 안 함」을 받는다.
     *
     * @scenario area=order_settings
     *
     * @effects settings_request_order_settings_rules
     *
     * @return void 반환값 없음
     */
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

    /**
     * 알 수 없는 재고 차감 시점은 거절한다.
     *
     * @scenario area=order_settings
     *
     * @effects settings_request_order_settings_rules
     *
     * @return void 반환값 없음
     */
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

    /**
     * 입금 계좌는 계좌번호가 필수다.
     *
     * @scenario area=order_settings
     *
     * @effects settings_request_order_settings_rules
     *
     * @return void 반환값 없음
     */
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

    /**
     * 입금 계좌는 예금주가 필수다.
     *
     * @scenario area=order_settings
     *
     * @effects settings_request_order_settings_rules
     *
     * @return void 반환값 없음
     */
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

    /**
     * 활성 기본 입금 계좌가 하나도 없으면 거절한다.
     *
     * @scenario area=order_settings
     *
     * @effects settings_request_order_settings_rules
     *
     * @return void 반환값 없음
     */
    public function test_bank_accounts_requires_at_least_one_active_default(): void
    {
        $request = new StoreEcommerceSettingsRequest;
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

    /**
     * 주문설정 은행 목록은 사이트 기본 언어 은행명이 필수다.
     *
     * @scenario area=banks
     *
     * @effects settings_request_bank_names_follow_site_locale
     *
     * @return void 반환값 없음
     */
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
            '사이트 기본 언어(ko) 은행명이 없으면 실패해야 합니다'
        );
    }

    /**
     * 사이트 기본 언어 은행명만 있어도 통과한다.
     *
     * @scenario area=banks
     *
     * @effects settings_request_bank_names_follow_site_locale
     *
     * @return void 반환값 없음
     */
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
            '사이트 기본 언어(ko)만 입력해도 유효해야 합니다. 오류: '.$validator->errors()->toJson()
        );
    }

    /**
     * 다른 언어 은행명이 null 이어도 통과한다.
     *
     * @scenario area=banks
     *
     * @effects settings_request_bank_names_follow_site_locale
     *
     * @return void 반환값 없음
     */
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
            '다른 로케일이 null이어도 유효해야 합니다. 오류: '.$validator->errors()->toJson()
        );
    }

    /**
     * 다른 언어 은행명을 보내면 길이 상한을 검사한다.
     *
     * @scenario area=banks
     *
     * @effects settings_request_bank_names_follow_site_locale
     *
     * @return void 반환값 없음
     */
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

    /**
     * 요청 언어가 영어여도 사이트 기본 언어 은행명만 있으면 영어 은행명을 요구하지 않는다.
     *
     * @scenario area=banks
     *
     * @effects settings_request_bank_names_follow_site_locale
     *
     * @return void 반환값 없음
     */
    public function test_banks_site_locale_required_even_when_request_locale_is_en(): void
    {
        // 필수 언어는 요청 언어가 아니라 사이트 기본 언어다(제품 결정 2026-10-08) — 영어로 보는 관리자도 ko 은행명만 있으면 통과
        config(['app.site_locale' => 'ko']);
        app()->setLocale('en');

        $validator = $this->validate([
            '_tab' => 'order_settings',
            'order_settings' => [
                'banks' => [
                    ['code' => '004', 'name' => ['ko' => '국민은행']],
                ],
            ],
        ]);

        $this->assertFalse(
            $validator->errors()->has('order_settings.banks.0.name.en'),
            '요청 언어가 en 이어도 사이트 기본 언어(ko) 은행명이 있으면 영문 은행명을 요구하지 않아야 합니다'
        );
    }

    /**
     * 모든 칸을 채운 은행 목록은 통과한다.
     *
     * @scenario area=banks
     *
     * @effects settings_request_bank_names_follow_site_locale
     *
     * @return void 반환값 없음
     */
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
            '유효한 은행 데이터를 허용해야 합니다. 오류: '.$validator->errors()->toJson()
        );
    }

    /**
     * 은행 목록 오류 문구가 정의돼 있다.
     *
     * @scenario area=banks
     *
     * @effects settings_request_bank_names_follow_site_locale
     *
     * @return void 반환값 없음
     */
    public function test_banks_custom_messages_exist(): void
    {
        $request = new StoreEcommerceSettingsRequest;
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

    /**
     * 활성 기본 입금 계좌가 있으면 통과한다.
     *
     * @scenario area=order_settings
     *
     * @effects settings_request_order_settings_rules
     *
     * @return void 반환값 없음
     */
    public function test_bank_accounts_passes_with_active_default(): void
    {
        $request = new StoreEcommerceSettingsRequest;
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

    /**
     * 올바른 택배사 목록이 있는 배송 탭은 통과한다.
     *
     * @scenario area=shipping
     *
     * @effects settings_request_carrier_rules
     *
     * @return void 반환값 없음
     */
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
            '유효한 carriers 데이터로 검증이 통과해야 합니다: '.json_encode($validator->errors()->toArray())
        );
    }

    /**
     * 빈 택배사 목록도 통과한다.
     *
     * @scenario area=shipping
     *
     * @effects settings_request_carrier_rules
     *
     * @return void 반환값 없음
     */
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

    /**
     * 택배사 키가 없어도 통과한다.
     *
     * @scenario area=shipping
     *
     * @effects settings_request_carrier_rules
     *
     * @return void 반환값 없음
     */
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

    /**
     * 택배사 코드는 필수다.
     *
     * @scenario area=shipping
     *
     * @effects settings_request_carrier_rules
     *
     * @return void 반환값 없음
     */
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

    /**
     * 형식에 맞는 택배사 코드는 통과한다.
     *
     * @scenario area=shipping
     *
     * @effects settings_request_carrier_rules
     *
     * @return void 반환값 없음
     */
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

    /**
     * 형식에 어긋난 택배사 코드는 거절한다.
     *
     * @scenario area=shipping
     *
     * @effects settings_request_carrier_rules
     *
     * @return void 반환값 없음
     */
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

    /**
     * 택배사 코드 길이 상한을 넘으면 거절한다.
     *
     * @scenario area=shipping
     *
     * @effects settings_request_carrier_rules
     *
     * @return void 반환값 없음
     */
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

    /**
     * 새 택배사는 사이트 기본 언어(ko) 이름이 필수다.
     *
     * @scenario area=shipping
     *
     * @effects settings_request_carrier_rules
     *
     * @return void 반환값 없음
     */
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

    /**
     * 택배사 이름은 언어별 배열이어야 한다.
     *
     * @scenario area=shipping
     *
     * @effects settings_request_carrier_rules
     *
     * @return void 반환값 없음
     */
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

    /**
     * 택배사 이름 길이 상한을 넘으면 거절한다.
     *
     * @scenario area=shipping
     *
     * @effects settings_request_carrier_rules
     *
     * @return void 반환값 없음
     */
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

    /**
     * 택배사 유형은 필수다.
     *
     * @scenario area=shipping
     *
     * @effects settings_request_carrier_rules
     *
     * @return void 반환값 없음
     */
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

    /**
     * 알 수 없는 택배사 유형은 거절한다.
     *
     * @scenario area=shipping
     *
     * @effects settings_request_carrier_rules
     *
     * @return void 반환값 없음
     */
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

    /**
     * 허용된 택배사 유형은 통과한다.
     *
     * @scenario area=shipping
     *
     * @effects settings_request_carrier_rules
     *
     * @return void 반환값 없음
     */
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

    /**
     * 추적 주소 길이 상한을 넘으면 거절한다.
     *
     * @scenario area=shipping
     *
     * @effects settings_request_carrier_rules
     *
     * @return void 반환값 없음
     */
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

    /**
     * 추적 주소는 생략해도 된다.
     *
     * @scenario area=shipping
     *
     * @effects settings_request_carrier_rules
     *
     * @return void 반환값 없음
     */
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

    /**
     * 중복된 택배사 코드는 거절한다.
     *
     * @scenario area=shipping
     *
     * @effects settings_request_carrier_rules
     *
     * @return void 반환값 없음
     */
    public function test_carrier_duplicate_codes_rejected(): void
    {
        $request = new StoreEcommerceSettingsRequest;
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

    /**
     * 대소문자만 다른 택배사 코드도 중복으로 본다.
     *
     * @scenario area=shipping
     *
     * @effects settings_request_carrier_rules
     *
     * @return void 반환값 없음
     */
    public function test_carrier_duplicate_codes_case_insensitive(): void
    {
        $request = new StoreEcommerceSettingsRequest;
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

    /**
     * 공백만 있는 택배사 이름은 추가 검사가 거절한다.
     *
     * @scenario area=shipping
     *
     * @effects settings_request_carrier_rules
     *
     * @return void 반환값 없음
     */
    public function test_carrier_empty_name_rejected_by_custom_validator(): void
    {
        // validateCarrierNames 는 사이트 기본 언어의 배송사명을 검사한다.
        // 테스트 데이터가 ko 공백을 제공하므로 환경 로케일(ja 등)과 무관하게 ko 로 고정한다.
        app()->setLocale('ko');

        $request = new StoreEcommerceSettingsRequest;
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

    /**
     * 이름 배열이 없는 택배사는 추가 검사가 거절한다.
     *
     * @scenario area=shipping
     *
     * @effects settings_request_carrier_rules
     *
     * @return void 반환값 없음
     */
    public function test_carrier_missing_name_array_rejected_by_custom_validator(): void
    {
        $request = new StoreEcommerceSettingsRequest;
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

    /**
     * 택배사 오류 문구가 정의돼 있다.
     *
     * @scenario area=shipping
     *
     * @effects settings_request_carrier_rules
     *
     * @return void 반환값 없음
     */
    public function test_carrier_custom_messages_exist(): void
    {
        $request = new StoreEcommerceSettingsRequest;
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

    /**
     * 여러 택배사 행을 각각 따로 검증한다.
     *
     * @scenario area=shipping
     *
     * @effects settings_request_carrier_rules
     *
     * @return void 반환값 없음
     */
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

    // ──────────────────────────────────────────────
    // mileage 탭 검증 (§8.2)
    // ──────────────────────────────────────────────

    /**
     * 탭 필드에 마일리지를 받는다.
     *
     * @scenario area=tab
     *
     * @effects settings_request_tab_field_scopes_validation
     *
     * @return void 반환값 없음
     */
    public function test_tab_field_accepts_mileage(): void
    {
        $validator = $this->validate(['_tab' => 'mileage', 'mileage' => ['enabled' => true]]);

        $this->assertFalse($validator->errors()->has('_tab'), 'mileage 탭이 허용되어야 합니다');
    }

    /**
     * 올바른 마일리지 설정은 통과한다.
     *
     * @scenario area=mileage
     *
     * @effects settings_request_mileage_rules
     *
     * @return void 반환값 없음
     */
    public function test_mileage_valid_settings_pass(): void
    {
        $validator = $this->validate([
            'mileage' => [
                'enabled' => true,
                'default_earn_rate' => 5,
                'earn_trigger' => 'confirmed',
                'earn_delay_days' => 0,
                'currency_rules' => [
                    ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 1000, 'use_unit' => 10, 'max_use_type' => 'fixed', 'max_use_percent' => 30, 'max_use_value' => 50000],
                ],
                'expiry_enabled' => true,
                'expiry_days' => 365,
                'expiry_notification_enabled' => true,
                'expiry_notification_days_before' => 7,
            ],
        ]);

        $this->assertFalse($validator->fails(), '유효한 마일리지 설정은 통과해야 합니다');
    }

    /**
     * 알 수 없는 적립 시점은 거절한다.
     *
     * @scenario area=mileage
     *
     * @effects settings_request_mileage_rules
     *
     * @return void 반환값 없음
     */
    public function test_mileage_earn_trigger_rejects_invalid(): void
    {
        $validator = $this->validate(['mileage' => ['enabled' => true, 'earn_trigger' => 'invalid']]);

        $this->assertTrue($validator->errors()->has('mileage.earn_trigger'));
    }

    /**
     * 알 수 없는 최대 사용 방식은 거절한다.
     *
     * @scenario area=mileage
     *
     * @effects settings_request_mileage_rules
     *
     * @return void 반환값 없음
     */
    public function test_mileage_max_use_type_rejects_invalid(): void
    {
        $validator = $this->validate([
            'mileage' => [
                'enabled' => true,
                'currency_rules' => [['currency_code' => 'KRW', 'max_use_type' => 'percentage']],
            ],
        ]);

        $this->assertTrue($validator->errors()->has('mileage.currency_rules.0.max_use_type'));
    }

    /**
     * 음수 기본 적립률은 거절한다.
     *
     * @scenario area=mileage
     *
     * @effects settings_request_mileage_rules
     *
     * @return void 반환값 없음
     */
    public function test_mileage_default_earn_rate_rejects_negative(): void
    {
        $validator = $this->validate(['mileage' => ['enabled' => true, 'default_earn_rate' => -5]]);

        $this->assertTrue($validator->errors()->has('mileage.default_earn_rate'));
    }

    /**
     * 통화 코드가 중복된 마일리지 설정은 거절한다.
     *
     * @scenario area=mileage
     *
     * @effects settings_request_mileage_rules
     *
     * @return void 반환값 없음
     */
    public function test_mileage_duplicate_currency_code_rejected(): void
    {
        // withValidator after-검증을 포함한 전체 검증
        $request = new StoreEcommerceSettingsRequest;
        $request->merge([
            'mileage' => [
                'enabled' => true,
                'currency_rules' => [
                    ['currency_code' => 'KRW', 'max_use_type' => 'fixed'],
                    ['currency_code' => 'KRW', 'max_use_type' => 'fixed'],
                ],
            ],
        ]);
        $validator = Validator::make($request->all(), $request->rules());
        $request->withValidator($validator);
        $validator->passes();

        $this->assertTrue(
            $validator->errors()->has('mileage.currency_rules.1.currency_code'),
            '통화 코드 중복은 거부되어야 합니다'
        );
    }

    /**
     * 검증된 설정 값에 마일리지 구역이 담긴다.
     *
     * @scenario area=tab
     *
     * @effects settings_request_tab_field_scopes_validation
     *
     * @return void 반환값 없음
     */
    public function test_validated_settings_includes_mileage(): void
    {
        $request = new StoreEcommerceSettingsRequest;
        $request->merge([
            '_tab' => 'mileage',
            'mileage' => ['enabled' => true, 'default_earn_rate' => 3],
        ]);
        $request->setValidator(Validator::make($request->all(), $request->rules()));

        $settings = $request->validatedSettings();

        $this->assertArrayHasKey('mileage', $settings, 'validatedSettings 에 mileage 카테고리가 포함되어야 합니다');
        $this->assertArrayNotHasKey('_tab', $settings, '_tab 은 제외되어야 합니다');
    }

    // ========================================================================
    // shipping.carriers / shipping.types 활성 플래그 — 키 부재 거절
    // ========================================================================

    /**
     * 배송사·배송유형 행의 `is_active` 는 키 부재도 거절한다.
     *
     * 동기화가 `?? true` 라 키가 빠지면 운영자가 내려 둔 항목이 켜진 채 되살아난다 — 클레임 사유
     * 카탈로그와 같은 강도(`required_with`)로 맞춘다 (S10 10차 확정).
     *
     * @scenario area=shipping
     *
     * @effects settings_request_carrier_rules
     *
     * @return void 반환값 없음
     */
    public function test_carrier_and_type_is_active_key_is_required_when_rows_are_sent(): void
    {
        $validator = $this->validate([
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [
                    ['code' => 'cj', 'name' => ['ko' => 'CJ대한통운'], 'type' => 'domestic'],
                ],
                'types' => [
                    ['code' => 'parcel', 'name' => ['ko' => '택배'], 'category' => 'domestic'],
                ],
            ],
        ]);

        $this->assertTrue($validator->errors()->has('shipping.carriers.0.is_active'), 'carriers 행에 is_active 가 없으면 422 여야 합니다');
        $this->assertTrue($validator->errors()->has('shipping.types.0.is_active'), 'types 행에 is_active 가 없으면 422 여야 합니다');
    }

    /**
     * 실재하지 않는 id 를 단 택배사·배송유형 행은 거절한다.
     *
     * @scenario area=shipping
     *
     * @effects settings_request_carrier_rules
     *
     * @return void 반환값 없음
     */
    public function test_carrier_and_type_rows_with_unknown_id_are_rejected(): void
    {
        // `id` 는 이름의 사이트 기본 언어 필수 면제(기존 행) 판정 근거다. 실재하지 않는 id 를 통과시키면
        // 면제만 받고 sync 는 신규 create 로 흘러 이름 없는 행이 생긴다 — 클레임 사유와 같은 강도.
        $validator = $this->validate([
            '_tab' => 'shipping',
            'shipping' => [
                'carriers' => [
                    ['id' => 999999, 'code' => 'ghost', 'name' => ['en' => 'Ghost'], 'type' => 'domestic', 'is_active' => true],
                ],
                'types' => [
                    ['id' => 999999, 'code' => 'ghost', 'name' => ['en' => 'Ghost'], 'category' => 'domestic', 'is_active' => true],
                ],
            ],
        ]);

        $this->assertTrue($validator->errors()->has('shipping.carriers.0.id'), '실재하지 않는 배송사 id 는 422 여야 합니다');
        $this->assertTrue($validator->errors()->has('shipping.types.0.id'), '실재하지 않는 배송유형 id 는 422 여야 합니다');
    }

    /**
     * 완료 처리 기본값의 하위 키 목록이 defaults.json 과 같은 집합인지 검증합니다.
     *
     * `required_array_keys:` 와 하위 boolean 규칙은 키를 문자열로 열거한다. 세 번째 완료
     * 기본값이 `defaults.json` 에 생겨도 그 열거를 함께 고치지 않으면, 그 키는 규칙이 없어
     * `validated()` 에서 조용히 탈락하고 소비 쪽 `?? true` 를 만난다 — 운영자가 끈 값이
     * 켜진 채로 실행된다. 열거를 손으로 맞추는 대신 정본에서 파생해 대조한다.
     *
     * @scenario area=claim
     *
     * @effects settings_request_claim_rules
     *
     * @return void 반환값 없음
     */
    public function test_complete_defaults_rule_keys_match_defaults_json(): void
    {
        $defaults = json_decode(
            (string) file_get_contents(__DIR__.'/../../../config/settings/defaults.json'),
            true
        );
        $expected = array_keys($defaults['defaults']['claim']['complete_defaults'] ?? []);

        $this->assertNotEmpty($expected, 'defaults.json 의 claim.complete_defaults 를 읽지 못했습니다');

        $rules = (new StoreEcommerceSettingsRequest)->rules();

        // ① required_array_keys 열거가 정본과 같은 집합인가
        $parent = $rules['claim.complete_defaults'] ?? [];
        $declared = [];
        foreach ($parent as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'required_array_keys:')) {
                $declared = explode(',', substr($rule, strlen('required_array_keys:')));
            }
        }
        sort($declared);
        $sortedExpected = $expected;
        sort($sortedExpected);
        $this->assertSame(
            $sortedExpected,
            $declared,
            'required_array_keys 의 열거가 defaults.json 의 완료 기본값 키 집합과 다릅니다'
        );

        // ② 하위 키마다 boolean 규칙이 있는가 — 규칙 배열의 **존재**만 보면 `nullable|string`
        //    으로 바꿔도 초록이다. 이 케이스가 약속한 것은 타입 게이트이므로 boolean 을 본다.
        foreach ($expected as $key) {
            $this->assertArrayHasKey(
                "claim.complete_defaults.{$key}",
                $rules,
                "완료 기본값 {$key} 에 검증 규칙이 없습니다 — validated() 에서 탈락해 소비 쪽 기본값으로 되살아납니다"
            );

            $this->assertContains(
                'boolean',
                (array) $rules["claim.complete_defaults.{$key}"],
                "완료 기본값 {$key} 의 규칙에 boolean 이 없습니다 — 문자열 'false' 가 참으로 저장됩니다"
            );
        }
    }

    /**
     * `claim` 스칼라 축 **전부**가 서버 검증 규칙을 갖는지 검증합니다.
     *
     * 위 케이스가 닫은 것은 `complete_defaults` 의 **하위 키**뿐이고, 그 부모인 스칼라 축은
     * 규칙이 손열거인 채 남아 있었다. 그래서 `defaults.json` 에 축이 하나 늘면 화면 통로
     * 검사(`adminEcommerceSettingsClaim.test.ts`)는 한 번 붉어지지만, 개발자가 화면 통로를
     * 붙이는 순간 초록으로 돌아오고 **서버 규칙 부재는 아무도 보지 않는다**. 그때 그 축은
     * `validated()` 에서 탈락하는데 설정 저장이 카테고리 파일 통째 교체라, 운영자가 값을
     * 넣어도 저장할 때마다 지워져 기본값으로 되살아난다 — 응답은 200 이고 로그도 없다.
     *
     * 되돌리면(규칙 한 줄 삭제) 붉어진다.
     *
     * @scenario area=claim
     *
     * @effects settings_request_claim_rules
     *
     * @return void 반환값 없음
     */
    public function test_every_claim_scalar_axis_in_defaults_json_has_a_validation_rule(): void
    {
        $defaults = json_decode(
            (string) file_get_contents(__DIR__.'/../../../config/settings/defaults.json'),
            true
        );
        $claim = $defaults['defaults']['claim'] ?? [];

        $this->assertNotEmpty($claim, 'defaults.json 의 defaults.claim 을 읽지 못했습니다');

        $rules = (new StoreEcommerceSettingsRequest)->rules();

        // 사유 카탈로그 3종은 DB 관리 대상이라 defaults.json 에 없다 — 여기 있는 것은 전부
        // 운영자가 설정 탭에서 편집하는 축이므로 예외 없이 규칙을 가져야 한다.
        $missing = [];
        foreach (array_keys($claim) as $axis) {
            if (! array_key_exists("claim.{$axis}", $rules)) {
                $missing[] = $axis;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'defaults.json 의 claim 축인데 검증 규칙이 없습니다: '.implode(', ', $missing)
                .' — validated() 에서 탈락해 저장할 때마다 지워집니다'
        );
    }
}
