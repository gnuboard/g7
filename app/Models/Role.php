<?php

namespace App\Models;

use App\Enums\ExtensionOwnerType;
use App\Enums\PasswordExpiryPolicy;
use App\Models\Concerns\HasUserOverrides;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 역할 모델.
 *
 * 관리자 UI 에서 수정한 필드(name/description) 는 `HasUserOverrides` trait 에 의해
 * `user_overrides` 컬럼에 자동 누적 기록되며, 이후 코어/확장 업데이트의 `syncRole()`
 * 경로에서 필드 단위로 보존됩니다.
 */
class Role extends Model
{
    use HasFactory, HasUserOverrides;

    /**
     * `HasUserOverrides` 가 추적할 필드.
     *
     * `ExtensionRoleSyncHelper::syncRole()` L79~85 의 upsert 시 필드 단위 보존 대상과 일치.
     *
     * @var array<int, string>
     */
    protected array $trackableFields = ['name', 'description', 'is_active'];

    /**
     * 다국어 JSON 컬럼 — sub-key dot-path 단위로 user_overrides 보존.
     *
     * 사용자가 ko 라벨만 수정하면 user_overrides=['name.ko'] 로 기록되어
     * 언어팩 활성/시더 재실행 시 ja/en 키는 자동 동기화 가능.
     *
     * @var array<int, string>
     */
    protected array $translatableTrackableFields = ['name', 'description'];

    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'identifier' => ['label_key' => 'activity_log.fields.identifier', 'type' => 'text'],
        'is_active' => ['label_key' => 'activity_log.fields.is_active', 'type' => 'boolean'],
    ];

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'roles';

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
        'name',
        'description',
        'extension_type',
        'extension_identifier',
        'user_overrides',
        'is_active',
        'password_expiry_policy',
    ];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string> 속성명 → 캐스트 타입 맵
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'extension_type' => ExtensionOwnerType::class,
            'user_overrides' => 'array',
            'is_active' => 'boolean',
            'password_expiry_policy' => PasswordExpiryPolicy::class,
        ];
    }

    /**
     * 역할에 속한 사용자들과의 관계를 정의합니다.
     *
     * @return BelongsToMany user_roles 피벗(assigned_at, assigned_by 포함) 기반 사용자 관계
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withPivot(['assigned_at', 'assigned_by'])
            ->withTimestamps();
    }

    /**
     * 역할이 가진 권한들과의 관계를 정의합니다.
     *
     * @return BelongsToMany role_permissions 피벗(granted_at, granted_by, scope_type 포함) 기반 권한 관계
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
            ->withPivot(['granted_at', 'granted_by', 'scope_type'])
            ->withTimestamps();
    }

    /**
     * 역할이 접근 가능한 메뉴들과의 관계
     *
     * @return BelongsToMany
     */
    public function menus(): BelongsToMany
    {
        return $this->belongsToMany(Menu::class, 'role_menus')
            ->withPivot('permission_type')
            ->withTimestamps();
    }

    /**
     * 역할의 메뉴 권한들과의 관계를 정의합니다.
     *
     * @deprecated role_menus 피벗 테이블 사용. menus() 메서드를 사용하세요.
     *
     * @return HasMany
     */
    public function menuPermissions(): HasMany
    {
        return $this->hasMany(MenuPermission::class);
    }

    /**
     * 특정 권한을 가지고 있는지 확인합니다.
     *
     * @param  string  $permission  권한 식별자
     * @return bool 해당 권한 보유 여부
     */
    public function hasPermission(string $permission): bool
    {
        return $this->permissions()->where('identifier', $permission)->exists();
    }

    /**
     * 여러 권한을 가지고 있는지 확인합니다.
     *
     * @param  array  $permissions  권한 식별자 배열
     * @param  bool  $requireAll  모든 권한이 필요한지 여부 (true: AND, false: OR)
     * @return bool 조건(AND/OR)에 따른 권한 보유 여부
     */
    public function hasPermissions(array $permissions, bool $requireAll = true): bool
    {
        $userPermissions = $this->permissions()->whereIn('identifier', $permissions)->count();

        return $requireAll ? $userPermissions === count($permissions) : $userPermissions > 0;
    }

    /**
     * 특정 메뉴에 대한 권한을 가지고 있는지 확인합니다.
     *
     * @param  int  $menuId  메뉴 ID
     * @param  string  $permissionType  권한 유형 (read, write, delete)
     * @return bool menu_permissions 에 허용(is_allowed) 권한이 있으면 true
     */
    public function hasMenuPermission(int $menuId, string $permissionType = 'read'): bool
    {
        return $this->menuPermissions()
            ->where('menu_id', $menuId)
            ->where('permission_type', $permissionType)
            ->where('is_allowed', true)
            ->exists();
    }

    /**
     * 지정된 로케일의 역할 이름 반환
     *
     * @param  string|null  $locale  로케일 (null 이면 현재 앱 로케일)
     * @return string 역할 이름 (로케일 → fallback 로케일 → 첫 번째 값 순으로 선택)
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
     * 지정된 로케일의 역할 설명 반환
     *
     * @param  string|null  $locale  로케일 (null 이면 현재 앱 로케일)
     * @return string 역할 설명 (로케일 → fallback 로케일 → 첫 번째 값 순으로 선택)
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
     * 현재 로케일의 역할 이름 반환
     *
     * @return Attribute 현재 로케일 역할 이름을 반환하는 접근자
     */
    protected function localizedName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getLocalizedName()
        );
    }

    /**
     * 현재 로케일의 역할 설명 반환
     *
     * @return Attribute 현재 로케일 역할 설명을 반환하는 접근자
     */
    protected function localizedDescription(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getLocalizedDescription()
        );
    }

    /**
     * 코어 시스템에서 생성된 역할인지 확인합니다.
     *
     * @return bool
     */
    public function isCore(): bool
    {
        return $this->extension_type === ExtensionOwnerType::Core;
    }

    /**
     * 삭제 가능한 역할인지 확인합니다.
     *
     * extension_type이 null(사용자 직접 생성)인 경우에만 삭제 가능합니다.
     *
     * @return bool
     */
    public function isDeletable(): bool
    {
        return $this->extension_type === null;
    }

    /**
     * 확장(모듈/플러그인)이 소유한 역할인지 확인합니다.
     *
     * @return bool
     */
    public function isExtensionOwned(): bool
    {
        return in_array($this->extension_type, [ExtensionOwnerType::Module, ExtensionOwnerType::Plugin], true);
    }

    /**
     * 사용 여부(is_active)를 바꿀 수 없는 역할인지 판정합니다.
     *
     * 꺼진 역할의 권한은 판정에서 즉시 빠진다(User::permissionGrants). 최고관리자 역할(admin)을
     * 끄면 최고관리자를 포함한 운영자 전원이 관리 화면을 잃으므로 누구도 끌 수 없다.
     * 서비스 가드와 응답의 `is_active_locked`·`abilities.can_toggle_status` 가 이 한 곳을 읽는다.
     *
     * @return bool 사용 여부 변경 불가면 true
     */
    public function isActiveStateLocked(): bool
    {
        return $this->identifier === 'admin';
    }

    /**
     * 액터가 이 역할의 정보·권한·사용 여부를 바꿀 수 있는지 판정합니다.
     *
     * 보호된 역할(코어/확장 소유)은 슈퍼 관리자만 바꿀 수 있습니다. 인증 액터가 없으면
     * (Artisan/내부 시더) 신뢰 경로로 보아 허용합니다. 서비스의 수정 가드와 응답의
     * `abilities.can_update`/`can_toggle_status` 가 이 한 곳을 함께 읽습니다 — 둘이 따로
     * 판정하면 화면은 버튼을 켜 두고 서버는 403 으로 거절하는 어긋남이 생깁니다.
     *
     * @param  User|null  $actor  행위자 (null 이면 신뢰 경로)
     * @return bool 수정 가능 여부
     */
    public function isModifiableBy(?User $actor): bool
    {
        if ($actor === null || $actor->isSuperAdmin()) {
            return true;
        }

        return ! $this->isCore() && ! $this->isExtensionOwned();
    }
}
