<?php

namespace App\Models;

use App\Enums\ExtensionOwnerType;
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
    protected array $trackableFields = ['name', 'description'];

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
    ];

    /**
     * 속성 캐스팅
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'extension_type' => ExtensionOwnerType::class,
            'user_overrides' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * 역할에 속한 사용자들과의 관계를 정의합니다.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withPivot(['assigned_at', 'assigned_by'])
            ->withTimestamps();
    }

    /**
     * 역할이 가진 권한들과의 관계를 정의합니다.
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
     */
    protected function localizedName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getLocalizedName()
        );
    }

    /**
     * 현재 로케일의 역할 설명 반환
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
}
