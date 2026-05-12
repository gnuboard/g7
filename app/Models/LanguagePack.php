<?php

namespace App\Models;

use App\Enums\LanguagePackOrigin;
use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 언어팩 모델.
 *
 * 슬롯(scope, target_identifier, locale) 단위로 여러 벤더의 언어팩이 공존할 수 있으며,
 * 슬롯당 active 상태는 1개만 허용됩니다 (DB functional unique index).
 *
 * @property int $id
 * @property string $identifier
 * @property string $vendor
 * @property string $scope
 * @property string|null $target_identifier
 * @property string $locale
 * @property string $locale_name
 * @property string $locale_native_name
 * @property string $text_direction
 * @property string $version
 * @property string|null $latest_version
 * @property string|null $target_version_constraint
 * @property bool $target_version_mismatch
 * @property string|null $license
 * @property array<string, string>|null $description
 * @property string $status
 * @property string|null $deactivated_reason
 * @property \Illuminate\Support\Carbon|null $deactivated_at
 * @property string|null $incompatible_required_version
 * @property bool $is_protected
 * @property array<string, mixed> $manifest
 * @property string|null $source_type
 * @property string|null $source_url
 * @property int|null $installed_by
 * @property \Illuminate\Support\Carbon|null $installed_at
 * @property \Illuminate\Support\Carbon|null $activated_at
 */
class LanguagePack extends Model
{
    use HasFactory;

    /**
     * 테이블명.
     *
     * @var string
     */
    protected $table = 'language_packs';

    /**
     * 기본키.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * 타임스탬프 사용 여부.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * 대량 할당 허용 컬럼.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'identifier',
        'vendor',
        'scope',
        'target_identifier',
        'locale',
        'locale_name',
        'locale_native_name',
        'text_direction',
        'version',
        'latest_version',
        'target_version_constraint',
        'target_version_mismatch',
        'license',
        'description',
        'status',
        'deactivated_reason',
        'deactivated_at',
        'incompatible_required_version',
        'is_protected',
        'manifest',
        'source_type',
        'source_url',
        'installed_by',
        'installed_at',
        'activated_at',
    ];

    /**
     * 캐스팅 정의.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'description' => 'array',
            'manifest' => 'array',
            'is_protected' => 'boolean',
            'target_version_mismatch' => 'boolean',
            'installed_at' => 'datetime',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * 설치자(User) 와의 관계를 정의합니다.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function installer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    /**
     * 활성 상태 여부를 확인합니다.
     *
     * @return bool 활성 여부
     */
    public function isActive(): bool
    {
        return $this->status === LanguagePackStatus::Active->value;
    }

    /**
     * 코어 스코프 여부를 확인합니다.
     *
     * @return bool 코어 여부
     */
    public function isCoreScope(): bool
    {
        return $this->scope === LanguagePackScope::Core->value;
    }

    /**
     * 번들 (수정 보호) 언어팩 여부를 확인합니다.
     *
     * @return bool 번들 여부
     */
    public function isProtected(): bool
    {
        return (bool) $this->is_protected;
    }

    /**
     * UI 출처(origin) 분류를 반환합니다.
     *
     * `source_type` 컬럼을 3그룹(built_in / bundled / user_installed) 으로 매핑한 값.
     * 가상 미설치 번들 행도 source_type=bundled 이므로 동일 매핑.
     *
     * @return string|null origin 값 (source_type 누락 시 null)
     */
    public function getOriginAttribute(): ?string
    {
        return LanguagePackOrigin::fromSourceTypeValue($this->source_type)?->value;
    }

    /**
     * 슬롯 키를 반환합니다 ({scope}|{target_identifier}|{locale}).
     *
     * @return string 슬롯 키
     */
    public function slotKey(): string
    {
        return sprintf(
            '%s|%s|%s',
            $this->scope,
            $this->target_identifier ?? '',
            $this->locale
        );
    }

    /**
     * 활성 언어팩만 조회하는 스코프.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', LanguagePackStatus::Active->value);
    }

    /**
     * 특정 스코프 언어팩만 조회하는 스코프.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $scope  스코프 문자열
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfScope(Builder $query, string $scope): Builder
    {
        return $query->where('scope', $scope);
    }

    /**
     * 특정 슬롯의 언어팩만 조회하는 스코프.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $scope  스코프 문자열
     * @param  string|null  $targetIdentifier  대상 확장 식별자
     * @param  string  $locale  로케일
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForSlot(
        Builder $query,
        string $scope,
        ?string $targetIdentifier,
        string $locale
    ): Builder {
        return $query
            ->where('scope', $scope)
            ->where('target_identifier', $targetIdentifier)
            ->where('locale', $locale);
    }

    /**
     * 활성 디렉토리 절대 경로를 반환합니다.
     *
     * source_type=bundled_with_extension 인 가상 레코드는 확장 디렉토리 경로를 반환하고,
     * 그 외에는 lang-packs/{identifier}/ 경로를 반환합니다.
     *
     * @return string 디렉토리 절대 경로
     */
    public function resolveDirectory(): string
    {
        if ($this->source_type === 'bundled_with_extension' && $this->source_url) {
            return base_path($this->source_url);
        }

        // uninstalled 가상 행(미설치 번들)은 활성 디렉토리가 없으므로 _bundled 원본을 가리킴
        if ($this->status === LanguagePackStatus::Uninstalled->value) {
            return base_path('lang-packs/_bundled/'.$this->identifier);
        }

        return base_path('lang-packs/'.$this->identifier);
    }
}
