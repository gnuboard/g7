<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 상품 추가옵션 그룹 모델
 */
class ProductAdditionalOption extends Model
{
    protected $table = 'ecommerce_product_additional_options';

    protected $fillable = [
        'product_id',
        'name',
        'is_required',
        'sort_order',
    ];

    protected $casts = [
        'name' => 'array',
        'is_required' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * 상품 관계
     *
     * @return BelongsTo 상품 모델과의 관계
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * 선택지 관계
     *
     * @return HasMany 추가옵션 선택지 모델과의 관계
     */
    public function values(): HasMany
    {
        return $this->hasMany(ProductAdditionalOptionValue::class, 'additional_option_id');
    }

    /**
     * 활성 선택지 관계 (정렬 적용)
     *
     * @return HasMany 활성 선택지 모델과의 관계
     */
    public function activeValues(): HasMany
    {
        return $this->values()->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * 현재 로케일의 옵션명 반환
     *
     * @param  string|null  $locale  로케일
     * @return string 옵션명
     */
    public function getLocalizedName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $name = $this->name;

        return $name[$locale] ?? $name[config('app.fallback_locale', 'ko')] ?? $name[array_key_first($name)] ?? '';
    }
}
