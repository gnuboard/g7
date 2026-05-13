<?php

namespace App\Models;

use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 레이아웃 확장 모델
 *
 * 모듈/플러그인이 기존 레이아웃에 주입하는 확장을 관리합니다.
 *
 * @property int $id 확장 ID
 * @property int $template_id 템플릿 ID
 * @property LayoutExtensionType $extension_type 확장 타입
 * @property string $target_name 타겟 이름
 * @property LayoutSourceType $source_type 출처 타입
 * @property string $source_identifier 출처 식별자
 * @property string|null $override_target 오버라이드 대상
 * @property array $content 확장 정의 JSON
 * @property int $priority 우선순위
 * @property bool $is_active 활성 상태
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Template $template 템플릿 관계
 */
class LayoutExtension extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'template_layout_extensions';

    /**
     * 대량 할당 가능 필드
     *
     * @var array<string>
     */
    protected $fillable = [
        'template_id',
        'extension_type',
        'target_name',
        'source_type',
        'source_identifier',
        'override_target',
        'content',
        'priority',
        'is_active',
    ];

    /**
     * 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'extension_type' => LayoutExtensionType::class,
            'source_type' => LayoutSourceType::class,
            'content' => 'array',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * 템플릿 관계
     *
     * @return BelongsTo
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * 활성 확장만 조회
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Extension Point 타입만 조회
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeExtensionPoints(Builder $query): Builder
    {
        return $query->where('extension_type', LayoutExtensionType::ExtensionPoint);
    }

    /**
     * Overlay 타입만 조회
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeOverlays(Builder $query): Builder
    {
        return $query->where('extension_type', LayoutExtensionType::Overlay);
    }

    /**
     * 특정 출처의 확장만 조회
     *
     * @param Builder $query
     * @param LayoutSourceType $sourceType
     * @param string $identifier
     * @return Builder
     */
    public function scopeBySource(Builder $query, LayoutSourceType $sourceType, string $identifier): Builder
    {
        return $query->where('source_type', $sourceType)
            ->where('source_identifier', $identifier);
    }

    /**
     * 우선순위 순으로 정렬
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('priority', 'asc')
            ->orderBy('source_identifier', 'asc');
    }

    /**
     * 템플릿 오버라이드만 조회
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeTemplateOverrides(Builder $query): Builder
    {
        return $query->where('source_type', LayoutSourceType::Template);
    }

    /**
     * 특정 모듈/플러그인에 대한 템플릿 오버라이드 조회
     *
     * @param Builder $query
     * @param string $overrideTarget 오버라이드 대상 모듈/플러그인 식별자
     * @return Builder
     */
    public function scopeOverridingTarget(Builder $query, string $overrideTarget): Builder
    {
        return $query->where('override_target', $overrideTarget);
    }

    /**
     * 모듈 출처만 조회
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeFromModules(Builder $query): Builder
    {
        return $query->where('source_type', LayoutSourceType::Module);
    }

    /**
     * 플러그인 출처만 조회
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeFromPlugins(Builder $query): Builder
    {
        return $query->where('source_type', LayoutSourceType::Plugin);
    }
}
