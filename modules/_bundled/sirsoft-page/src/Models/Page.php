<?php

namespace Modules\Sirsoft\Page\Models;

use App\Casts\AsUnicodeJson;
use App\Extension\HookManager;
use App\Models\User;
use App\Search\Contracts\FulltextSearchable;
use App\Support\HtmlImageExtractor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;
use Modules\Sirsoft\Page\Database\Factories\PageFactory;

class Page extends Model implements FulltextSearchable
{
    use HasFactory;
    use Searchable;

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
        'content_thumbnail_url',
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
     * 모델 이벤트 등록
     *
     * 본문 첫 내부 이미지 URL 캐시(content_thumbnail_url)는 저장 시점에만 계산한다.
     * 모델 saving 이벤트에 두는 이유: PageService::restoreVersion() 이 서비스
     * create/update 를 우회해 Repository 를 직접 호출하므로, 버전 롤백 시에도 캐시가
     * 자동 재계산되는 유일 지점이 모델 이벤트다 (공개 이슈 #22 동종 — board 와 동형).
     */
    protected static function booted(): void
    {
        static::saving(function (Page $page) {
            if ($page->exists && ! $page->isDirty('content') && ! $page->isDirty('content_mode')) {
                return;
            }

            // text 모드 본문은 이스케이프 렌더(이미지 미표시) — 캐시하지 않는다
            if ($page->content_mode !== 'html') {
                $page->content_thumbnail_url = null;

                return;
            }

            // content 는 다국어 JSON — 기본 로케일 우선, 없으면 배열 순서대로
            // 첫 내부 이미지가 나올 때까지 시도 (레거시 평문 문자열도 수용)
            $extracted = null;
            $candidates = [];
            $content = $page->content;
            $htmls = is_array($content)
                ? array_values(array_filter(
                    [$content[config('app.locale')] ?? null, ...array_values($content)],
                    fn ($html) => is_string($html) && $html !== ''
                ))
                : (is_string($content) && $content !== '' ? [$content] : []);

            foreach ($htmls as $html) {
                $candidates = array_merge($candidates, HtmlImageExtractor::candidates($html));
                $extracted ??= HtmlImageExtractor::firstInternal($html);

                if ($extracted !== null) {
                    break;
                }
            }

            // 확장이 후보를 대체(CDN prefix 승격 등)하거나 차단(null)할 수 있는 필터 훅.
            // 특정 에디터 확장에 의존하지 않는다 — 페이로드는 일반 HTML 파싱 결과뿐이다.
            $value = HookManager::applyFilters(
                'sirsoft-page.page.filter_content_thumbnail',
                $extracted,
                $page,
                $candidates
            );

            // 필터 반환값 방어 — 비문자열/빈 값/컬럼 상한 초과는 null(후보 없음)로 강등
            $page->content_thumbnail_url = is_string($value) && $value !== '' && mb_strlen($value) <= 1000
                ? $value
                : null;
        });
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
     * @param  Builder  $query
     * @return Builder
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
