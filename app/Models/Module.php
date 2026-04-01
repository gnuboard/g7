<?php

namespace App\Models;

use App\Enums\ExtensionStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'modules';

    /**
     * 기본키
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * 타임스탬프 사용 여부
     *
     * @var bool
     */
    public $timestamps = true;

    protected $fillable = [
        'identifier',
        'vendor',
        'name',
        'version',
        'latest_version',
        'status',
        'update_available',
        'description',
        'github_url',
        'github_changelog_url',
        'metadata',
        'config',
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
            'config' => 'array',
            'metadata' => 'array',
            'is_active' => 'boolean',
            'update_available' => 'boolean',
        ];
    }

    /**
     * 모듈이 활성화되어 있는지 확인합니다.
     *
     * @return bool 활성화 상태 여부
     */
    public function isActive(): bool
    {
        return $this->status === ExtensionStatus::Active->value;
    }

    /**
     * 모듈이 시스템에 설치되어 있는지 확인합니다.
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
     * 모듈을 생성한 사용자와의 관계를 정의합니다.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 모듈을 마지막으로 수정한 사용자와의 관계를 정의합니다.
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * 지정된 로케일의 모듈 이름 반환
     */
    public function getLocalizedName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        if (! is_array($this->name)) {
            return (string) $this->name;
        }

        return $this->name[$locale]
            ?? $this->name[config('app.fallback_locale')]
            ?? (! empty($this->name) ? array_values($this->name)[0] : '')
            ?? '';
    }

    /**
     * 지정된 로케일의 모듈 설명 반환
     */
    public function getLocalizedDescription(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        if (! is_array($this->description)) {
            return (string) $this->description;
        }

        return $this->description[$locale]
            ?? $this->description[config('app.fallback_locale')]
            ?? (! empty($this->description) ? array_values($this->description)[0] : '')
            ?? '';
    }

    /**
     * 현재 로케일의 모듈 이름 반환
     */
    protected function localizedName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getLocalizedName()
        );
    }

    /**
     * 현재 로케일의 모듈 설명 반환
     */
    protected function localizedDescription(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getLocalizedDescription()
        );
    }
}
