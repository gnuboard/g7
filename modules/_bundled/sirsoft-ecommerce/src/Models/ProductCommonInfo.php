<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Casts\AsUnicodeJson;
use App\Extension\HookManager;
use App\Search\Contracts\FulltextSearchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

/**
 * 공통정보 모델
 *
 * 상품 상세페이지에 표시될 공통 안내 정보를 관리합니다.
 */
class ProductCommonInfo extends Model implements FulltextSearchable
{
    use Searchable;
    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'content_mode' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.content_mode', 'type' => 'text'],
        'is_default' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.is_default', 'type' => 'boolean'],
        'is_active' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.is_active', 'type' => 'boolean'],
        'sort_order' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.sort_order', 'type' => 'number'],
    ];

    protected $table = 'ecommerce_product_common_infos';

    protected $fillable = [
        'name',
        'content',
        'content_mode',
        'is_default',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'name' => AsUnicodeJson::class,
        'content' => AsUnicodeJson::class,
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * 현재 로케일의 공통정보명 반환
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
     * 현재 로케일의 안내 내용 반환
     *
     * @param string|null $locale 로케일 (기본값: 현재 앱 로케일)
     * @return string
     */
    public function getLocalizedContent(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $content = $this->content;

        if (! is_array($content)) {
            return '';
        }

        return $content[$locale] ?? $content['ko'] ?? $content['en'] ?? $content[array_key_first($content)] ?? '';
    }

    /**
     * 상품 관계
     *
     * @return HasMany 상품 모델과의 관계
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'common_info_id');
    }

    /**
     * 활성 공통정보만 조회하는 스코프
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

    /**
     * 기본 공통정보만 조회하는 스코프
     *
     * @param \Illuminate\Database\Eloquent\Builder $query 쿼리 빌더
     * @return \Illuminate\Database\Eloquent\Builder 쿼리 빌더
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * 사용 중인 언어 개수 반환
     *
     * @return int
     */
    public function getLanguageCountAttribute(): int
    {
        $content = $this->content;

        if (! is_array($content)) {
            return 0;
        }

        return count(array_filter($content, fn ($value) => ! empty($value)));
    }

    // ─── FulltextSearchable 구현 ─────────────────────────

    /**
     * FULLTEXT 검색 대상 컬럼 목록을 반환합니다.
     *
     * @return array<string>
     */
    public function searchableColumns(): array
    {
        return ['name', 'content'];
    }

    /**
     * 컬럼별 검색 가중치를 반환합니다.
     *
     * @return array<string, float>
     */
    public function searchableWeights(): array
    {
        return [
            'name' => 2.0,
            'content' => 1.0,
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
            'sirsoft-ecommerce.search.product_common_info.index_should_update',
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
            'content' => $this->content,
        ];
    }
}
