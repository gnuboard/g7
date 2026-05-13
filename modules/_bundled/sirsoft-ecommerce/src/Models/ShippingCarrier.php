<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\Concerns\HasUserOverrides;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 배송사 모델
 *
 * 배송사 마스터 데이터를 관리합니다.
 *
 * ShippingCarrierSeeder 는 GenericEntitySyncHelper 기반으로 동작하므로,
 * 사용자가 관리자 UI 에서 수정한 필드는 `user_overrides` 에 기록되어
 * 재설치/재시드 시 보존됩니다.
 */
class ShippingCarrier extends Model
{
    use HasUserOverrides;

    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'code' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.code', 'type' => 'text'],
        'type' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.type', 'type' => 'text'],
        'tracking_url' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.tracking_url', 'type' => 'text'],
        'is_active' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.is_active', 'type' => 'boolean'],
        'sort_order' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.sort_order', 'type' => 'number'],
    ];

    /** @var list<string> 사용자 수정 추적 필드 (시더 재실행 시 보존 대상) */
    protected array $trackableFields = [
        'name',
        'type',
        'tracking_url',
        'is_active',
        'sort_order',
    ];

    /**
     * 다국어 JSON 컬럼 — sub-key dot-path 단위 user_overrides 보존.
     *
     * @var array<int, string>
     */
    protected array $translatableTrackableFields = ['name'];

    protected $table = 'ecommerce_shipping_carriers';

    protected $fillable = [
        'code',
        'name',
        'type',
        'tracking_url',
        'is_active',
        'sort_order',
        'user_overrides',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'name' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'user_overrides' => 'array',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    /**
     * 배송사 레코드를 생성한 사용자 관계 (created_by FK).
     *
     * @return BelongsTo 생성자 사용자 관계
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    /**
     * 배송사 레코드를 마지막으로 수정한 사용자 관계 (updated_by FK).
     *
     * @return BelongsTo 수정자 사용자 관계
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }

    /**
     * 현재 로케일의 배송사명을 반환합니다 (다국어 fallback chain 적용).
     *
     * @param  string|null  $locale  반환할 로케일. null 이면 현재 앱 로케일 사용
     * @return string 로케일별 배송사명, 누락 시 fallback 로케일/첫 번째 키 순으로 시도
     */
    public function getLocalizedName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $name = $this->name;

        if (! is_array($name)) {
            return '';
        }

        return $name[$locale] ?? $name[config('app.fallback_locale', 'ko')] ?? $name[array_key_first($name)] ?? '';
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
     * 국내 배송사 여부 (type 이 'domestic' 인지) 를 반환합니다.
     *
     * @return bool 국내 배송사면 true
     */
    public function isDomestic(): bool
    {
        return $this->type === 'domestic';
    }

    /**
     * 국제 배송사 여부 (type 이 'international' 인지) 를 반환합니다.
     *
     * @return bool 국제 배송사면 true
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
