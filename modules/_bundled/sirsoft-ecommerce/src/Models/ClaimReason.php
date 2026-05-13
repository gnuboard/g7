<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\Concerns\HasUserOverrides;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonTypeEnum;

/**
 * 클래임 사유 모델.
 *
 * 환불/교환/반품 등 클래임 사유 템플릿을 관리합니다.
 *
 * @since 7.0.0-beta.2 (HasUserOverrides 적용)
 */
class ClaimReason extends Model
{
    use HasUserOverrides;

    /**
     * 사용자 수정 보존 대상 필드.
     *
     * @var array<int, string>
     */
    protected array $trackableFields = ['name', 'sort_order', 'is_active'];

    /**
     * 다국어 JSON 컬럼 — sub-key dot-path 단위 user_overrides 보존.
     *
     * @var array<int, string>
     */
    protected array $translatableTrackableFields = ['name'];

    protected $table = 'ecommerce_claim_reasons';

    protected $fillable = [
        'type',
        'code',
        'name',
        'fault_type',
        'is_user_selectable',
        'is_active',
        'sort_order',
        'created_by',
        'updated_by',
        'user_overrides',
    ];

    protected $casts = [
        'name' => 'array',
        'type' => ClaimReasonTypeEnum::class,
        'fault_type' => ClaimReasonFaultTypeEnum::class,
        'is_user_selectable' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'user_overrides' => 'array',
    ];

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
     * 현재 로케일의 사유명 반환
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

        return $name[$locale] ?? $name[config('app.fallback_locale', 'ko')] ?? $name[array_key_first($name)] ?? '';
    }

    /**
     * 활성 사유만 조회 스코프
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
     * 고객 선택 가능한 사유만 조회 스코프
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUserSelectable($query)
    {
        return $query->where('is_user_selectable', true);
    }

    /**
     * 사유 유형별 조회 스코프
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|ClaimReasonTypeEnum $type 사유 유형
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, string|ClaimReasonTypeEnum $type)
    {
        $value = $type instanceof ClaimReasonTypeEnum ? $type->value : $type;

        return $query->where('type', $value);
    }

    /**
     * 귀책 유형별 조회 스코프
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|ClaimReasonFaultTypeEnum $faultType 귀책 유형
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfFaultType($query, string|ClaimReasonFaultTypeEnum $faultType)
    {
        $value = $faultType instanceof ClaimReasonFaultTypeEnum ? $faultType->value : $faultType;

        return $query->where('fault_type', $value);
    }
}
