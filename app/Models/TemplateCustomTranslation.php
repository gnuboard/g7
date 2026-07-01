<?php

namespace App\Models;

use App\Models\Concerns\HasUserOverrides;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 템플릿 커스텀 다국어 키 모델.
 *
 * 레이아웃 편집기에서 인라인 편집으로 평문을 `$t:custom.{layout}.{seq}` 키로
 * 전환할 때 생성되는 동적 다국어 키를 저장합니다. 런타임에는
 * `MergeCustomTranslations` 리스너가 `template.language.merge` 필터에서
 * 언어팩보다 높은 우선순위로 병합합니다.
 *
 * @property int $id 커스텀 다국어 키 ID
 * @property int $template_id 소속 템플릿 ID
 * @property string|null $layout_name 생성 출처 레이아웃 이름
 * @property string $translation_key 다국어 키 ($t: 참조 경로)
 * @property array $values 로케일별 번역 값
 * @property array|null $user_overrides 사용자 수정 보존 추적
 * @property string $status 상태 (active|orphaned)
 * @property int|null $created_by 생성자 ID
 * @property int|null $updated_by 수정자 ID
 * @property int $lock_version 낙관적 잠금 버전
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Template $template 템플릿 관계
 */
class TemplateCustomTranslation extends Model
{
    use HasUserOverrides;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'template_custom_translations';

    /**
     * 대량 할당 가능 필드
     *
     * @var array<string>
     */
    protected $fillable = [
        'template_id',
        'layout_name',
        'translation_key',
        'values',
        'status',
        'created_by',
        'updated_by',
        'lock_version',
    ];

    /**
     * HasUserOverrides — 사용자가 수정 가능한 필드.
     *
     * @var array<string>
     */
    protected array $trackableFields = ['values'];

    /**
     * HasUserOverrides — 다국어 JSON 컬럼 sub-key dot-path 단위 보존.
     * 언어팩 재설치/업그레이드 시 유저가 손댄 로케일 값만 보존됩니다.
     *
     * @var array<string>
     */
    protected array $translatableTrackableFields = ['values'];

    /**
     * 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'values' => 'array',
            'user_overrides' => 'array',
            'lock_version' => 'integer',
        ];
    }

    /**
     * 템플릿 관계
     *
     * @return BelongsTo 템플릿 관계
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * 활성 상태 키만 조회
     *
     * @param  Builder  $query  쿼리 빌더
     * @return Builder 활성 키 쿼리
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
