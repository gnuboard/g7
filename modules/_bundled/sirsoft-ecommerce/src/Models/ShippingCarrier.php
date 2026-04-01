<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 배송사 모델
 *
 * 배송사 마스터 데이터를 관리합니다.
 */
class ShippingCarrier extends Model
{
    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'code' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.code', 'type' => 'text'],
        'type' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.type', 'type' => 'text'],
        'tracking_url' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.tracking_url', 'type' => 'text'],
        'is_active' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.is_active', 'type' => 'boolean'],
        'sort_order' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.sort_order', 'type' => 'number'],
    ];

    protected $table = 'ecommerce_shipping_carriers';

    protected $fillable = [
        'code',
        'name',
        'type',
        'tracking_url',
        'is_active',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'name' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    /**
     * 생성자 관계
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    /**
     * 수정자 관계
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }

    /**
     * 현재 로케일의 배송사명 반환
     *
     * @param  string|null  $locale  로케일 (기본값: 현재 앱 로케일)
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
     * 배송 추적 URL 생성
     *
     * @param  string  $trackingNumber  운송장 번호
     * @return string|null 추적 URL (템플릿 없으면 null)
     */
    public function buildTrackingUrl(string $trackingNumber): ?string
    {
        if (empty($this->tracking_url)) {
            return null;
        }

        return str_replace('{tracking_number}', $trackingNumber, $this->tracking_url);
    }

    /**
     * 국내 배송사 여부
     */
    public function isDomestic(): bool
    {
        return $this->type === 'domestic';
    }

    /**
     * 국제 배송사 여부
     */
    public function isInternational(): bool
    {
        return $this->type === 'international';
    }

    /**
     * 활성 배송사만 조회 스코프
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * 정렬 순서대로 조회 스코프
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * 유형별 조회 스코프
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $type  배송사 유형 (domestic, international)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
