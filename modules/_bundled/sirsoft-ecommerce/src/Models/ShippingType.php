<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\Concerns\HasUserOverrides;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 배송유형 모델
 *
 * 배송유형 마스터 데이터를 관리합니다.
 *
 * @since 7.0.0-beta.2 (HasUserOverrides 적용)
 */
class ShippingType extends Model
{
    use HasUserOverrides;

    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'code' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.code', 'type' => 'text'],
        'category' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.category', 'type' => 'text'],
        'is_active' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.is_active', 'type' => 'boolean'],
        'sort_order' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.sort_order', 'type' => 'number'],
    ];

    /**
     * 사용자 수정 보존 대상 필드.
     *
     * @var array<int, string>
     */
    protected array $trackableFields = ['name', 'category', 'is_active', 'sort_order'];

    protected $table = 'ecommerce_shipping_types';

    protected $fillable = [
        'code',
        'name',
        'category',
        'is_active',
        'sort_order',
        'created_by',
        'updated_by',
        'user_overrides',
    ];

    protected $casts = [
        'name' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'user_overrides' => 'array',
    ];

    /**
     * 요청 단위 캐시 (코드 → 모델)
     *
     * @var \Illuminate\Database\Eloquent\Collection|null
     */
    protected static $codeCache = null;

    /**
     * 생성자 관계
     *
     * @return BelongsTo
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    /**
     * 수정자 관계
     *
     * @return BelongsTo
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }

    /**
     * 현재 로케일의 배송유형명 반환
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
     * 국내 배송유형 여부
     *
     * @return bool
     */
    public function isDomestic(): bool
    {
        return $this->category === 'domestic';
    }

    /**
     * 해외 배송유형 여부
     *
     * @return bool
     */
    public function isInternational(): bool
    {
        return $this->category === 'international';
    }

    /**
     * 코드로 캐시된 ShippingType 조회
     *
     * shipping_types는 드물게 변경되므로 요청 단위 캐시 적용
     *
     * @param string $code 배송유형 코드
     * @return self|null
     */
    public static function getCachedByCode(string $code): ?self
    {
        if (static::$codeCache === null) {
            static::$codeCache = self::all()->keyBy('code');
        }

        return static::$codeCache[$code] ?? null;
    }

    /**
     * 캐시를 초기화합니다.
     *
     * @return void
     */
    public static function clearCodeCache(): void
    {
        static::$codeCache = null;
    }

    /**
     * 활성 배송유형만 조회 스코프
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * 정렬 순서대로 조회 스코프
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * 카테고리별 조회 스코프
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $category 카테고리 (domestic, international, other)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}
