<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Casts\AsUnicodeJson;
use App\Extension\HookManager;
use App\Search\Contracts\FulltextSearchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Brand extends Model implements FulltextSearchable
{
    use Searchable, SoftDeletes;

    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'website' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.website', 'type' => 'text'],
        'sort_order' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.sort_order', 'type' => 'number'],
        'is_active' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.is_active', 'type' => 'boolean'],
    ];

    protected $table = 'ecommerce_brands';

    protected $fillable = [
        'name',
        'slug',
        'website',
        'sort_order',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'name' => AsUnicodeJson::class,
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    /**
     * 브랜드를 생성한 사용자 관계 (created_by FK).
     *
     * @return BelongsTo 생성자 사용자 관계
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    /**
     * 브랜드를 마지막으로 수정한 사용자 관계 (updated_by FK).
     *
     * @return BelongsTo 수정자 사용자 관계
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }

    /**
     * 이 브랜드에 속한 상품 목록 관계.
     *
     * @return HasMany 상품 관계 컬렉션
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'brand_id');
    }

    /**
     * 현재 로케일의 브랜드명을 반환합니다 (다국어 fallback chain 적용).
     *
     * @param  string|null  $locale  반환할 로케일. null 이면 현재 앱 로케일 사용
     * @return string 로케일별 브랜드명, 누락 시 fallback 로케일/첫 번째 키 순으로 시도
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
     * 활성 브랜드만 조회 스코프
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

    // ─── FulltextSearchable 구현 ─────────────────────────

    /**
     * FULLTEXT 검색 대상 컬럼 목록을 반환합니다.
     *
     * @return array<string>
     */
    public function searchableColumns(): array
    {
        return ['name'];
    }

    /**
     * 컬럼별 검색 가중치를 반환합니다.
     *
     * @return array<string, float>
     */
    public function searchableWeights(): array
    {
        return [
            'name' => 1.0,
        ];
    }

    /**
     * MySQL FULLTEXT 엔진에서는 인덱스 업데이트가 불필요합니다.
     *
     * @return bool
     */
    public function searchIndexShouldBeUpdated(): bool
    {
        $default = config('scout.driver') !== 'mysql-fulltext';

        return HookManager::applyFilters(
            'sirsoft-ecommerce.search.brand.index_should_update',
            $default,
            $this
        );
    }

    /**
     * 검색 인덱스용 배열을 반환합니다.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'name' => $this->name,
        ];
    }
}
