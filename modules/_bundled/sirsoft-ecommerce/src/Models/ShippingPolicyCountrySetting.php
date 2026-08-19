<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sirsoft\Ecommerce\Casts\ShippingApiConfigCast;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;

/**
 * 배송정책 국가별 설정 모델
 *
 * 배송정책별로 국가마다 독립적인 배송방법, 부과정책, 배송비를 관리합니다.
 */
class ShippingPolicyCountrySetting extends Model
{
    protected $table = 'ecommerce_shipping_policy_country_settings';

    protected $fillable = [
        'shipping_policy_id',
        'country_code',
        'shipping_method',
        'custom_shipping_name',
        'currency_code',
        'charge_policy',
        'base_fee',
        'free_threshold',
        'ranges',
        'api_endpoint',
        'api_request_fields',
        'api_response_fee_field',
        'api_config',
        'extra_fee_enabled',
        'extra_fee_settings',
        'extra_fee_multiply',
        'is_active',
    ];

    protected $casts = [
        'ranges' => 'array',
        'api_request_fields' => 'array',
        'api_config' => ShippingApiConfigCast::class,
        'extra_fee_settings' => 'array',
        'base_fee' => 'decimal:2',
        'free_threshold' => 'decimal:2',
        'extra_fee_enabled' => 'boolean',
        'extra_fee_multiply' => 'boolean',
        'is_active' => 'boolean',
        'custom_shipping_name' => 'array',
        'charge_policy' => ChargePolicyEnum::class,
    ];

    /**
     * 소속 배송정책
     *
     * @return BelongsTo
     */
    public function shippingPolicy(): BelongsTo
    {
        return $this->belongsTo(ShippingPolicy::class);
    }

    /**
     * 배송비 요약 텍스트 반환
     *
     * @return string
     */
    public function getFeeSummary(): string
    {
        $chargePolicy = $this->charge_policy;

        return match ($chargePolicy) {
            ChargePolicyEnum::FREE => __('sirsoft-ecommerce::messages.shipping_policy.fee_summary.free'),
            ChargePolicyEnum::FIXED => $this->formatFixedFeeSummary(),
            ChargePolicyEnum::CONDITIONAL_FREE => $this->formatConditionalFreeSummary(),
            ChargePolicyEnum::RANGE_AMOUNT,
            ChargePolicyEnum::RANGE_QUANTITY,
            ChargePolicyEnum::RANGE_WEIGHT,
            ChargePolicyEnum::RANGE_VOLUME,
            ChargePolicyEnum::RANGE_VOLUME_WEIGHT => $this->formatRangeFeeSummary(),
            ChargePolicyEnum::API => __('sirsoft-ecommerce::messages.shipping_policy.fee_summary.api'),
            ChargePolicyEnum::PER_QUANTITY,
            ChargePolicyEnum::PER_WEIGHT,
            ChargePolicyEnum::PER_VOLUME,
            ChargePolicyEnum::PER_VOLUME_WEIGHT,
            ChargePolicyEnum::PER_AMOUNT => $this->formatPerUnitFeeSummary(),
            default => '',
        };
    }

    /**
     * 고정 배송비 요약 포맷
     *
     * @return string
     */
    protected function formatFixedFeeSummary(): string
    {
        return __('sirsoft-ecommerce::messages.shipping_policy.fee_summary.fixed', [
            'fee' => ecommerce_format_price($this->base_fee ?? 0),
        ]);
    }

    /**
     * 조건부 무료 배송비 요약 포맷
     *
     * @return string
     */
    protected function formatConditionalFreeSummary(): string
    {
        return __('sirsoft-ecommerce::messages.shipping_policy.fee_summary.conditional', [
            'threshold' => ecommerce_format_price($this->free_threshold ?? 0),
            'fee' => ecommerce_format_price($this->base_fee ?? 0),
        ]);
    }

    /**
     * 구간별 배송비 요약 포맷
     *
     * @return string
     */
    protected function formatRangeFeeSummary(): string
    {
        if (empty($this->ranges) || empty($this->ranges['tiers'])) {
            return '';
        }

        $parts = [];

        foreach ($this->ranges['tiers'] as $tier) {
            $fee = ecommerce_format_price($tier['fee'] ?? 0);
            $parts[] = $this->formatTierRangeLabel($tier).": {$fee}";
        }

        return implode(' / ', $parts);
    }

    /**
     * 구간의 범위 라벨을 포맷합니다 (예: "~5개", "6개~", "2~5kg").
     *
     * 단위는 부과정책에서 파생합니다. tier 의 `unit` 키는 시더만 채우던 값이라
     * 관리자 화면에서 저장한 정책에는 존재하지 않아 단위가 사라졌습니다.
     *
     * @param  array  $tier  구간 정의
     * @return string 범위 라벨
     */
    protected function formatTierRangeLabel(array $tier): string
    {
        $min = $tier['min'] ?? 0;
        $max = $tier['max'] ?? null;
        $isAmount = $this->charge_policy === ChargePolicyEnum::RANGE_AMOUNT;

        $format = fn ($value) => $isAmount
            ? ecommerce_format_price($value ?? 0)
            : $this->formatRangeNumber($value).$this->resolveRangeUnitLabel();

        // 첫 구간(시작 0)은 상한만, 마지막 구간(상한 없음)은 시작값만 표기
        if ((float) $min == 0.0) {
            return $max === null || $max === '' ? '~' : '~'.$format($max);
        }

        if ($max === null || $max === '') {
            return $format($min).'~';
        }

        return $isAmount
            ? $format($min).'~'.$format($max)
            : $this->formatRangeNumber($min).'~'.$format($max);
    }

