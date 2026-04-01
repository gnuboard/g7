<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\ShippingMethodEnum;

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
        'currency_code',
        'charge_policy',
        'base_fee',
        'free_threshold',
        'ranges',
        'api_endpoint',
        'api_request_fields',
        'api_response_fee_field',
        'extra_fee_enabled',
        'extra_fee_settings',
        'extra_fee_multiply',
        'is_active',
    ];

    protected $casts = [
        'ranges' => 'array',
        'api_request_fields' => 'array',
        'extra_fee_settings' => 'array',
        'base_fee' => 'decimal:2',
        'free_threshold' => 'decimal:2',
        'extra_fee_enabled' => 'boolean',
        'extra_fee_multiply' => 'boolean',
        'is_active' => 'boolean',
        'shipping_method' => ShippingMethodEnum::class,
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

        $tiers = $this->ranges['tiers'];
        $unit = $tiers[0]['unit'] ?? '';
        $parts = [];

        foreach ($tiers as $tier) {
            $min = $tier['min'] ?? 0;
            $max = $tier['max'] ?? null;
            $fee = ecommerce_format_price($tier['fee'] ?? 0);

            if ($min === 0) {
                $range = "~{$max}{$unit}";
            } elseif ($max === null) {
                $range = "{$min}{$unit}~";
            } else {
                $range = "{$min}~{$max}{$unit}";
            }

            $parts[] = "{$range}: {$fee}";
        }

        return implode(' / ', $parts);
    }

    /**
     * 단위당 배송비 요약 포맷
     *
     * @return string
     */
    protected function formatPerUnitFeeSummary(): string
    {
        $unitValue = $this->ranges['unit_value'] ?? 1;

        return __('sirsoft-ecommerce::messages.shipping_policy.fee_summary.'.$this->charge_policy->value, [
            'unit' => number_format($unitValue),
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
            'base_fee' => number_format($this->base_fee).'원',
            'free_threshold' => $this->free_threshold
                ? number_format($this->free_threshold).'원'
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

        $tiers = $this->ranges['tiers'];
        $unit = $tiers[0]['unit'] ?? '';
        $result = [];

        foreach ($tiers as $tier) {
            $min = $tier['min'] ?? 0;
            $max = $tier['max'] ?? null;
            $fee = $tier['fee'] ?? 0;

            if ($min === 0) {
                $range = '~'.number_format($max).$unit;
            } elseif ($max === null) {
                $range = number_format($min).$unit.'~';
            } else {
                $range = number_format($min).'~'.number_format($max).$unit;
            }

            $result[] = [
                'range' => $range,
                'fee' => number_format($fee).'원',
            ];
        }

        return $result;
    }

    /**
     * 우편번호가 도서산간 지역인지 확인하고 추가배송비를 반환합니다.
     *
     * @param string|null $zipcode 우편번호
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
            if (preg_match('/^(\d+)-(\d+)$/', $pattern, $matches)) {
                $start = $matches[1];
                $end = $matches[2];
                if ($normalizedZipcode >= $start && $normalizedZipcode <= $end) {
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
     * @param string|null $zipcode 우편번호
     * @return bool 도서산간 지역 여부
     */
    public function isRemoteArea(?string $zipcode): bool
    {
        return $this->getExtraFeeForZipcode($zipcode) > 0;
    }
}
