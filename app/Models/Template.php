<?php

namespace App\Models;

use App\Enums\DeactivationReason;
use App\Enums\ExtensionStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array{ko: string, en: string} $name
 * @property array{ko: string, en: string} $description
 * @property-read string $localized_name
 * @property-read string $localized_description
 */
class Template extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'identifier',
        'vendor',
        'name',
        'version',
        'latest_version',
        'update_available',
        'type',
        'status',
        'deactivated_reason',
        'deactivated_at',
        'incompatible_required_version',
        'description',
        'user_modified_at',
        'github_url',
        'github_changelog_url',
        'metadata',
        'created_by',
        'updated_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'metadata' => 'array',
            'user_modified_at' => 'datetime',
            'update_available' => 'boolean',
            'deactivated_reason' => DeactivationReason::class,
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * 현재 로케일의 템플릿 이름 반환
     */
    protected function localizedName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getLocalizedName()
        );
    }

    /**
     * 현재 로케일의 템플릿 설명 반환
     */
    protected function localizedDescription(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getLocalizedDescription()
        );
    }

    /**
     * 지정된 로케일의 템플릿 이름 반환
     *
     * @param string|null $locale 로케일 (null이면 현재 로케일 사용)
     */
    public function getLocalizedName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        if (! is_array($this->name)) {
            return (string) $this->name;
        }

        // 요청 언어 → fallback 언어 → 첫 번째 값 → 빈 문자열
        return $this->name[$locale]
            ?? $this->name[config('app.fallback_locale')]
            ?? (! empty($this->name) ? array_values($this->name)[0] : '')
            ?? '';
    }

    /**
     * 지정된 로케일의 템플릿 설명 반환
     *
     * @param string|null $locale 로케일 (null이면 현재 로케일 사용)
     */
    public function getLocalizedDescription(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        if (! is_array($this->description)) {
            return (string) $this->description;
        }

        // 요청 언어 → fallback 언어 → 첫 번째 값 → 빈 문자열
        return $this->description[$locale]
            ?? $this->description[config('app.fallback_locale')]
            ?? (! empty($this->description) ? array_values($this->description)[0] : '')
            ?? '';
    }

    /**
     * Get the layouts for the template.
     */
    public function layouts(): HasMany
    {
        return $this->hasMany(TemplateLayout::class);
    }

    /**
     * 템플릿이 활성화되어 있는지 확인합니다.
     *
     * @return bool 활성화 상태 여부
     */
    public function isActive(): bool
    {
        return $this->status === ExtensionStatus::Active->value;
    }

    /**
     * 템플릿이 시스템에 설치되어 있는지 확인합니다.
     *
     * @return bool 설치 상태 여부
     */
    public function isInstalled(): bool
    {
        return in_array($this->status, [
            ExtensionStatus::Active->value, ExtensionStatus::Inactive->value
        ]);
    }

    /**
     * 템플릿을 생성한 사용자와의 관계를 정의합니다.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 템플릿을 마지막으로 수정한 사용자와의 관계를 정의합니다.
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