    /**
     * 부과정책에서 구간 단위 라벨을 파생합니다.
     *
     * @return string 단위 라벨 (해당 없으면 빈 문자열)
     */
    protected function resolveRangeUnitLabel(): string
    {
        $key = match ($this->charge_policy) {
            ChargePolicyEnum::RANGE_QUANTITY => 'quantity',
            ChargePolicyEnum::RANGE_WEIGHT, ChargePolicyEnum::RANGE_VOLUME_WEIGHT => 'weight',
            ChargePolicyEnum::RANGE_VOLUME => 'volume',
            default => null,
        };

        return $key === null
            ? ''
            : __('sirsoft-ecommerce::messages.shipping_policy.fee_summary.range_unit.'.$key);
    }

    /**
     * 구간 경계값을 소수 손실 없이 포맷합니다.
     *
     * number_format 은 기본 소수 0자리라 0.5kg 이 "1" 로 표시됩니다.
     *
     * @param  int|float|string|null  $value  경계값
     * @return string 포맷된 숫자
     */
    protected function formatRangeNumber(int|float|string|null $value): string
    {
        $number = (float) ($value ?? 0);

        if (floor($number) == $number) {
            return number_format($number);
        }

        // 불필요한 뒤쪽 0 제거 (2.50 → 2.5)
        return rtrim(rtrim(number_format($number, 3), '0'), '.');
    }

    /**
     * 단위당 배송비 요약 포맷
     *
     * @return string
     */
    protected function formatPerUnitFeeSummary(): string
    {
        $unitValue = $this->ranges['unit_value'] ?? 1;

        // 금액당 정책의 단위값은 금액이므로 통화 표기를 붙인다 (그 외는 개/kg/L 로 문구가 단위를 갖는다)
        $unit = $this->charge_policy === ChargePolicyEnum::PER_AMOUNT
            ? ecommerce_format_price($unitValue)
            : $this->formatRangeNumber($unitValue);

        return __('sirsoft-ecommerce::messages.shipping_policy.fee_summary.'.$this->charge_policy->value, [
            'unit' => $unit,
            'fee' => ecommerce_format_price($this->base_fee ?? 0),
        ]);
    }

    /**
     * 배송비 상세 정보 반환 (구조화된 배열)
     *
     * @return array
     */
    public function getDetailedFeeInfo(): array
    {
        return [
            'type' => $this->charge_policy->value,
            'base_fee' => ecommerce_format_price($this->base_fee ?? 0),
            'free_threshold' => $this->free_threshold
                ? ecommerce_format_price($this->free_threshold)
                : null,
            'tiers' => $this->formatTiersArray(),
        ];
    }

    /**
     * 구간별 배송비를 구조화된 배열로 반환
     *
     * @return array|null
     */
    protected function formatTiersArray(): ?array
    {
        if (empty($this->ranges) || empty($this->ranges['tiers'])) {
            return null;
        }

        $result = [];

        foreach ($this->ranges['tiers'] as $tier) {
            $result[] = [
                'range' => $this->formatTierRangeLabel($tier),
                'fee' => ecommerce_format_price($tier['fee'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * 우편번호가 도서산간 지역인지 확인하고 추가배송비를 반환합니다.
     *
     * @param  string|null  $zipcode  우편번호
     * @return int 추가배송비 (도서산간 아닌 경우 0)
     */
    public function getExtraFeeForZipcode(?string $zipcode): int
    {
        if (! $this->extra_fee_enabled || empty($zipcode)) {
            return 0;
        }

        $settings = $this->extra_fee_settings ?? [];
        if (empty($settings)) {
            return 0;
        }

        // 우편번호 정규화 (하이픈 제거)
        $normalizedZipcode = str_replace(['-', ' '], '', $zipcode);

        foreach ($settings as $setting) {
            $pattern = $setting['zipcode'] ?? '';
            $fee = (int) ($setting['fee'] ?? 0);

            if (empty($pattern)) {
                continue;
            }

            // 범위 지원: "63000-63999"
            // 자릿수가 다른 우편번호에서 문자열 비교가 오판정하므로 숫자로 비교한다.
            if (preg_match('/^(\d+)-(\d+)$/', $pattern, $matches)) {
                $start = (int) $matches[1];
                $end = (int) $matches[2];
                $numericZipcode = (int) $normalizedZipcode;
                if ($numericZipcode >= $start && $numericZipcode <= $end) {
                    return $fee;
                }

                continue;
            }

            // 패턴 정규화 (하이픈, 공백 제거 - 범위가 아닌 경우에만)
            $normalizedPattern = str_replace(['-', ' '], '', $pattern);

            // 와일드카드(*) 지원: "63*" → 63으로 시작
            if (str_ends_with($normalizedPattern, '*')) {
                $prefix = substr($normalizedPattern, 0, -1);
                if (str_starts_with($normalizedZipcode, $prefix)) {
                    return $fee;
                }
            }
            // 정확한 일치
            elseif ($normalizedZipcode === $normalizedPattern) {
                return $fee;
            }
        }

        return 0;
    }

    /**
     * 우편번호가 도서산간 지역인지 확인합니다.
     *
     * @param  string|null  $zipcode  우편번호
     * @return bool 도서산간 지역 여부
     */
    public function isRemoteArea(?string $zipcode): bool
    {
        return $this->getExtraFeeForZipcode($zipcode) > 0;
    }
}
