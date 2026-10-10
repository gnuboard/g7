<?php

namespace App\Models;

use App\Enums\LayoutSourceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 템플릿 레이아웃 모델
 *
 * @property-read Collection<int, TemplateLayoutVersion> $versions 레이아웃 버전 목록
 *
 * @method HasMany versions() 레이아웃 버전 관계
 * @method TemplateLayoutVersion|null latestVersion() 최신 버전 조회
 * @method TemplateLayoutVersion|null getVersion(int $version) 특정 버전 조회
 */
class TemplateLayout extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * 대량 할당 가능한 속성
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'template_id',
        'name',
        'content',
        'extends',
        'source_type',
        'source_identifier',
        'created_by',
        'updated_by',
        'original_content_hash',
        'original_content_size',
        'original_content',
        'lock_version',
    ];

    /**
     * 직렬화에서 숨길 속성 — 원본 본문은 3자 병합 기준 전용이라 API 응답·편집기로 내보내지 않는다.
     *
     * 캐스트를 두지 않는다: 문자열 그대로 두어야 빈 map(`{}`) 모양이 보존된다.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'original_content',
    ];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'source_type' => LayoutSourceType::class,
            'lock_version' => 'integer',
        ];
    }

    /**
     * 템플릿과의 관계
     *
     * @return BelongsTo 소속 템플릿 관계
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * 부모 레이아웃과의 관계 (레이아웃 상속)
     *
     * @return BelongsTo|null 부모 레이아웃 관계, 상속하지 않으면 null
     */
    public function parent(): ?BelongsTo
    {
        if ($this->extends === null) {
            return null;
        }

        return $this->belongsTo(TemplateLayout::class, 'extends', 'name')
            ->where('template_id', $this->template_id);
    }

    /**
     * 레이아웃 버전 목록 (최신순)
     *
     * @return HasMany 버전 관계
     */
    public function versions(): HasMany
    {
        return $this->hasMany(TemplateLayoutVersion::class, 'layout_id')
            ->orderBy('version', 'desc');
    }

    /**
     * 최신 버전 조회
     *
     * @return TemplateLayoutVersion|null 최신 버전, 이력이 없으면 null
     */
    public function latestVersion(): ?TemplateLayoutVersion
    {
        return $this->versions()->first();
    }

    /**
     * 특정 버전 조회
     *
     * @param  int  $version  버전 번호
     * @return TemplateLayoutVersion|null 버전, 없으면 null
     */
    public function getVersion(int $version): ?TemplateLayoutVersion
    {
        return $this->versions()
            ->where('version', $version)
            ->first();
    }

    /**
     * 소스 타입으로 필터링하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  LayoutSourceType  $sourceType  소스 타입
     * @return Builder 필터가 걸린 쿼리
     */
    public function scopeBySourceType(Builder $query, LayoutSourceType $sourceType): Builder
    {
        return $query->where('source_type', $sourceType);
    }

    /**
     * 모듈 레이아웃만 조회하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 필터가 걸린 쿼리
     */
    public function scopeFromModules(Builder $query): Builder
    {
        return $query->where('source_type', LayoutSourceType::Module);
    }

    /**
     * 특정 모듈의 레이아웃만 조회하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return Builder 필터가 걸린 쿼리
     */
    public function scopeByModule(Builder $query, string $moduleIdentifier): Builder
    {
        return $query->where('source_type', LayoutSourceType::Module)
            ->where('source_identifier', $moduleIdentifier);
    }

    /**
     * 플러그인 레이아웃만 조회하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 필터가 걸린 쿼리
     */
    public function scopeFromPlugins(Builder $query): Builder
    {
        return $query->where('source_type', LayoutSourceType::Plugin);
    }

    /**
     * 특정 플러그인의 레이아웃만 조회하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  string  $pluginIdentifier  플러그인 식별자
     * @return Builder 필터가 걸린 쿼리
     */
    public function scopeByPlugin(Builder $query, string $pluginIdentifier): Builder
    {
        return $query->where('source_type', LayoutSourceType::Plugin)
            ->where('source_identifier', $pluginIdentifier);
    }

    /**
     * 템플릿 레이아웃만 조회하는 스코프 (오버라이드 포함)
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 필터가 걸린 쿼리
     */
    public function scopeFromTemplates(Builder $query): Builder
    {
        return $query->where('source_type', LayoutSourceType::Template);
    }

    /**
     * 특정 소스 식별자로 필터링하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  string  $identifier  소스 식별자
     * @return Builder 필터가 걸린 쿼리
     */
    public function scopeBySourceIdentifier(Builder $query, string $identifier): Builder
    {
        return $query->where('source_identifier', $identifier);
    }
}
