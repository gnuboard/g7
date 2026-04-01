<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use App\Rules\LocaleRequiredTranslatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\ShippingMethodEnum;

/**
 * 배송정책 생성 요청
 */
class StoreShippingPolicyRequest extends FormRequest
{
    /**
     * 권한 확인
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검사 규칙
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
            'country_settings.*.shipping_method' => ['required', Rule::enum(ShippingMethodEnum::class)],
            'country_settings.*.currency_code' => ['required', 'string', 'max:10'],
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
            'country_settings.*.api_endpoint' => ['nullable', 'url', 'max:500'],
            'country_settings.*.api_request_fields' => ['nullable', 'array'],
            'country_settings.*.api_request_fields.*' => ['string', 'max:100'],
            'country_settings.*.api_response_fee_field' => ['nullable', 'string', 'max:100'],

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
     * 유효성 검사 메시지
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
            'country_settings.*.currency_code.required' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.currency_code.required'),
            'country_settings.*.charge_policy.required' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.charge_policy.required'),
            'country_settings.*.charge_policy.Illuminate\Validation\Rules\Enum' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.charge_policy.in'),
            'country_settings.*.base_fee.numeric' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.base_fee.numeric'),
            'country_settings.*.base_fee.min' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.base_fee.min'),
            'country_settings.*.free_threshold.numeric' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.free_threshold.numeric'),
            'country_settings.*.free_threshold.min' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.free_threshold.min'),
            'country_settings.*.api_endpoint.url' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.api_endpoint.url'),
            'country_settings.*.extra_fee_enabled.required' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.extra_fee_enabled.required'),
            'country_settings.*.is_active.required' => __('sirsoft-ecommerce::validation.shipping_policy.country_settings.is_active.required'),
            'is_active.required' => __('sirsoft-ecommerce::validation.shipping_policy.is_active.required'),
        ];
    }

    /**
     * 추가 검증 (구간별 배송비 연속성, 비무료 정책 배송비 0원 금지 등)
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            $this->validateRangeTiersContinuity($validator);
            $this->validateNonFreeBaseFee($validator);
        });
    }

    /**
     * 구간별 배송비 tiers의 연속성을 검증합니다.
     *
     * - 첫 구간의 시작값은 0이어야 합니다.
     * - 마지막 구간의 종료값은 null(무제한)이어야 합니다.
     * - 시작값이 종료값보다 작아야 합니다.
     * - 구간이 연속적이어야 합니다 (현재 max + 1 === 다음 min, 포함 범위 기준).
     * - 배송비는 0 이상이어야 합니다.
     */
    private function validateRangeTiersContinuity(\Illuminate\Validation\Validator $validator): void
    {
        $countrySettings = $this->input('country_settings', []);

        if (! is_array($countrySettings)) {
            return;
        }

        foreach ($countrySettings as $i => $cs) {
            $tiers = $cs['ranges']['tiers'] ?? null;

            if (! is_array($tiers) || count($tiers) === 0) {
                continue;
            }

            // 첫 구간 min은 0이어야 함
            if (($tiers[0]['min'] ?? null) != 0) {
                $validator->errors()->add(
                    "country_settings.{$i}.ranges.tiers.0.min",
                    __('sirsoft-ecommerce::validation.shipping_policy.ranges.first_min_zero')
                );
            }

            // 마지막 구간 max는 null이어야 함
            $lastIdx = count($tiers) - 1;
            if (isset($tiers[$lastIdx]['max']) && $tiers[$lastIdx]['max'] !== null) {
                $validator->errors()->add(
                    "country_settings.{$i}.ranges.tiers.{$lastIdx}.max",
                    __('sirsoft-ecommerce::validation.shipping_policy.ranges.last_max_unlimited')
                );
            }

            for ($j = 0; $j < count($tiers); $j++) {
                $tier = $tiers[$j];

                // min < max (마지막 구간 제외)
                if ($j < $lastIdx && isset($tier['max']) && $tier['max'] !== null) {
                    if ((float) ($tier['min'] ?? 0) >= (float) $tier['max']) {
                        $validator->errors()->add(
                            "country_settings.{$i}.ranges.tiers.{$j}.min",
                            __('sirsoft-ecommerce::validation.shipping_policy.ranges.min_less_than_max')
                        );
                    }
                }

                // 구간 연속성: 현재 max + 1 === 다음 min (포함 범위 기준)
                if ($j < $lastIdx) {
                    $nextTier = $tiers[$j + 1];
                    if (isset($tier['max']) && $tier['max'] !== null
                        && (float) $tier['max'] + 1 !== (float) ($nextTier['min'] ?? 0)) {
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
     * 무료배송이 아닌 정책에서 배송비 0원을 금지합니다.
     *
     * 구간별 배송비(RANGE_*) 정책은 tiers에서 배송비를 관리하므로 예외입니다.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    private function validateNonFreeBaseFee(\Illuminate\Validation\Validator $validator): void
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
