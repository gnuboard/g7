<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\PublicOutboundUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\ShippingApiAuthType;
use Modules\Sirsoft\Ecommerce\Enums\ShippingApiHttpMethod;
use Modules\Sirsoft\Ecommerce\Enums\ShippingApiRequestField;
use Modules\Sirsoft\Ecommerce\Enums\ShippingApiResponseType;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingTypeRepositoryInterface;

/**
 * 배송정책 생성 요청
 */
class StoreShippingPolicyRequest extends FormRequest
{
    /**
     * 권한 검사는 라우트의 permission 미들웨어가 담당하므로 FormRequest 레벨은 통과시킵니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 배송 정책 저장 요청의 유효성 검사 규칙을 반환합니다 (훅으로 동적 확장 가능).
     *
     * @return array<string, mixed> 필드별 validation rules
     */
    public function rules(): array
    {
        $rules = [
            // 정책 메타데이터
            'name' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 200)],
            'is_active' => ['required', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],

            // 국가별 설정 (최소 1개 필수)
            'country_settings' => ['required', 'array', 'min:1'],
            'country_settings.*.country_code' => ['required', 'string', 'max:10', 'distinct'],
            'country_settings.*.shipping_method' => [
                'required',
                'string',
                // audit:allow query-unbounded-get reason: 배송유형은 운영자 등록 설정성 테이블 — 허용 어휘 구성용 전량 조회이며 행 수가 데이터 증가에 비례하지 않는다
                Rule::in(app(ShippingTypeRepositoryInterface::class)->getAll()->pluck('code')->toArray()),
            ],
            'country_settings.*.custom_shipping_name' => ['nullable', 'array'],
            'country_settings.*.custom_shipping_name.*' => ['nullable', 'string', 'max:100'],
            // 통화는 서버가 상점 기본 통화로 강제하므로 클라이언트 입력에 의존하지 않는다 (읽기전용 표시).
            'country_settings.*.currency_code' => ['nullable', 'string', 'max:10'],
            'country_settings.*.charge_policy' => ['required', Rule::enum(ChargePolicyEnum::class)],

            // 배송비 관련
            'country_settings.*.base_fee' => ['nullable', 'numeric', 'min:0'],
            'country_settings.*.free_threshold' => ['nullable', 'numeric', 'min:0'],

            // 구간별 설정
            'country_settings.*.ranges' => ['nullable', 'array'],
            'country_settings.*.ranges.type' => ['required_with:country_settings.*.ranges', 'string'],
            'country_settings.*.ranges.unit_value' => ['nullable', 'numeric', 'min:0.01'],
            'country_settings.*.ranges.tiers' => ['nullable', 'array', 'min:1'],
            'country_settings.*.ranges.tiers.*.min' => ['required', 'numeric', 'min:0'],
            'country_settings.*.ranges.tiers.*.max' => ['nullable', 'numeric', 'min:0'],
            'country_settings.*.ranges.tiers.*.fee' => ['required', 'numeric', 'min:0'],

            // API 설정
            'country_settings.*.api_endpoint' => ['nullable', 'url', 'max:500', new PublicOutboundUrl],
            'country_settings.*.api_request_fields' => ['nullable', 'array'],
            // 후보 5종 SSoT(ShippingApiRequestField) 외 필드명 거부 — silent drop 차단
            'country_settings.*.api_request_fields.*' => ['string', 'max:100', Rule::in(ShippingApiRequestField::values())],
            'country_settings.*.api_response_fee_field' => ['nullable', 'string', 'max:100'],

