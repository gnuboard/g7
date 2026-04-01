<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 상품정보제공고시 템플릿 모델
 */
class ProductNoticeTemplate extends Model
{
    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'category' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.category', 'type' => 'text'],
        'is_active' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.is_active', 'type' => 'boolean'],
        'sort_order' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.sort_order', 'type' => 'number'],
    ];

    protected $table = 'ecommerce_product_notice_templates';

    protected $fillable = [
        'name',
        'category',
        'fields',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'name' => 'array',
        'fields' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * 현재 로케일의 템플릿명 반환
     *
     * @param string|null $locale 로케일 (기본값: 현재 앱 로케일)
     * @return string
     */
    public function getLocalizedName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $name = $this->name;

        if (! is_array($name)) {
            return '';
        }

        return $name[$locale] ?? $name['ko'] ?? $name['en'] ?? $name[array_key_first($name)] ?? '';
    }

    /**
     * 활성 템플릿만 조회하는 스코프
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
