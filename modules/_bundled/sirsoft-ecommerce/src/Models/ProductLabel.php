<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 상품 라벨 모델
 */
class ProductLabel extends Model
{
    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'color' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.color', 'type' => 'text'],
        'is_active' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.is_active', 'type' => 'boolean'],
        'sort_order' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.sort_order', 'type' => 'number'],
    ];

    protected $table = 'ecommerce_product_labels';

    protected $fillable = [
        'name',
        'color',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'name' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * 현재 로케일에 맞는 라벨명을 반환합니다.
     *
     * @param string|null $locale 로케일
     * @return string 라벨명
     */
    public function getLocalizedName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $name = $this->name;

        return $name[$locale] ?? $name[config('app.fallback_locale', 'ko')] ?? $name[array_key_first($name)] ?? '';
    }

    /**
     * 라벨 할당 관계
     *
     * @return HasMany 라벨 할당과의 관계
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ProductLabelAssignment::class, 'label_id');
    }

    /**
     * 활성 라벨만 조회하는 스코프
     *
     * @param \Illuminate\Database\Eloquent\Builder $query 쿼리 빌더
     * @return \Illuminate\Database\Eloquent\Builder 쿼리 빌더
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * 정렬 순서대로 조회하는 스코프
     *
     * @param \Illuminate\Database\Eloquent\Builder $query 쿼리 빌더
     * @return \Illuminate\Database\Eloquent\Builder 쿼리 빌더
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