            // 계산 API 고급 설정 (api_config) — 메서드/인증/매핑/응답형식
            'country_settings.*.api_config' => ['nullable', 'array'],
            'country_settings.*.api_config.http_method' => ['nullable', 'string', Rule::in(ShippingApiHttpMethod::values())],
            'country_settings.*.api_config.auth_type' => ['nullable', 'string', Rule::in(ShippingApiAuthType::values())],
            'country_settings.*.api_config.auth_token' => ['nullable', 'string', 'max:1000'],
            // 헤더명: HTTP 토큰 문자만 허용 (CRLF/콜론/공백 차단 — 헤더 인젝션 방지)
            'country_settings.*.api_config.auth_header_name' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/'],
            'country_settings.*.api_config.response_type' => ['nullable', 'string', Rule::in(ShippingApiResponseType::values())],
            'country_settings.*.api_config.response_path' => ['nullable', 'string', 'max:200'],
            'country_settings.*.api_config.skip_ssl_verify' => ['nullable', 'boolean'],
            'country_settings.*.api_config.field_map' => ['nullable', 'array'],
            // 외부 키 이름: 영숫자/언더스코어/하이픈/점만 (헤더·쿼리 인젝션 방지)
            'country_settings.*.api_config.field_map.*' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.\-]+$/'],

            // 도서산간 추가배송비
            'country_settings.*.extra_fee_enabled' => ['required', 'boolean'],
            'country_settings.*.extra_fee_settings' => ['nullable', 'array'],
            'country_settings.*.extra_fee_settings.*.zipcode' => ['required', 'string', 'max:20'],
            'country_settings.*.extra_fee_settings.*.fee' => ['required', 'numeric', 'min:0'],
            'country_settings.*.extra_fee_settings.*.region' => ['nullable', 'string', 'max:100'],
            'country_settings.*.extra_fee_multiply' => ['nullable', 'boolean'],

            // 사용여부
            'country_settings.*.is_active' => ['required', 'boolean'],
        ];

        // 훅을 통한 validation rules 확장
        return HookManager::applyFilters('sirsoft-ecommerce.shipping_policy.store_validation_rules', $rules, $this);
    }

    /**
     * 유효성 검사 실패 시 표시할 다국어 메시지를 반환합니다.
     *
     * @return array<string, string> rule key → 번역된 메시지
     */
    public function messages(): array
    {
        return [
            'name.required' => __('sirsoft-ecommerce::validation.shipping_policy.name.required'),
            'country_settings.required' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.required'),
            'country_settings.min' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.min'),
            'country_settings.*.country_code.required' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.country_code.required'),
            'country_settings.*.country_code.distinct' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.country_code.distinct'),
            'country_settings.*.shipping_method.required' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.shipping_method.required'),
            'country_settings.*.shipping_method.Illuminate\Validation\Rules\Enum' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.shipping_method.in'),
            'country_settings.*.charge_policy.required' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.charge_policy.required'),
            'country_settings.*.charge_policy.Illuminate\Validation\Rules\Enum' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.charge_policy.in'),
            'country_settings.*.base_fee.numeric' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.base_fee.numeric'),
            'country_settings.*.base_fee.min' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.base_fee.min'),
            'country_settings.*.free_threshold.numeric' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.free_threshold.numeric'),
            'country_settings.*.free_threshold.min' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.free_threshold.min'),
            'country_settings.*.api_endpoint.url' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.api_endpoint.url'),
            'country_settings.*.api_request_fields.*.in' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.api_request_fields.in'),
            'country_settings.*.api_config.http_method.in' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.api_config.http_method_in'),
            'country_settings.*.api_config.auth_type.in' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.api_config.auth_type_in'),
            'country_settings.*.api_config.auth_header_name.regex' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.api_config.auth_header_name_format'),
            'country_settings.*.api_config.response_type.in' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.api_config.response_type_in'),
            'country_settings.*.api_config.field_map.*.regex' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.api_config.field_map_format'),
            'country_settings.*.extra_fee_enabled.required' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.extra_fee_enabled.required'),
            'country_settings.*.is_active.required' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.is_active.required'),
            // 구간별 배송비 음수/필수 직결 메시지 (raw key 노출 차단)
            'country_settings.*.ranges.tiers.*.fee.required' => __('sirsoft-ecommerce::validation.shipping_policy.ranges.fee_required'),
            'country_settings.*.ranges.tiers.*.fee.min' => __('sirsoft-ecommerce::validation.shipping_policy.ranges.fee_non_negative'),
            'country_settings.*.ranges.tiers.*.min.min' => __('sirsoft-ecommerce::validation.shipping_policy.ranges.tier_min_non_negative'),
            'country_settings.*.ranges.tiers.*.max.min' => __('sirsoft-ecommerce::validation.shipping_policy.ranges.tier_max_non_negative'),
            'country_settings.*.ranges.unit_value.min' => __('sirsoft-ecommerce::validation.shipping_policy.ranges.unit_value_min'),
            'country_settings.*.ranges.tiers.min' => __('sirsoft-ecommerce::validation.shipping_policy.ranges.tiers_required'),
            'country_settings.*.ranges.tiers.array' => __('sirsoft-ecommerce::validation.shipping_policy.ranges.tiers_required'),
            'country_settings.*.extra_fee_settings.*.zipcode.required' => __('sirsoft-ecommerce::validation.shipping_policy.extra_fee.zipcode_format'),
            'country_settings.*.extra_fee_settings.*.fee.min' => __('sirsoft-ecommerce::validation.extra_fee_template.fee_min'),
            'is_active.required' => __('sirsoft-ecommerce::validation.shipping_policy.is_active_required'),
        ];
    }

    /**
     * 단일 필드 rules 로 표현 불가한 cross-field 검증을 추가합니다.
     *
     * - 구간별 배송비 tiers 의 연속성 (시작=0, 마지막=무제한)
     * - 비무료 정책의 base_fee 0 원 금지
     * - shipping_method=custom 일 때 custom_shipping_name 다국어 필수
     *
     * @param  Validator  $validator  Laravel validator 인스턴스
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validatePolicyRequiredSettings($validator);
            $this->validateRangeTiersContinuity($validator);
            $this->validateNonFreeBaseFee($validator);
            $this->validateCustomShippingName($validator);
            $this->validateApiEndpointRequired($validator);
            $this->validateApiConfig($validator);
            $this->validateExtraFeeZipcodeFormat($validator);
        });
    }

    /**
     * 부과정책이 요구하는 설정값의 존재를 검증합니다 (cross-field).
     *
     * 이 값들이 비어 있으면 계산 결과가 조용히 0원(무료배송)이 되므로 저장 시점에 차단합니다.
     *
     * - 구간별 정책: ranges.tiers 최소 1개
     * - 단위당 정책: ranges.unit_value
     * - 조건부 무료: free_threshold
     *
     * @param  Validator  $validator  Laravel validator 인스턴스
     */
    private function validatePolicyRequiredSettings(Validator $validator): void
    {
        $countrySettings = $this->input('country_settings', []);

        if (! is_array($countrySettings)) {
            return;
        }

        foreach ($countrySettings as $i => $cs) {
            $policy = ChargePolicyEnum::tryFrom($cs['charge_policy'] ?? '');

            if (! $policy) {
                continue;
            }

            if ($policy->requiresRanges()) {
                $tiers = $cs['ranges']['tiers'] ?? null;

                if (! is_array($tiers) || count($tiers) === 0) {
                    $validator->errors()->add(
                        "country_settings.{$i}.ranges.tiers",
                        __('sirsoft-ecommerce::validation.shipping_policy.ranges.tiers_required')
                    );
                }
            }

            if ($policy->requiresUnitValue()) {
                $unitValue = $cs['ranges']['unit_value'] ?? null;

                if ($unitValue === null || $unitValue === '' || ! is_numeric($unitValue)) {
                    $validator->errors()->add(
                        "country_settings.{$i}.ranges.unit_value",
                        __('sirsoft-ecommerce::validation.shipping_policy.ranges.unit_value_required')
                    );
                }
            }

            if ($policy->requiresFreeThreshold()) {
                $freeThreshold = $cs['free_threshold'] ?? null;

                if ($freeThreshold === null || $freeThreshold === '' || ! is_numeric($freeThreshold)) {
                    $validator->errors()->add(
                        "country_settings.{$i}.free_threshold",
                        __('sirsoft-ecommerce::validation.shipping_policy.free_threshold_required')
                    );
                }
            }
        }
    }

    /**
     * 도서산간 우편번호 패턴의 형식을 검증합니다.
     *
     * 지원 형식은 정확일치("63000"), 범위("63000-63999"), 접두 와일드카드("63*") 3종입니다.
     * 그 외 형식은 어떤 우편번호에도 매칭되지 않아 추가배송비가 조용히 누락됩니다.
     *
     * @param  Validator  $validator  Laravel validator 인스턴스
     */
    private function validateExtraFeeZipcodeFormat(Validator $validator): void
    {
        $countrySettings = $this->input('country_settings', []);

        if (! is_array($countrySettings)) {
            return;
        }

        foreach ($countrySettings as $i => $cs) {
            if (empty($cs['extra_fee_enabled'])) {
                continue;
            }

            $settings = $cs['extra_fee_settings'] ?? [];

            if (! is_array($settings)) {
                continue;
            }

            foreach ($settings as $j => $setting) {
                $zipcode = trim((string) ($setting['zipcode'] ?? ''));

                if ($zipcode === '') {
                    continue;
                }

                $isValid = preg_match('/^\d+-\d+$/', $zipcode)
                    || preg_match('/^\d+\*$/', $zipcode)
                    || preg_match('/^\d+$/', $zipcode);

                if (! $isValid) {
                    $validator->errors()->add(
                        "country_settings.{$i}.extra_fee_settings.{$j}.zipcode",
                        __('sirsoft-ecommerce::validation.shipping_policy.extra_fee.zipcode_format')
                    );
                }
            }
        }
    }

    /**
     * 계산 API 정책(api) 선택 시 api_endpoint 필수 검증 (cross-field).
     *
     * 비-API 정책은 base rule 의 nullable 을 유지하여 회귀를 막고, API 정책에 한해 빈
     * endpoint 저장을 차단하여 silent base_fee 폴백을 방지합니다.
     *
     * @param  Validator  $validator  Laravel validator 인스턴스
     */
    private function validateApiEndpointRequired(Validator $validator): void
    {
        $countrySettings = $this->input('country_settings', []);

        if (! is_array($countrySettings)) {
            return;
        }

        foreach ($countrySettings as $i => $cs) {
            $policy = ChargePolicyEnum::tryFrom($cs['charge_policy'] ?? '');

            if ($policy && $policy->requiresApiEndpoint() && trim((string) ($cs['api_endpoint'] ?? '')) === '') {
                $validator->errors()->add(
                    "country_settings.{$i}.api_endpoint",
                    __('sirsoft-ecommerce::validation.shipping_policy.country_settings.api_endpoint.required')
                );
            }
        }
    }

    /**
     * 계산 API 고급 설정(api_config)의 cross-field 검증.
     *
     * - custom_header 인증 시 헤더명 필수
     *
     * @param  Validator  $validator  Laravel validator 인스턴스
     */
    private function validateApiConfig(Validator $validator): void
    {
        $countrySettings = $this->input('country_settings', []);

        if (! is_array($countrySettings)) {
            return;
        }

        foreach ($countrySettings as $i => $cs) {
            $policy = ChargePolicyEnum::tryFrom($cs['charge_policy'] ?? '');

            if (! $policy || ! $policy->requiresApiEndpoint()) {
                continue;
            }

            $config = $cs['api_config'] ?? [];

            // custom_header 인증 시 헤더명 필수
            if (($config['auth_type'] ?? null) === ShippingApiAuthType::CUSTOM_HEADER->value
                && trim((string) ($config['auth_header_name'] ?? '')) === '') {
                $validator->errors()->add(
                    "country_settings.{$i}.api_config.auth_header_name",
                    __('sirsoft-ecommerce::validation.shipping_policy.country_settings.api_config.auth_header_name_required')
                );
            }
        }
    }

    /**
     * 구간별 배송비 tiers의 연속성을 검증합니다.
     *
     * - 첫 구간의 시작값은 0이어야 합니다.
     * - 마지막 구간의 종료값은 null(무제한)이어야 합니다.
     * - 마지막을 제외한 구간에는 종료값이 있어야 합니다 (중간 무제한 구간 금지).
     * - 시작값이 종료값보다 작아야 합니다.
     * - 구간이 연속적이어야 합니다. 연결 규칙은 값의 성질에 따라 다릅니다.
     *   - 이산형(수량): 다음 min === 현재 max + 1
     *   - 연속형(금액/무게/부피): 다음 min === 현재 max (부동소수 오차 허용)
     * - 배송비는 0 이상이어야 합니다.
     */
    private function validateRangeTiersContinuity(Validator $validator): void
    {
        $countrySettings = $this->input('country_settings', []);

        if (! is_array($countrySettings)) {
            return;
        }

        // 연속형 구간의 소수 입력(2.5kg)에서 부동소수 오차로 정상 설정이 거부되지 않도록 허용 오차를 둔다.
        $epsilon = 0.001;

        foreach ($countrySettings as $i => $cs) {
            $tiers = $cs['ranges']['tiers'] ?? null;

            if (! is_array($tiers) || count($tiers) === 0) {
                continue;
            }

            $policy = ChargePolicyEnum::tryFrom($cs['charge_policy'] ?? '');
            $isDiscrete = $policy?->hasDiscreteRangeValues() ?? false;

            // 첫 구간 min은 0이어야 함 (문자열 "0" 도 허용)
            if ((float) ($tiers[0]['min'] ?? null) != 0.0) {
                $validator->errors()->add(
                    "country_settings.{$i}.ranges.tiers.0.min",
                    __('sirsoft-ecommerce::validation.shipping_policy.ranges.first_min_zero')
                );
            }

            // 마지막 구간 max는 null이어야 함
            $lastIdx = count($tiers) - 1;
            if (! $this->isUnlimitedMax($tiers[$lastIdx]['max'] ?? null)) {
                $validator->errors()->add(
                    "country_settings.{$i}.ranges.tiers.{$lastIdx}.max",
                    __('sirsoft-ecommerce::validation.shipping_policy.ranges.last_max_unlimited')
                );
            }

            for ($j = 0; $j < count($tiers); $j++) {
                $tier = $tiers[$j];
                $hasMax = ! $this->isUnlimitedMax($tier['max'] ?? null);

                // 중간 구간의 종료값 누락 금지 (뒤 구간이 영구히 도달 불가해진다)
                if ($j < $lastIdx && ! $hasMax) {
                    $validator->errors()->add(
                        "country_settings.{$i}.ranges.tiers.{$j}.max",
                        __('sirsoft-ecommerce::validation.shipping_policy.ranges.middle_max_required')
                    );

                    continue;
                }

                // 수량 구간의 경계값은 정수
                if ($isDiscrete) {
                    $minValue = $tier['min'] ?? 0;
                    $maxValue = $tier['max'] ?? null;
                    $hasFractionalBoundary = (is_numeric($minValue) && floor((float) $minValue) != (float) $minValue)
                        || ($hasMax && is_numeric($maxValue) && floor((float) $maxValue) != (float) $maxValue);

                    if ($hasFractionalBoundary) {
                        $validator->errors()->add(
                            "country_settings.{$i}.ranges.tiers.{$j}.max",
                            __('sirsoft-ecommerce::validation.shipping_policy.ranges.tier_value_integer')
                        );
                    }
                }

                // min < max (마지막 구간 제외)
                if ($j < $lastIdx && $hasMax) {
                    if ((float) ($tier['min'] ?? 0) >= (float) $tier['max']) {
                        $validator->errors()->add(
                            "country_settings.{$i}.ranges.tiers.{$j}.min",
                            __('sirsoft-ecommerce::validation.shipping_policy.ranges.min_less_than_max')
                        );
                    }
                }

                // 구간 연속성 (이산형은 max + 1, 연속형은 max 가 다음 min)
                if ($j < $lastIdx && $hasMax) {
                    $expectedNextMin = (float) $tier['max'] + ($isDiscrete ? 1 : 0);
                    $actualNextMin = (float) ($tiers[$j + 1]['min'] ?? 0);

                    if (abs($expectedNextMin - $actualNextMin) >= $epsilon) {
                        $validator->errors()->add(
                            "country_settings.{$i}.ranges.tiers.{$j}.max",
                            __('sirsoft-ecommerce::validation.shipping_policy.ranges.continuity')
                        );
                    }
                }

                // fee >= 0
                if ((float) ($tier['fee'] ?? 0) < 0) {
                    $validator->errors()->add(
                        "country_settings.{$i}.ranges.tiers.{$j}.fee",
                        __('sirsoft-ecommerce::validation.shipping_policy.ranges.fee_non_negative')
                    );
                }
            }
        }
    }

    /**
     * 구간의 종료값이 무제한(비어 있음)인지 판정합니다.
     *
     * @param  mixed  $max  종료값
     * @return bool 무제한이면 true
     */
    private function isUnlimitedMax(mixed $max): bool
    {
        return $max === null || $max === '';
    }

    /**
     * 무료배송이 아닌 정책에서 배송비 0원을 금지합니다.
     *
     * 구간별 배송비(RANGE_*) 정책은 tiers에서 배송비를 관리하므로 예외입니다.
     */
    private function validateNonFreeBaseFee(Validator $validator): void
    {
        $countrySettings = $this->input('country_settings', []);

        if (! is_array($countrySettings)) {
            return;
        }

        foreach ($countrySettings as $i => $cs) {
            $chargePolicy = ChargePolicyEnum::tryFrom($cs['charge_policy'] ?? '');

            if (! $chargePolicy) {
                continue;
            }

            // 기본 배송비가 필요한 정책에서만 검증 (FIXED, CONDITIONAL_FREE, PER_*)
            if ($chargePolicy->requiresBaseFee() && (float) ($cs['base_fee'] ?? 0) <= 0) {
                $validator->errors()->add(
                    "country_settings.{$i}.base_fee",
                    __('sirsoft-ecommerce::validation.shipping_policy.base_fee_zero_not_allowed')
                );
            }
        }
    }

    /**
     * custom 배송방법 선택 시 custom_shipping_name 필수 검증
     *
     * @param  Validator  $validator  Validator 인스턴스
     */
    private function validateCustomShippingName(Validator $validator): void
    {
        $countrySettings = $this->input('country_settings', []);

        if (! is_array($countrySettings)) {
            return;
        }

        $locale = app()->getLocale();

        foreach ($countrySettings as $i => $cs) {
            if (($cs['shipping_method'] ?? '') !== 'custom') {
                continue;
            }

            $customName = $cs['custom_shipping_name'] ?? [];
            $localeName = is_array($customName) ? trim($customName[$locale] ?? $customName[config('app.fallback_locale', 'ko')] ?? '') : '';

            if ($localeName === '') {
                $validator->errors()->add(
                    "country_settings.{$i}.custom_shipping_name.{$locale}",
                    __('sirsoft-ecommerce::validation.shipping_policy.custom_shipping_name_required')
                );
            }
        }
    }

    /**
     * 데이터 전처리
     *
     * country_settings 배열을 순회하며 charge_policy에 따라 불필요한 필드를 정리합니다.
     * KR이 아닌 국가는 도서산간 설정을 강제 비활성화합니다.
     */
    protected function prepareForValidation(): void
    {
        $countrySettings = $this->input('country_settings');

        if (! is_array($countrySettings)) {
            return;
        }

        foreach ($countrySettings as $i => $cs) {
            $chargePolicy = ChargePolicyEnum::tryFrom($cs['charge_policy'] ?? '');

            if ($chargePolicy) {
                // 기본 배송비가 불필요한 경우
                if (! $chargePolicy->requiresBaseFee()) {
                    $countrySettings[$i]['base_fee'] = 0;
                }

                // 무료 기준금액이 불필요한 경우
                if (! $chargePolicy->requiresFreeThreshold()) {
                    $countrySettings[$i]['free_threshold'] = null;
                }

                // 구간 설정이 불필요한 경우
                if (! $chargePolicy->requiresRanges() && ! $chargePolicy->requiresUnitValue()) {
                    $countrySettings[$i]['ranges'] = null;
                }

                // API 설정이 불필요한 경우
                if (! $chargePolicy->requiresApiEndpoint()) {
                    $countrySettings[$i]['api_endpoint'] = null;
                    $countrySettings[$i]['api_request_fields'] = null;
                    $countrySettings[$i]['api_response_fee_field'] = null;
                    $countrySettings[$i]['api_config'] = null;
                }
            }

            // 도서산간: KR이 아닌 국가는 강제 비활성
            if (($cs['country_code'] ?? '') !== 'KR') {
                $countrySettings[$i]['extra_fee_enabled'] = false;
                $countrySettings[$i]['extra_fee_settings'] = null;
                $countrySettings[$i]['extra_fee_multiply'] = false;
            }
        }

        $this->merge(['country_settings' => $countrySettings]);
    }
}
