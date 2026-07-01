<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 상품 추가옵션 선택지 모델
 *
 * 추가옵션 그룹(ProductAdditionalOption)의 하위 선택지로,
 * 각 선택지는 KRW 기준 추가금(price_adjustment)을 보유합니다.
 */
class ProductAdditionalOptionValue extends Model
{
    protected $table = 'ecommerce_product_additional_option_values';

    protected $fillable = [
        'additional_option_id',
        'name',
        'price_adjustment',
        'mc_price_adjustment',
        'is_default',
        'is_active',
        'allow_custom_text',
        'sort_order',
    ];

    protected $casts = [
        'additional_option_id' => 'integer',
        'name' => 'array',
        'price_adjustment' => 'integer',
        'mc_price_adjustment' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'allow_custom_text' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * 추가옵션 그룹 관계
     *
     * @return BelongsTo 추가옵션 그룹 모델과의 관계
     */
    public function additionalOption(): BelongsTo
    {
        return $this->belongsTo(ProductAdditionalOption::class, 'additional_option_id');
    }

    /**
     * 현재 로케일의 선택지명 반환
     *
     * @param  string|null  $locale  로케일 (null이면 현재 로케일)
     * @return string 로컬라이즈된 선택지명
     */
    public function getLocalizedName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $name = $this->name;

        if (! is_array($name)) {
            return is_string($name) ? $name : '';
        }

        return $name[$locale] ?? $name[config('app.fallback_locale', 'ko')] ?? $name[array_key_first($name)] ?? '';
    }

    /**
     * 추가금 반환 (KRW 기준)
     *
     * @return int 추가금
     */
    public function getPriceAdjustment(): int
    {
        return (int) ($this->price_adjustment ?? 0);
    }

    /**
     * 주문 스냅샷용 배열 반환
     *
     * 주문 생성 시점의 선택지 정보를 동결하기 위한 데이터입니다.
     *
     * @return array 스냅샷 데이터
     */
    public function toSnapshotArray(): array
    {
        return [
            'additional_option_id' => (int) $this->additional_option_id,
            'value_id' => (int) $this->id,
            'name' => $this->name,
            'price_adjustment' => $this->getPriceAdjustment(),
            'mc_price_adjustment' => $this->mc_price_adjustment,
        ];
    }
}
