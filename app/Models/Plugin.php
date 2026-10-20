<?php

namespace App\Models;

use App\Enums\DeactivationReason;
use App\Enums\ExtensionStatus;
use App\Support\LocalizedValue;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Plugin extends Model
{
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'plugins';

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
        'description',
        'status',
        'deactivated_reason',
        'deactivated_at',
        'incompatible_required_version',
        'update_available',
        'hooks',
        'github_url',
        'github_changelog_url',
        'metadata',
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
            'name' => 'array',
            'description' => 'array',
            'hooks' => 'array',
            'metadata' => 'array',
            'update_available' => 'boolean',
            'deactivated_reason' => DeactivationReason::class,
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * 지정된 로케일의 플러그인 이름 반환
     *
     * @param  string|null  $locale  로케일 (null 이면 현재 로케일)
     * @return string 로케일의 플러그인 이름
     */
    public function getLocalizedName(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        if (! is_array($this->name)) {
            return (string) $this->name;
        }

        // 요청 언어 → 사이트 기본 언어 → 영어 → 첫 값 (#148)
        return LocalizedValue::pick($this->name, $locale) ?? '';
    }

    /**
     * 지정된 로케일의 플러그인 설명 반환
     *
     * @param  string|null  $locale  로케일 (null 이면 현재 로케일)
     * @return string 로케일의 플러그인 설명
     */
    public function getLocalizedDescription(?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        if (! is_array($this->description)) {
            return (string) $this->description;
        }

        // 요청 언어 → 사이트 기본 언어 → 영어 → 첫 값 (#148)
        return LocalizedValue::pick($this->description, $locale) ?? '';
    }

    /**
     * 현재 로케일의 플러그인 이름 반환
     *
     * @return Attribute 현재 로케일 이름 접근자
     */
    protected function localizedName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getLocalizedName()
        );
    }

    /**
     * 현재 로케일의 플러그인 설명 반환
     *
     * @return Attribute 현재 로케일 설명 접근자
     */
    protected function localizedDescription(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getLocalizedDescription()
        );
    }

    /**
     * 플러그인이 활성화되어 있는지 확인합니다.
     *
     * @return bool 활성화 상태 여부
     */
    public function isActive(): bool
    {
        return $this->status === ExtensionStatus::Active->value;
    }

    /**
     * 플러그인이 시스템에 설치되어 있는지 확인합니다.
     *
     * @return bool 설치 상태 여부
     */
    public function isInstalled(): bool
    {
        return in_array($this->status, [
            ExtensionStatus::Active->value, ExtensionStatus::Inactive->value,
        ]);
    }

    /**
     * 플러그인을 생성한 사용자와의 관계를 정의합니다.
     *
     * @return BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 플러그인을 마지막으로 수정한 사용자와의 관계를 정의합니다.
     *
     * @return BelongsTo 최종 수정자 관계
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
