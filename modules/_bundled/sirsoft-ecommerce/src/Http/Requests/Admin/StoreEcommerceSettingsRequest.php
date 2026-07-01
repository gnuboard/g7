<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Rules\LocaleRequiredTranslatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;

/**
 * 이커머스 설정 저장 요청 검증
 */
class StoreEcommerceSettingsRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $locale = app()->getLocale();

        return [
            '_tab' => ['sometimes', 'string', 'in:basic_info,language_currency,seo,order_settings,claim,shipping,review_settings,notification_definitions,notifications,inquiry,mileage'],

            'notifications' => ['sometimes', 'array'],
            'notifications.channels' => ['sometimes', 'array'],
            'notifications.channels.*.id' => ['required_with:notifications.channels', 'string', 'max:50'],
            'notifications.channels.*.is_active' => ['required_with:notifications.channels', 'boolean'],
            'notifications.channels.*.sort_order' => ['nullable', 'integer', 'min:0'],

            'basic_info' => ['sometimes', 'array'],
            'basic_info.shop_name' => ['required_with:basic_info', 'string', 'max:255'],
            'basic_info.route_path' => ['exclude_if:basic_info.no_route,true', 'required_with:basic_info', 'string', 'max:100'],
            'basic_info.no_route' => ['nullable', 'boolean'],
            'basic_info.company_name' => ['nullable', 'string', 'max:255'],
            'basic_info.business_number_1' => ['nullable', 'string', 'max:3'],
            'basic_info.business_number_2' => ['nullable', 'string', 'max:2'],
            'basic_info.business_number_3' => ['nullable', 'string', 'max:5'],
            'basic_info.ceo_name' => ['nullable', 'string', 'max:100'],
            'basic_info.business_type' => ['nullable', 'string', 'max:100'],
            'basic_info.business_category' => ['nullable', 'string', 'max:255'],
            'basic_info.zipcode' => ['nullable', 'string', 'max:10'],
            'basic_info.base_address' => ['nullable', 'string', 'max:500'],
            'basic_info.detail_address' => ['nullable', 'string', 'max:255'],
            'basic_info.phone_1' => ['nullable', 'string', 'max:4'],
            'basic_info.phone_2' => ['nullable', 'string', 'max:4'],
            'basic_info.phone_3' => ['nullable', 'string', 'max:4'],
            'basic_info.fax_1' => ['nullable', 'string', 'max:4'],
            'basic_info.fax_2' => ['nullable', 'string', 'max:4'],
            'basic_info.fax_3' => ['nullable', 'string', 'max:4'],
            'basic_info.email_id' => ['nullable', 'string', 'max:100'],
            'basic_info.email_domain' => ['nullable', 'string', 'max:100'],
            'basic_info.privacy_officer' => ['nullable', 'string', 'max:100'],
            'basic_info.privacy_officer_email' => ['nullable', 'email', 'max:255'],
            'basic_info.mail_order_number' => ['nullable', 'string', 'max:100'],
            'basic_info.telecom_number' => ['nullable', 'string', 'max:100'],

            'language_currency' => ['sometimes', 'array'],
            // default_language 제거 (A1-⑤, D-LANG): 사이트 언어는 코어 일반설정으로 일원화. 모듈 orphan 필드.
            'language_currency.default_currency' => ['nullable', 'string', 'max:10'],
            'language_currency.currencies' => ['nullable', 'array'],
            'language_currency.currencies.*.code' => ['required_with:language_currency.currencies', 'string', 'regex:/^[A-Z]{3}$/'],
            'language_currency.currencies.*.name' => ['required_with:language_currency.currencies', 'array'],
            'language_currency.currencies.*.name.*' => ['string', 'max:100'],
            'language_currency.currencies.*.symbol' => ['nullable', 'string', 'max:8'],
            'language_currency.currencies.*.exchange_rate' => ['nullable', 'numeric', 'min:0'],
            // base_unit = 그 통화가 기본일 때 환율 입력의 분모(1단위 금액). 소액통화만 묶음(KRW=1000, JPY=100).
            'language_currency.currencies.*.base_unit' => ['nullable', 'integer', 'min:1'],
            'language_currency.currencies.*.rounding_unit' => ['nullable', 'string'],
            'language_currency.currencies.*.rounding_method' => ['nullable', 'string', 'in:floor,round,ceil'],
            'language_currency.currencies.*.decimal_places' => ['nullable', 'integer', 'min:0', 'max:8'],
            'language_currency.currencies.*.is_default' => ['nullable', 'boolean'],
            // 통화별 언어(가입 시 통화 추정용 매핑, A4). 후보 = 시스템 지원 로케일.
            'language_currency.currencies.*.locales' => ['nullable', 'array'],
            'language_currency.currencies.*.locales.*' => ['string', 'in:'.implode(',', config('app.supported_locales', ['ko', 'en']))],

            'seo' => ['sometimes', 'array'],
            'seo.meta_category_title' => ['nullable', 'string', 'max:500'],
            'seo.meta_category_description' => ['nullable', 'string', 'max:1000'],
            'seo.meta_search_title' => ['nullable', 'string', 'max:500'],
            'seo.meta_search_description' => ['nullable', 'string', 'max:1000'],
            'seo.meta_product_title' => ['nullable', 'string', 'max:500'],
            'seo.meta_product_description' => ['nullable', 'string', 'max:1000'],
            'seo.meta_shop_index_title' => ['nullable', 'string', 'max:500'],
            'seo.meta_shop_index_description' => ['nullable', 'string', 'max:1000'],
            'seo.seo_category' => ['nullable', 'boolean'],
            'seo.seo_search_result' => ['nullable', 'boolean'],
            'seo.seo_product_detail' => ['nullable', 'boolean'],
            'seo.seo_shop_index' => ['nullable', 'boolean'],

            // inquiry 섹션
            'inquiry' => ['sometimes', 'array'],
            'inquiry.board_slug' => ['nullable', 'string', 'max:255'],

            // order_settings 섹션
            'order_settings' => ['sometimes', 'array'],
            'order_settings.default_pg_provider' => ['nullable', 'string', 'max:50'],
            'order_settings.payment_methods' => ['nullable', 'array'],
            'order_settings.payment_methods.*.id' => ['required_with:order_settings.payment_methods', 'string', 'max:50'],
            'order_settings.payment_methods.*.pg_provider' => ['nullable', 'string', 'max:50'],
            'order_settings.payment_methods.*.sort_order' => ['nullable', 'integer', 'min:1'],
            'order_settings.payment_methods.*.is_active' => ['nullable', 'boolean'],
            'order_settings.payment_methods.*.min_order_amount' => ['nullable', 'integer', 'min:0'],
            'order_settings.payment_methods.*.stock_deduction_timing' => ['nullable', 'string', 'in:order_placed,payment_complete,none'],
            'order_settings.payment_methods.*.mileage_deduction_timing' => ['nullable', 'string', 'in:order_placed,payment_complete'],
            'order_settings.banks' => ['nullable', 'array'],
            'order_settings.banks.*.code' => ['required_with:order_settings.banks', 'string', 'max:10'],
            'order_settings.banks.*.name' => ['required_with:order_settings.banks', 'array'],
            "order_settings.banks.*.name.{$locale}" => ['required_with:order_settings.banks', 'string', 'max:100'],
            'order_settings.banks.*.name.*' => ['nullable', 'string', 'max:100'],
            'order_settings.bank_accounts' => ['nullable', 'array'],
            'order_settings.bank_accounts.*.bank_code' => ['required_with:order_settings.bank_accounts', 'string', 'max:10'],
            'order_settings.bank_accounts.*.account_number' => ['required_with:order_settings.bank_accounts', 'string', 'max:50'],
            'order_settings.bank_accounts.*.account_holder' => ['required_with:order_settings.bank_accounts', 'string', 'max:100'],
            'order_settings.bank_accounts.*.is_active' => ['nullable', 'boolean'],
            'order_settings.bank_accounts.*.is_default' => ['nullable', 'boolean'],
            'order_settings.auto_cancel_expired' => ['nullable', 'boolean'],
            'order_settings.auto_cancel_days' => ['nullable', 'integer', 'min:0', 'max:30'],
            'order_settings.cart_expiry_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'order_settings.stock_restore_on_cancel' => ['nullable', 'boolean'],
            'order_settings.confirmable_statuses' => ['nullable', 'array'],
            'order_settings.confirmable_statuses.*' => ['string', 'in:payment_complete,shipping_hold,preparing,shipping_ready,shipping,delivered'],

            // claim 섹션 (refund_reasons는 DB 동기화 대상)
            'claim' => ['sometimes', 'array'],
            'claim.refund_reasons' => ['nullable', 'array'],
            'claim.refund_reasons.*.id' => ['nullable', 'integer'],
            'claim.refund_reasons.*.code' => ['required_with:claim.refund_reasons', 'string', 'max:50', 'regex:/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/'],
            'claim.refund_reasons.*.name' => ['required_with:claim.refund_reasons', 'array', new LocaleRequiredTranslatable(maxLength: 100)],
            'claim.refund_reasons.*.fault_type' => ['required_with:claim.refund_reasons', 'string', 'in:customer,seller,carrier'],
            'claim.refund_reasons.*.is_user_selectable' => ['nullable', 'boolean'],
            'claim.refund_reasons.*.is_active' => ['nullable', 'boolean'],
            'claim.refund_reasons.*.sort_order' => ['nullable', 'integer', 'min:0'],

            // review_settings 섹션
            'review_settings' => ['sometimes', 'array'],
            'review_settings.write_deadline_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'review_settings.max_images' => ['nullable', 'integer', 'min:0', 'max:20'],
            'review_settings.max_image_size_mb' => ['nullable', 'integer', 'min:1', 'max:50'],

            // mileage 섹션
            'mileage' => ['sometimes', 'array'],
            'mileage.enabled' => ['required_with:mileage', 'boolean'],
            'mileage.default_earn_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // 마일리지 차감 시점은 결제수단별(order_settings.payment_methods.*.mileage_deduction_timing)로 관리한다.
            'mileage.earn_trigger' => ['nullable', 'string', 'in:delivered,confirmed'],
            'mileage.earn_delay_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'mileage.currency_rules' => ['nullable', 'array'],
            // 통화 코드: ISO 4217 형식(3자리 영문 대문자) 강제
            'mileage.currency_rules.*.currency_code' => ['required_with:mileage.currency_rules', 'string', 'regex:/^[A-Z]{3}$/'],
            // 1점당 금액: 0이면 마일리지가 금전 가치 0 → 사용 불가. 최소 0 초과(0.001 단위까지 허용)
            'mileage.currency_rules.*.point_value' => ['nullable', 'numeric', 'min:0.001'],
            'mileage.currency_rules.*.min_use_amount' => ['nullable', 'integer', 'min:0'],
            'mileage.currency_rules.*.use_unit' => ['nullable', 'integer', 'min:1'],
            'mileage.currency_rules.*.max_use_type' => ['nullable', 'string', 'in:percent,fixed'],
            'mileage.currency_rules.*.max_use_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // 최대 사용한도(고정금액): 비합리적 거액 방지를 위해 10억 상한
            'mileage.currency_rules.*.max_use_value' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'mileage.expiry_enabled' => ['nullable', 'boolean'],
            'mileage.expiry_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'mileage.expiry_notification_enabled' => ['nullable', 'boolean'],
            'mileage.expiry_notification_days_before' => ['nullable', 'integer', 'min:1', 'max:365'],

            // shipping 섹션
            'shipping' => ['sometimes', 'array'],
            'shipping.default_country' => ['nullable', 'string', 'max:10'],
            'shipping.available_countries' => ['nullable', 'array'],
            'shipping.available_countries.*.code' => ['required_with:shipping.available_countries', 'string', 'max:10'],
            'shipping.available_countries.*.name' => ['required_with:shipping.available_countries', 'array'],
            'shipping.available_countries.*.name.*' => ['string', 'max:100'],
            'shipping.available_countries.*.is_active' => ['nullable', 'boolean'],
            'shipping.international_shipping_enabled' => ['nullable', 'boolean'],
            'shipping.free_shipping_threshold' => ['nullable', 'integer', 'min:0'],
            'shipping.free_shipping_enabled' => ['nullable', 'boolean'],
            'shipping.address_validation_enabled' => ['nullable', 'boolean'],
            'shipping.address_api_provider' => ['nullable', 'string', 'max:50'],

            // shipping.carriers 섹션 (DB 동기화 대상)
            'shipping.carriers' => ['nullable', 'array'],
            'shipping.carriers.*.id' => ['nullable', 'integer'],
            'shipping.carriers.*.code' => ['required_with:shipping.carriers', 'string', 'max:50', 'regex:/^[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*$/'],
            'shipping.carriers.*.name' => ['required_with:shipping.carriers', 'array', new LocaleRequiredTranslatable(maxLength: 100)],
            'shipping.carriers.*.type' => ['required_with:shipping.carriers', 'string', 'in:domestic,international'],
            'shipping.carriers.*.tracking_url' => ['nullable', 'string', 'max:500'],
            'shipping.carriers.*.is_active' => ['nullable', 'boolean'],
            'shipping.carriers.*.sort_order' => ['nullable', 'integer', 'min:0'],

            // shipping.types 섹션 (DB 동기화 대상)
            'shipping.types' => ['nullable', 'array'],
            'shipping.types.*.id' => ['nullable', 'integer'],
            'shipping.types.*.code' => ['required_with:shipping.types', 'string', 'max:50', 'regex:/^[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*$/'],
            'shipping.types.*.name' => ['required_with:shipping.types', 'array', new LocaleRequiredTranslatable(maxLength: 100)],
            'shipping.types.*.category' => ['required_with:shipping.types', 'string', 'in:domestic,international,other'],
            'shipping.types.*.is_active' => ['nullable', 'boolean'],
            'shipping.types.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * 검증 속성명 다국어 처리
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('sirsoft-ecommerce::validation.attributes');
    }

    /**
     * 추가 검증 로직 설정
     *
     * @param  Validator  $validator  Validator 인스턴스
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateBaseCurrencyChangeable($validator);
            $this->validateUniqueCurrencyCodes($validator);
            $this->validateCurrencyNames($validator);
            $this->validateAtLeastOneActivePaymentMethod($validator);
            $this->validatePgRequiredForActivation($validator);
            $this->validateBankAccountDefaults($validator);
            $this->validateUniqueCountryCodes($validator);
            $this->validateCountryNames($validator);
            $this->validateDefaultCountryExists($validator);
            $this->validateUniqueCarrierCodes($validator);
            $this->validateCarrierNames($validator);
            $this->validateUniqueShippingTypeCodes($validator);
            $this->validateShippingTypeNames($validator);
            $this->validateUniqueRefundReasonCodes($validator);
            $this->validateRefundReasonNames($validator);
            $this->validateMileageCurrencyRules($validator);
            $this->validateMileageEarnRate($validator);
        });
    }

    /**
     * 기본 통화(default_currency) 변경 가능 여부 검증 (A2 — D-BASE-1)
     *
     * 상품 또는 주문이 1건이라도 존재하면 기본 통화 변경을 차단한다. base 통화는 모든 금액
     * 테이블의 SSoT 이고 소급 변환이 데이터 정합성을 깨므로, 데이터가 생긴 이후에는 영구 잠금한다.
     * (소프트삭제 포함 — 삭제분도 과거 base 로 생성된 이력) 동일 통화 재저장은 변경이 아니므로 통과.
     *
     * @param  Validator  $validator  Validator 인스턴스
     */
    protected function validateBaseCurrencyChangeable(Validator $validator): void
    {
        $newDefault = $this->input('language_currency.default_currency');
        if ($newDefault === null) {
            return; // 통화 설정 미전달 — 변경 아님
        }

        $current = app(EcommerceSettingsService::class)->getSetting('language_currency.default_currency');
        if ($newDefault === $current) {
            return; // 동일 통화 재저장 — 변경 아님
        }

        // 상품/주문 1건이라도 있으면 base 변경 불가 (영구 잠금, 소프트삭제 포함)
        $hasData = app(ProductRepositoryInterface::class)->existsAny()
            || app(OrderRepositoryInterface::class)->existsAny();

        if ($hasData) {
            $validator->errors()->add(
                'language_currency.default_currency',
                __('sirsoft-ecommerce::validation.custom.language_currency.base_locked_after_data')
            );
        }
    }

    /**
     * 마일리지 활성화 시 기본 적립률 검증
     *
     * 마일리지 사용(enabled)을 켰는데 기본 적립률이 0%면 적립이 전혀 발생하지 않아
     * 활성화 의미가 없으므로 거부한다. (비활성 상태에서는 0% 허용)
     *
     * @param  Validator  $validator  Validator 인스턴스
     */
    protected function validateMileageEarnRate(Validator $validator): void
    {
        if (! $this->has('mileage')) {
            return;
        }

        $enabled = $this->boolean('mileage.enabled');
        $rate = $this->input('mileage.default_earn_rate');

        if ($enabled && $rate !== null && (float) $rate <= 0) {
            $validator->errors()->add(
                'mileage.default_earn_rate',
                __('sirsoft-ecommerce::validation.mileage.earn_rate_required_when_enabled')
            );
        }
    }

    /**
     * 마일리지 통화 규칙 검증
     *
     * 1) 통화 코드 중복 금지
     * 2) 첫 행은 기본 통화여야 함 (기본 통화 행 보장)
     * 3) 등록된 통화(language_currency.currencies)만 허용 (정합성)
     *
     * 형식(ISO 4217)은 rules() 의 regex 로 선검증되며, 여기서는 도메인 정합성만 다룬다.
     *
     * @param  Validator  $validator  Validator 인스턴스
     */
    protected function validateMileageCurrencyRules(Validator $validator): void
    {
        $rules = $this->input('mileage.currency_rules', []);

        if (empty($rules) || ! is_array($rules)) {
            return;
        }

        [$defaultCurrency, $registeredCodes] = $this->resolveRegisteredCurrencies();

        $codes = [];
        foreach ($rules as $index => $rule) {
            if (! isset($rule['currency_code']) || ! is_string($rule['currency_code'])) {
                continue;
            }

            $code = strtoupper(trim($rule['currency_code']));

            // 형식 위반(regex 미통과)은 rules() 에서 이미 에러 추가됨 → 도메인 검증 스킵
            if (! preg_match('/^[A-Z]{3}$/', $code)) {
                $codes[] = $code;

                continue;
            }

            // 1) 중복
            if (in_array($code, $codes, true)) {
                $validator->errors()->add(
                    "mileage.currency_rules.{$index}.currency_code",
                    __('sirsoft-ecommerce::validation.mileage.duplicate_currency')
                );
            }

            // 2) 첫 행 = 기본 통화
            if ($index === 0 && $defaultCurrency !== null && $code !== $defaultCurrency) {
                $validator->errors()->add(
                    'mileage.currency_rules.0.currency_code',
                    __('sirsoft-ecommerce::validation.mileage.first_must_be_default', ['currency' => $defaultCurrency])
                );
            }

            // 3) 등록 통화 정합성 (등록 목록이 있을 때만)
            if (! empty($registeredCodes) && ! in_array($code, $registeredCodes, true)) {
                $validator->errors()->add(
                    "mileage.currency_rules.{$index}.currency_code",
                    __('sirsoft-ecommerce::validation.mileage.currency_not_registered', ['currency' => $code])
                );
            }

            $codes[] = $code;
        }
    }

    /**
     * 검증 기준이 되는 등록 통화 정보를 해석합니다.
     *
     * 같은 요청에 language_currency 가 함께 오면 그 값을, 아니면 현재 저장된 설정을 기준으로 한다
     * (탭별 저장 시 마일리지 탭은 현재 통화 설정을 기준으로 정합성 검증).
     *
     * @return array{0: ?string, 1: array<int, string>} [기본통화코드, 등록통화코드목록]
     */
    protected function resolveRegisteredCurrencies(): array
    {
        $defaultCurrency = $this->input('language_currency.default_currency');
        $currencies = $this->input('language_currency.currencies');

        // 요청에 language_currency 가 없으면 현재 저장 설정에서 조회
        if ($defaultCurrency === null || ! is_array($currencies)) {
            $settings = app(EcommerceSettingsService::class);
            $defaultCurrency = $defaultCurrency ?? $settings->getSetting('language_currency.default_currency');
            $currencies = is_array($currencies) ? $currencies : ($settings->getSetting('language_currency.currencies') ?? []);
        }

        $codes = [];
        foreach ((array) $currencies as $currency) {
            if (isset($currency['code']) && is_string($currency['code'])) {
                $codes[] = strtoupper(trim($currency['code']));
            }
        }

        $defaultCurrency = is_string($defaultCurrency) ? strtoupper(trim($defaultCurrency)) : null;

        return [$defaultCurrency, $codes];
    }

    /**
     * 통화 코드 중복 검증
     *
     * @param  Validator  $validator  Validator 인스턴스
     */
    protected function validateUniqueCurrencyCodes(Validator $validator): void
    {
        $currencies = $this->input('language_currency.currencies', []);

        if (empty($currencies) || ! is_array($currencies)) {
            return;
        }

        $codes = [];
        foreach ($currencies as $index => $currency) {
            if (! isset($currency['code'])) {
                continue;
            }

            $code = strtoupper(trim($currency['code']));
            if (in_array($code, $codes)) {
                $validator->errors()->add(
                    "language_currency.currencies.{$index}.code",
                    __('sirsoft-ecommerce::validation.custom.language_currency.currencies.duplicate_code')
                );
            }
            $codes[] = $code;
        }
    }

    /**
     * 통화명 필수 검증 - 최소 하나의 로케일에 이름이 있어야 함
     *
     * @param  Validator  $validator  Validator 인스턴스
     */
    protected function validateCurrencyNames(Validator $validator): void
    {
        $currencies = $this->input('language_currency.currencies', []);

        if (empty($currencies) || ! is_array($currencies)) {
            return;
        }

        foreach ($currencies as $index => $currency) {
            $name = $currency['name'] ?? [];

            // name이 배열이 아니거나 빈 배열인 경우
            if (! is_array($name) || empty($name)) {
                $validator->errors()->add(
                    "language_currency.currencies.{$index}.name",
                    __('sirsoft-ecommerce::validation.custom.language_currency.currencies.name_required')
                );

                continue;
            }

            // 모든 로케일의 이름이 빈 문자열인지 확인
            $hasValidName = false;
            foreach ($name as $localeName) {
                if (is_string($localeName) && trim($localeName) !== '') {
                    $hasValidName = true;
                    break;
                }
            }

            if (! $hasValidName) {
                $validator->errors()->add(
                    "language_currency.currencies.{$index}.name",
                    __('sirsoft-ecommerce::validation.custom.language_currency.currencies.name_required')
                );
            }
        }
    }

    /**
     * 결제수단 최소 1개 활성화 검증
     *
     * @param  Validator  $validator  Validator 인스턴스
     */
    protected function validateAtLeastOneActivePaymentMethod(Validator $validator): void
    {
        $paymentMethods = $this->input('order_settings.payment_methods', []);

        if (empty($paymentMethods) || ! is_array($paymentMethods)) {
            return;
        }

        $hasActive = false;
        foreach ($paymentMethods as $method) {
            if (! empty($method['is_active'])) {
                $hasActive = true;
                break;
            }
        }

        if (! $hasActive) {
            $validator->errors()->add(
                'order_settings.payment_methods',
                __('sirsoft-ecommerce::validation.custom.order_settings.payment_methods.at_least_one_active')
            );
        }
    }

    /**
     * PG가 필요한 결제수단 활성화 시 PG사 선택 필수 검증
     *
     * needsPgProvider()인 결제수단이 is_active: true인 경우,
     * 해당 결제수단의 pg_provider 또는 default_pg_provider가 설정되어 있어야 합니다.
     *
     * @param  Validator  $validator  Validator 인스턴스
     */
    protected function validatePgRequiredForActivation(Validator $validator): void
    {
        $methods = $this->input('order_settings.payment_methods', []);
        $defaultPg = $this->input('order_settings.default_pg_provider');

        if (empty($methods) || ! is_array($methods)) {
            return;
        }

        foreach ($methods as $index => $method) {
            $id = $method['id'] ?? '';
            $isActive = $method['is_active'] ?? false;
            $pgProvider = $method['pg_provider'] ?? null;

            $enum = PaymentMethodEnum::tryFrom($id);
            if ($enum && $enum->needsPgProvider() && $isActive && ! $pgProvider && ! $defaultPg) {
                $validator->errors()->add(
                    "order_settings.payment_methods.{$index}.pg_provider",
                    __('sirsoft-ecommerce::validation.custom.order_settings.payment_methods.pg_required_for_activation')
                );
            }
        }
    }

    /**
     * 무통장 계좌 기본+사용 설정 검증
     *
     * 계좌가 존재하는 경우 하나 이상은 기본 선택 + 사용 선택 상태여야 합니다.
     *
     * @param  Validator  $validator  Validator 인스턴스
     */
    protected function validateBankAccountDefaults(Validator $validator): void
    {
        $bankAccounts = $this->input('order_settings.bank_accounts', []);

        if (empty($bankAccounts) || ! is_array($bankAccounts)) {
            return;
        }

        $hasActiveDefault = false;
        foreach ($bankAccounts as $account) {
            if (! empty($account['is_active']) && ! empty($account['is_default'])) {
                $hasActiveDefault = true;
                break;
            }
        }

        if (! $hasActiveDefault) {
            $validator->errors()->add(
                'order_settings.bank_accounts',
                __('sirsoft-ecommerce::validation.custom.order_settings.bank_accounts.at_least_one_active_default')
            );
        }
    }

    /**
     * 배송가능국가 코드 중복 검증
     *
     * @param  Validator  $validator  Validator 인스턴스
     */
    protected function validateUniqueCountryCodes(Validator $validator): void
    {
        $countries = $this->input('shipping.available_countries', []);

        if (empty($countries) || ! is_array($countries)) {
            return;
        }

        $codes = [];
        foreach ($countries as $index => $country) {
            if (! isset($country['code'])) {
                continue;
            }

            $code = strtoupper(trim($country['code']));
            if (in_array($code, $codes)) {
                $validator->errors()->add(
                    "shipping.available_countries.{$index}.code",
                    __('sirsoft-ecommerce::validation.custom.shipping.available_countries.duplicate_code')
                );
            }
            $codes[] = $code;
        }
    }

    /**
     * 배송가능국가 이름 필수 검증 - 최소 하나의 로케일에 이름이 있어야 함
     *
     * @param  Validator  $validator  Validator 인스턴스
     */
    protected function validateCountryNames(Validator $validator): void
    {
        $countries = $this->input('shipping.available_countries', []);

        if (empty($countries) || ! is_array($countries)) {
            return;
        }

        foreach ($countries as $index => $country) {
            $name = $country['name'] ?? [];

            if (! is_array($name) || empty($name)) {
                $validator->errors()->add(
                    "shipping.available_countries.{$index}.name",
                    __('sirsoft-ecommerce::validation.custom.shipping.available_countries.name_required')
                );

                continue;
            }

            $hasValidName = false;
            foreach ($name as $localeName) {
                if (is_string($localeName) && trim($localeName) !== '') {
                    $hasValidName = true;
                    break;
                }
            }

            if (! $hasValidName) {
                $validator->errors()->add(
                    "shipping.available_countries.{$index}.name",
                    __('sirsoft-ecommerce::validation.custom.shipping.available_countries.name_required')
                );
            }
        }
    }

    /**
     * 기본 국가가 배송가능국가 목록에 존재하는지 검증
     *
     * @param  Validator  $validator  Validator 인스턴스
     */
    protected function validateDefaultCountryExists(Validator $validator): void
    {
        $defaultCountry = $this->input('shipping.default_country');
        $countries = $this->input('shipping.available_countries', []);

        if (empty($defaultCountry) || empty($countries) || ! is_array($countries)) {
            return;
        }

        $codes = array_map(
            fn ($c) => strtoupper(trim($c['code'] ?? '')),
            $countries
        );

        if (! in_array(strtoupper(trim($defaultCountry)), $codes)) {
            $validator->errors()->add(
                'shipping.default_country',
                __('sirsoft-ecommerce::validation.custom.shipping.default_country.must_exist_in_countries')
            );
        }
    }

    /**
     * 배송사 코드 중복 검증
     *
     * @param  Validator  $validator  Validator 인스턴스
     */
    protected function validateUniqueCarrierCodes(Validator $validator): void
    {
        $carriers = $this->input('shipping.carriers', []);

        if (empty($carriers) || ! is_array($carriers)) {
            return;
        }

        $codes = [];
        foreach ($carriers as $index => $carrier) {
            if (! isset($carrier['code'])) {
                continue;
            }

            $code = strtolower(trim($carrier['code']));
            if (in_array($code, $codes)) {
                $validator->errors()->add(
                    "shipping.carriers.{$index}.code",
                    __('sirsoft-ecommerce::validation.custom.shipping.carriers.duplicate_code')
                );
            }
            $codes[] = $code;
        }
    }

    /**
     * 배송사명 필수 검증 - ko 로케일에 이름이 있어야 함
     *
     * @param  Validator  $validator  Validator 인스턴스
     */
    protected function validateCarrierNames(Validator $validator): void
    {
        $carriers = $this->input('shipping.carriers', []);

        if (empty($carriers) || ! is_array($carriers)) {
            return;
        }

        foreach ($carriers as $index => $carrier) {
            $name = $carrier['name'] ?? [];

            if (! is_array($name) || empty($name)) {
                $validator->errors()->add(
                    "shipping.carriers.{$index}.name",
                    __('sirsoft-ecommerce::validation.custom.shipping.carriers.name_required')
                );

                continue;
            }

            $locale = app()->getLocale();
            $localeName = $name[$locale] ?? '';
            if (! is_string($localeName) || trim($localeName) === '') {
                $validator->errors()->add(
                    "shipping.carriers.{$index}.name.{$locale}",
                    __('sirsoft-ecommerce::validation.custom.shipping.carriers.name_required')
                );
            }
        }
    }

    /**
     * 배송유형 코드 중복 검증
     *
     * @param  Validator  $validator  Validator 인스턴스
     */
    protected function validateUniqueShippingTypeCodes(Validator $validator): void
    {
        $types = $this->input('shipping.types', []);

        if (empty($types) || ! is_array($types)) {
            return;
        }

        $codes = [];
        foreach ($types as $index => $type) {
            if (! isset($type['code'])) {
                continue;
            }

            $code = strtolower(trim($type['code']));
            if (in_array($code, $codes)) {
                $validator->errors()->add(
                    "shipping.types.{$index}.code",
                    __('sirsoft-ecommerce::validation.custom.shipping.types.duplicate_code')
                );
            }
            $codes[] = $code;
        }
    }

    /**
     * 배송유형명 필수 검증 - ko 로케일에 이름이 있어야 함
     *
     * @param  Validator  $validator  Validator 인스턴스
     */
    protected function validateShippingTypeNames(Validator $validator): void
    {
        $types = $this->input('shipping.types', []);

        if (empty($types) || ! is_array($types)) {
            return;
        }

        foreach ($types as $index => $type) {
            $name = $type['name'] ?? [];

            if (! is_array($name) || empty($name)) {
                $validator->errors()->add(
                    "shipping.types.{$index}.name",
                    __('sirsoft-ecommerce::validation.custom.shipping.types.name_required')
                );

                continue;
            }

            $locale = app()->getLocale();
            $localeName = $name[$locale] ?? '';
            if (! is_string($localeName) || trim($localeName) === '') {
                $validator->errors()->add(
                    "shipping.types.{$index}.name.{$locale}",
                    __('sirsoft-ecommerce::validation.custom.shipping.types.name_required')
                );
            }
        }
    }

    /**
     * 환불 사유 코드 중복 검증
     *
     * @param  Validator  $validator  Validator 인스턴스
     */
    protected function validateUniqueRefundReasonCodes(Validator $validator): void
    {
        $reasons = $this->input('claim.refund_reasons', []);

        if (empty($reasons) || ! is_array($reasons)) {
            return;
        }

        $codes = [];
        foreach ($reasons as $index => $reason) {
            if (! isset($reason['code'])) {
                continue;
            }

            $code = strtolower(trim($reason['code']));
            if (in_array($code, $codes)) {
                $validator->errors()->add(
                    "claim.refund_reasons.{$index}.code",
                    __('sirsoft-ecommerce::validation.custom.claim.refund_reasons.duplicate_code')
                );
            }
            $codes[] = $code;
        }
    }

    /**
     * 환불 사유명 필수 검증 - 현재 로케일에 이름이 있어야 함
     *
     * @param  Validator  $validator  Validator 인스턴스
     */
    protected function validateRefundReasonNames(Validator $validator): void
    {
        $reasons = $this->input('claim.refund_reasons', []);

        if (empty($reasons) || ! is_array($reasons)) {
            return;
        }

        $locale = app()->getLocale();

        foreach ($reasons as $index => $reason) {
            $name = $reason['name'] ?? [];

            if (! is_array($name) || empty($name)) {
                $validator->errors()->add(
                    "claim.refund_reasons.{$index}.name",
                    __('sirsoft-ecommerce::validation.custom.claim.refund_reasons.name_required')
                );

                continue;
            }

            $localeName = $name[$locale] ?? '';
            if (! is_string($localeName) || trim($localeName) === '') {
                $validator->errors()->add(
                    "claim.refund_reasons.{$index}.name.{$locale}",
                    __('sirsoft-ecommerce::validation.custom.claim.refund_reasons.name_required')
                );
            }
        }
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $locale = app()->getLocale();

        return [
            // basic_info 섹션
            'basic_info.shop_name.required' => __('sirsoft-ecommerce::validation.custom.basic_info.shop_name.required'),
            'basic_info.shop_name.string' => __('sirsoft-ecommerce::validation.custom.basic_info.shop_name.string'),
            'basic_info.shop_name.max' => __('sirsoft-ecommerce::validation.custom.basic_info.shop_name.max'),
            'basic_info.route_path.required' => __('sirsoft-ecommerce::validation.custom.basic_info.route_path.required'),
            'basic_info.route_path.string' => __('sirsoft-ecommerce::validation.custom.basic_info.route_path.string'),
            'basic_info.route_path.max' => __('sirsoft-ecommerce::validation.custom.basic_info.route_path.max'),
            'basic_info.no_route.boolean' => __('sirsoft-ecommerce::validation.custom.basic_info.no_route.boolean'),
            'basic_info.company_name.string' => __('sirsoft-ecommerce::validation.custom.basic_info.company_name.string'),
            'basic_info.company_name.max' => __('sirsoft-ecommerce::validation.custom.basic_info.company_name.max'),
            'basic_info.business_number_1.string' => __('sirsoft-ecommerce::validation.custom.basic_info.business_number.string'),
            'basic_info.business_number_1.max' => __('sirsoft-ecommerce::validation.custom.basic_info.business_number.max'),
            'basic_info.business_number_2.string' => __('sirsoft-ecommerce::validation.custom.basic_info.business_number.string'),
            'basic_info.business_number_2.max' => __('sirsoft-ecommerce::validation.custom.basic_info.business_number.max'),
            'basic_info.business_number_3.string' => __('sirsoft-ecommerce::validation.custom.basic_info.business_number.string'),
            'basic_info.business_number_3.max' => __('sirsoft-ecommerce::validation.custom.basic_info.business_number.max'),
            'basic_info.ceo_name.string' => __('sirsoft-ecommerce::validation.custom.basic_info.ceo_name.string'),
            'basic_info.ceo_name.max' => __('sirsoft-ecommerce::validation.custom.basic_info.ceo_name.max'),
            'basic_info.business_type.string' => __('sirsoft-ecommerce::validation.custom.basic_info.business_type.string'),
            'basic_info.business_type.max' => __('sirsoft-ecommerce::validation.custom.basic_info.business_type.max'),
            'basic_info.business_category.string' => __('sirsoft-ecommerce::validation.custom.basic_info.business_category.string'),
            'basic_info.business_category.max' => __('sirsoft-ecommerce::validation.custom.basic_info.business_category.max'),
            'basic_info.zipcode.string' => __('sirsoft-ecommerce::validation.custom.basic_info.zipcode.string'),
            'basic_info.zipcode.max' => __('sirsoft-ecommerce::validation.custom.basic_info.zipcode.max'),
            'basic_info.base_address.string' => __('sirsoft-ecommerce::validation.custom.basic_info.base_address.string'),
            'basic_info.base_address.max' => __('sirsoft-ecommerce::validation.custom.basic_info.base_address.max'),
            'basic_info.detail_address.string' => __('sirsoft-ecommerce::validation.custom.basic_info.detail_address.string'),
            'basic_info.detail_address.max' => __('sirsoft-ecommerce::validation.custom.basic_info.detail_address.max'),
            'basic_info.phone_1.string' => __('sirsoft-ecommerce::validation.custom.basic_info.phone.string'),
            'basic_info.phone_1.max' => __('sirsoft-ecommerce::validation.custom.basic_info.phone.max'),
            'basic_info.phone_2.string' => __('sirsoft-ecommerce::validation.custom.basic_info.phone.string'),
            'basic_info.phone_2.max' => __('sirsoft-ecommerce::validation.custom.basic_info.phone.max'),
            'basic_info.phone_3.string' => __('sirsoft-ecommerce::validation.custom.basic_info.phone.string'),
            'basic_info.phone_3.max' => __('sirsoft-ecommerce::validation.custom.basic_info.phone.max'),
            'basic_info.fax_1.string' => __('sirsoft-ecommerce::validation.custom.basic_info.fax.string'),
            'basic_info.fax_1.max' => __('sirsoft-ecommerce::validation.custom.basic_info.fax.max'),
            'basic_info.fax_2.string' => __('sirsoft-ecommerce::validation.custom.basic_info.fax.string'),
            'basic_info.fax_2.max' => __('sirsoft-ecommerce::validation.custom.basic_info.fax.max'),
            'basic_info.fax_3.string' => __('sirsoft-ecommerce::validation.custom.basic_info.fax.string'),
            'basic_info.fax_3.max' => __('sirsoft-ecommerce::validation.custom.basic_info.fax.max'),
            'basic_info.email_id.string' => __('sirsoft-ecommerce::validation.custom.basic_info.email_id.string'),
            'basic_info.email_id.max' => __('sirsoft-ecommerce::validation.custom.basic_info.email_id.max'),
            'basic_info.email_domain.string' => __('sirsoft-ecommerce::validation.custom.basic_info.email_domain.string'),
            'basic_info.email_domain.max' => __('sirsoft-ecommerce::validation.custom.basic_info.email_domain.max'),
            'basic_info.privacy_officer.string' => __('sirsoft-ecommerce::validation.custom.basic_info.privacy_officer.string'),
            'basic_info.privacy_officer.max' => __('sirsoft-ecommerce::validation.custom.basic_info.privacy_officer.max'),
            'basic_info.privacy_officer_email.email' => __('sirsoft-ecommerce::validation.custom.basic_info.privacy_officer_email.email'),
            'basic_info.privacy_officer_email.max' => __('sirsoft-ecommerce::validation.custom.basic_info.privacy_officer_email.max'),
            'basic_info.mail_order_number.string' => __('sirsoft-ecommerce::validation.custom.basic_info.mail_order_number.string'),
            'basic_info.mail_order_number.max' => __('sirsoft-ecommerce::validation.custom.basic_info.mail_order_number.max'),
            'basic_info.telecom_number.string' => __('sirsoft-ecommerce::validation.custom.basic_info.telecom_number.string'),
            'basic_info.telecom_number.max' => __('sirsoft-ecommerce::validation.custom.basic_info.telecom_number.max'),

            // language_currency 섹션 (default_language 제거 — A1-⑤, D-LANG)
            'language_currency.default_currency.string' => __('sirsoft-ecommerce::validation.custom.language_currency.default_currency.string'),
            'language_currency.default_currency.max' => __('sirsoft-ecommerce::validation.custom.language_currency.default_currency.max'),
            'language_currency.currencies.*.code.required_with' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.code.required_with'),
            'language_currency.currencies.*.code.string' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.code.string'),
            'language_currency.currencies.*.code.regex' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.code.regex'),

            // mileage 통화 규칙 섹션
            'mileage.currency_rules.*.currency_code.required_with' => __('sirsoft-ecommerce::validation.custom.mileage.currency_rules.currency_code.required_with'),
            'mileage.currency_rules.*.currency_code.regex' => __('sirsoft-ecommerce::validation.custom.mileage.currency_rules.currency_code.regex'),
            'mileage.currency_rules.*.point_value.numeric' => __('sirsoft-ecommerce::validation.custom.mileage.currency_rules.point_value.numeric'),
            'mileage.currency_rules.*.point_value.min' => __('sirsoft-ecommerce::validation.custom.mileage.currency_rules.point_value.min'),
            'mileage.currency_rules.*.max_use_value.integer' => __('sirsoft-ecommerce::validation.custom.mileage.currency_rules.max_use_value.integer'),
            'mileage.currency_rules.*.max_use_value.min' => __('sirsoft-ecommerce::validation.custom.mileage.currency_rules.max_use_value.min'),
            'mileage.currency_rules.*.max_use_value.max' => __('sirsoft-ecommerce::validation.custom.mileage.currency_rules.max_use_value.max'),
            'language_currency.currencies.*.name.required_with' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.name.required_with'),
            'language_currency.currencies.*.name.array' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.name.array'),
            'language_currency.currencies.*.name.*.string' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.name.string'),
            'language_currency.currencies.*.name.*.max' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.name.max'),
            'language_currency.currencies.*.exchange_rate.numeric' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.exchange_rate.numeric'),
            'language_currency.currencies.*.exchange_rate.min' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.exchange_rate.min'),
            'language_currency.currencies.*.rounding_unit.string' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.rounding_unit.string'),
            'language_currency.currencies.*.rounding_method.string' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.rounding_method.string'),
            'language_currency.currencies.*.rounding_method.in' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.rounding_method.in'),
            'language_currency.currencies.*.decimal_places.integer' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.decimal_places.integer'),
            'language_currency.currencies.*.decimal_places.min' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.decimal_places.min'),
            'language_currency.currencies.*.decimal_places.max' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.decimal_places.max'),
            'language_currency.currencies.*.is_default.boolean' => __('sirsoft-ecommerce::validation.custom.language_currency.currencies.is_default.boolean'),

            // seo 섹션
            'seo.meta_category_title.string' => __('sirsoft-ecommerce::validation.custom.seo.meta_category_title.string'),
            'seo.meta_category_title.max' => __('sirsoft-ecommerce::validation.custom.seo.meta_category_title.max'),
            'seo.meta_category_description.string' => __('sirsoft-ecommerce::validation.custom.seo.meta_category_description.string'),
            'seo.meta_category_description.max' => __('sirsoft-ecommerce::validation.custom.seo.meta_category_description.max'),
            'seo.meta_search_title.string' => __('sirsoft-ecommerce::validation.custom.seo.meta_search_title.string'),
            'seo.meta_search_title.max' => __('sirsoft-ecommerce::validation.custom.seo.meta_search_title.max'),
            'seo.meta_search_description.string' => __('sirsoft-ecommerce::validation.custom.seo.meta_search_description.string'),
            'seo.meta_search_description.max' => __('sirsoft-ecommerce::validation.custom.seo.meta_search_description.max'),
            'seo.meta_product_title.string' => __('sirsoft-ecommerce::validation.custom.seo.meta_product_title.string'),
            'seo.meta_product_title.max' => __('sirsoft-ecommerce::validation.custom.seo.meta_product_title.max'),
            'seo.meta_product_description.string' => __('sirsoft-ecommerce::validation.custom.seo.meta_product_description.string'),
            'seo.meta_product_description.max' => __('sirsoft-ecommerce::validation.custom.seo.meta_product_description.max'),
            'seo.seo_category.boolean' => __('sirsoft-ecommerce::validation.custom.seo.seo_category.boolean'),
            'seo.seo_search_result.boolean' => __('sirsoft-ecommerce::validation.custom.seo.seo_search_result.boolean'),
            'seo.seo_product_detail.boolean' => __('sirsoft-ecommerce::validation.custom.seo.seo_product_detail.boolean'),
            'seo.meta_shop_index_title.string' => __('sirsoft-ecommerce::validation.custom.seo.meta_shop_index_title.string'),
            'seo.meta_shop_index_title.max' => __('sirsoft-ecommerce::validation.custom.seo.meta_shop_index_title.max'),
            'seo.meta_shop_index_description.string' => __('sirsoft-ecommerce::validation.custom.seo.meta_shop_index_description.string'),
            'seo.meta_shop_index_description.max' => __('sirsoft-ecommerce::validation.custom.seo.meta_shop_index_description.max'),
            'seo.seo_shop_index.boolean' => __('sirsoft-ecommerce::validation.custom.seo.seo_shop_index.boolean'),

            // order_settings 섹션 - banks
            'order_settings.banks.*.code.required_with' => __('sirsoft-ecommerce::validation.custom.banks.code.required_with'),
            'order_settings.banks.*.code.string' => __('sirsoft-ecommerce::validation.custom.banks.code.string'),
            'order_settings.banks.*.code.max' => __('sirsoft-ecommerce::validation.custom.banks.code.max'),
            'order_settings.banks.*.name.required_with' => __('sirsoft-ecommerce::validation.custom.banks.name.required_with'),
            'order_settings.banks.*.name.array' => __('sirsoft-ecommerce::validation.custom.banks.name.array'),
            "order_settings.banks.*.name.{$locale}.required_with" => __('sirsoft-ecommerce::validation.custom.banks.name.required_with'),
            "order_settings.banks.*.name.{$locale}.string" => __('sirsoft-ecommerce::validation.custom.banks.name.string'),
            "order_settings.banks.*.name.{$locale}.max" => __('sirsoft-ecommerce::validation.custom.banks.name.max'),
            'order_settings.banks.*.name.*.string' => __('sirsoft-ecommerce::validation.custom.banks.name.string'),
            'order_settings.banks.*.name.*.max' => __('sirsoft-ecommerce::validation.custom.banks.name.max'),

            // order_settings 섹션 - payment_methods
            'order_settings.payment_methods.*.id.required_with' => __('sirsoft-ecommerce::validation.custom.order_settings.payment_methods.id.required_with'),
            'order_settings.payment_methods.*.id.string' => __('sirsoft-ecommerce::validation.custom.order_settings.payment_methods.id.string'),
            'order_settings.payment_methods.*.sort_order.integer' => __('sirsoft-ecommerce::validation.custom.order_settings.payment_methods.sort_order.integer'),
            'order_settings.payment_methods.*.sort_order.min' => __('sirsoft-ecommerce::validation.custom.order_settings.payment_methods.sort_order.min'),
            'order_settings.payment_methods.*.is_active.boolean' => __('sirsoft-ecommerce::validation.custom.order_settings.payment_methods.is_active.boolean'),
            'order_settings.payment_methods.*.min_order_amount.integer' => __('sirsoft-ecommerce::validation.custom.order_settings.payment_methods.min_order_amount.integer'),
            'order_settings.payment_methods.*.min_order_amount.min' => __('sirsoft-ecommerce::validation.custom.order_settings.payment_methods.min_order_amount.min'),
            'order_settings.payment_methods.*.stock_deduction_timing.string' => __('sirsoft-ecommerce::validation.custom.order_settings.payment_methods.stock_deduction_timing.string'),
            'order_settings.payment_methods.*.stock_deduction_timing.in' => __('sirsoft-ecommerce::validation.custom.order_settings.payment_methods.stock_deduction_timing.in'),
            'order_settings.bank_accounts.*.bank_code.required_with' => __('sirsoft-ecommerce::validation.custom.order_settings.bank_accounts.bank_code.required_with'),
            'order_settings.bank_accounts.*.account_number.required_with' => __('sirsoft-ecommerce::validation.custom.order_settings.bank_accounts.account_number.required_with'),
            'order_settings.bank_accounts.*.account_holder.required_with' => __('sirsoft-ecommerce::validation.custom.order_settings.bank_accounts.account_holder.required_with'),
            'order_settings.bank_accounts.*.bank_code.string' => __('sirsoft-ecommerce::validation.custom.order_settings.bank_accounts.bank_code.string'),
            'order_settings.bank_accounts.*.account_number.string' => __('sirsoft-ecommerce::validation.custom.order_settings.bank_accounts.account_number.string'),
            'order_settings.bank_accounts.*.account_number.max' => __('sirsoft-ecommerce::validation.custom.order_settings.bank_accounts.account_number.max'),
            'order_settings.bank_accounts.*.account_holder.string' => __('sirsoft-ecommerce::validation.custom.order_settings.bank_accounts.account_holder.string'),
            'order_settings.bank_accounts.*.account_holder.max' => __('sirsoft-ecommerce::validation.custom.order_settings.bank_accounts.account_holder.max'),
            'order_settings.bank_accounts.*.is_active.boolean' => __('sirsoft-ecommerce::validation.custom.order_settings.bank_accounts.is_active.boolean'),
            'order_settings.bank_accounts.*.is_default.boolean' => __('sirsoft-ecommerce::validation.custom.order_settings.bank_accounts.is_default.boolean'),
            'order_settings.auto_cancel_expired.boolean' => __('sirsoft-ecommerce::validation.custom.order_settings.auto_cancel_expired.boolean'),
            'order_settings.auto_cancel_days.integer' => __('sirsoft-ecommerce::validation.custom.order_settings.auto_cancel_days.integer'),
            'order_settings.auto_cancel_days.min' => __('sirsoft-ecommerce::validation.custom.order_settings.auto_cancel_days.min'),
            'order_settings.auto_cancel_days.max' => __('sirsoft-ecommerce::validation.custom.order_settings.auto_cancel_days.max'),
            'order_settings.cart_expiry_days.integer' => __('sirsoft-ecommerce::validation.custom.order_settings.cart_expiry_days.integer'),
            'order_settings.cart_expiry_days.min' => __('sirsoft-ecommerce::validation.custom.order_settings.cart_expiry_days.min'),
            'order_settings.cart_expiry_days.max' => __('sirsoft-ecommerce::validation.custom.order_settings.cart_expiry_days.max'),
            'order_settings.stock_restore_on_cancel.boolean' => __('sirsoft-ecommerce::validation.custom.order_settings.stock_restore_on_cancel.boolean'),

            // shipping 섹션
            'shipping.default_country.string' => __('sirsoft-ecommerce::validation.custom.shipping.default_country.string'),
            'shipping.default_country.max' => __('sirsoft-ecommerce::validation.custom.shipping.default_country.max'),
            'shipping.available_countries.array' => __('sirsoft-ecommerce::validation.custom.shipping.available_countries.array'),
            'shipping.available_countries.*.code.required_with' => __('sirsoft-ecommerce::validation.custom.shipping.available_countries.code.required_with'),
            'shipping.available_countries.*.code.string' => __('sirsoft-ecommerce::validation.custom.shipping.available_countries.code.string'),
            'shipping.available_countries.*.code.max' => __('sirsoft-ecommerce::validation.custom.shipping.available_countries.code.max'),
            'shipping.available_countries.*.name.required_with' => __('sirsoft-ecommerce::validation.custom.shipping.available_countries.name.required_with'),
            'shipping.available_countries.*.name.array' => __('sirsoft-ecommerce::validation.custom.shipping.available_countries.name.array'),
            'shipping.available_countries.*.name.*.string' => __('sirsoft-ecommerce::validation.custom.shipping.available_countries.name.string'),
            'shipping.available_countries.*.name.*.max' => __('sirsoft-ecommerce::validation.custom.shipping.available_countries.name.max'),
            'shipping.available_countries.*.is_active.boolean' => __('sirsoft-ecommerce::validation.custom.shipping.available_countries.is_active.boolean'),
            'shipping.international_shipping_enabled.boolean' => __('sirsoft-ecommerce::validation.custom.shipping.international_shipping_enabled.boolean'),
            'shipping.free_shipping_threshold.integer' => __('sirsoft-ecommerce::validation.custom.shipping.free_shipping_threshold.integer'),
            'shipping.free_shipping_threshold.min' => __('sirsoft-ecommerce::validation.custom.shipping.free_shipping_threshold.min'),
            'shipping.free_shipping_enabled.boolean' => __('sirsoft-ecommerce::validation.custom.shipping.free_shipping_enabled.boolean'),
            'shipping.address_validation_enabled.boolean' => __('sirsoft-ecommerce::validation.custom.shipping.address_validation_enabled.boolean'),
            'shipping.address_api_provider.string' => __('sirsoft-ecommerce::validation.custom.shipping.address_api_provider.string'),
            'shipping.address_api_provider.max' => __('sirsoft-ecommerce::validation.custom.shipping.address_api_provider.max'),

            // shipping.carriers 섹션
            'shipping.carriers.*.code.required_with' => __('sirsoft-ecommerce::validation.custom.shipping.carriers.code.required_with'),
            'shipping.carriers.*.code.string' => __('sirsoft-ecommerce::validation.custom.shipping.carriers.code.string'),
            'shipping.carriers.*.code.max' => __('sirsoft-ecommerce::validation.custom.shipping.carriers.code.max'),
            'shipping.carriers.*.code.regex' => __('sirsoft-ecommerce::validation.custom.shipping.carriers.code.regex'),
            'shipping.carriers.*.name.required_with' => __('sirsoft-ecommerce::validation.custom.shipping.carriers.name.required_with'),
            'shipping.carriers.*.name.array' => __('sirsoft-ecommerce::validation.custom.shipping.carriers.name.array'),
            'shipping.carriers.*.type.required_with' => __('sirsoft-ecommerce::validation.custom.shipping.carriers.type.required_with'),
            'shipping.carriers.*.type.in' => __('sirsoft-ecommerce::validation.custom.shipping.carriers.type.in'),
            'shipping.carriers.*.tracking_url.string' => __('sirsoft-ecommerce::validation.custom.shipping.carriers.tracking_url.string'),
            'shipping.carriers.*.tracking_url.max' => __('sirsoft-ecommerce::validation.custom.shipping.carriers.tracking_url.max'),
            'shipping.carriers.*.is_active.boolean' => __('sirsoft-ecommerce::validation.custom.shipping.carriers.is_active.boolean'),

            // shipping.types 섹션
            'shipping.types.*.code.required_with' => __('sirsoft-ecommerce::validation.custom.shipping.types.code.required_with'),
            'shipping.types.*.code.string' => __('sirsoft-ecommerce::validation.custom.shipping.types.code.string'),
            'shipping.types.*.code.max' => __('sirsoft-ecommerce::validation.custom.shipping.types.code.max'),
            'shipping.types.*.code.regex' => __('sirsoft-ecommerce::validation.custom.shipping.types.code.regex'),
            'shipping.types.*.name.required_with' => __('sirsoft-ecommerce::validation.custom.shipping.types.name.required_with'),
            'shipping.types.*.name.array' => __('sirsoft-ecommerce::validation.custom.shipping.types.name.array'),
            'shipping.types.*.category.required_with' => __('sirsoft-ecommerce::validation.custom.shipping.types.category.required_with'),
            'shipping.types.*.category.in' => __('sirsoft-ecommerce::validation.custom.shipping.types.category.in'),
            'shipping.types.*.is_active.boolean' => __('sirsoft-ecommerce::validation.custom.shipping.types.is_active.boolean'),
        ];
    }

    /**
     * 검증된 데이터에서 카테고리 설정만 추출
     *
     * 최상위 레벨 오염 데이터(email_id, email_domain 등)를 제외하고
     * 유효한 카테고리만 반환합니다.
     *
     * @return array<string, array>
     */
    public function validatedSettings(): array
    {
        $validated = $this->validated();
        $validCategories = ['basic_info', 'language_currency', 'seo', 'order_settings', 'claim', 'shipping', 'review_settings', 'inquiry', 'notifications', 'mileage'];

        return array_filter(
            $validated,
            fn ($key) => in_array($key, $validCategories),
            ARRAY_FILTER_USE_KEY
        );
    }
}
