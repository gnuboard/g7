<?php

namespace Modules\Sirsoft\Page\Models;

use App\Extension\HookManager;
use App\Models\User;
use App\Casts\AsUnicodeJson;
use App\Search\Contracts\FulltextSearchable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Modules\Sirsoft\Page\Database\Factories\PageFactory;

class Page extends Model implements FulltextSearchable
{
    use HasFactory;
    use Searchable;
    use SoftDeletes;

    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'slug' => ['label_key' => 'sirsoft-page::activity_log.fields.slug', 'type' => 'text'],
        'content_mode' => ['label_key' => 'sirsoft-page::activity_log.fields.content_mode', 'type' => 'text'],
        'published' => ['label_key' => 'sirsoft-page::activity_log.fields.published', 'type' => 'boolean'],
        'published_at' => ['label_key' => 'sirsoft-page::activity_log.fields.published_at', 'type' => 'datetime'],
    ];

    /**
     * 팩토리 클래스를 반환합니다.
     */
    protected static function newFactory(): PageFactory
    {
        return PageFactory::new();
    }

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'pages';

    /**
     * 대량 할당 가능한 속성
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'slug',
        'title',
        'content',
        'content_mode',
        'published',
        'published_at',
        'seo_meta',
        'current_version',
        'created_by',
        'updated_by',
    ];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'title' => AsUnicodeJson::class,
            'content' => AsUnicodeJson::class,
            'seo_meta' => 'array',
            'published' => 'boolean',
            'published_at' => 'datetime',
            'current_version' => 'integer',
        ];
    }

    /**
     * 생성자와의 관계를 정의합니다.
     *
     * @return BelongsTo<User, Page>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 수정자와의 관계를 정의합니다.
     *
     * @return BelongsTo<User, Page>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * 버전 이력과의 관계를 정의합니다.
     *
     * @return HasMany<PageVersion>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PageVersion::class, 'page_id');
    }

    /**
     * 첨부파일과의 관계를 정의합니다.
     *
     * @return HasMany<PageAttachment>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(PageAttachment::class, 'page_id')->orderBy('order');
    }

    /**
     * 발행된 페이지만 조회하는 스코프
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePublished($query)
    {
        return $query->where('published', true);
    }

    /**
     * 지정된 로케일의 제목을 반환합니다.
     *
     * @param  string|null  $locale  로케일 (null이면 현재 로케일)
     * @return string 지정된 로케일의 제목
     */
    public function getLocalizedTitle(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        if (! is_array($this->title)) {
            return (string) $this->title;
        }

        return $this->title[$locale]
            ?? $this->title[config('app.fallback_locale')]
            ?? (! empty($this->title) ? array_values($this->title)[0] : '')
            ?? '';
    }

    /**
     * 현재 로케일의 제목 반환
     */
    protected function localizedTitle(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getLocalizedTitle()
        );
    }

    // ─── FulltextSearchable 구현 ─────────────────────────

    /**
     * FULLTEXT 검색 대상 컬럼 목록을 반환합니다.
     *
     * @return array<string>
     */
    public function searchableColumns(): array
    {
        return ['title', 'content'];
    }

    /**
     * 컬럼별 검색 가중치를 반환합니다.
     *
     * @return array<string, float>
     */
    public function searchableWeights(): array
    {
        return [
            'title' => 2.0,
            'content' => 1.0,
        ];
    }

    /**
     * 검색 인덱스용 배열을 반환합니다.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'title' => $this->title,
            'content' => $this->content,
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
            'sirsoft-page.search.page.index_should_update',
            $default,
            $this
        );
    }
}
