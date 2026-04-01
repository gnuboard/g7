<?php

namespace App\Models;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Permission extends Model
{
    use HasFactory;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'permissions';

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
        'parent_id',
        'identifier',
        'name',
        'description',
        'extension_type',
        'extension_identifier',
        'type',
        'order',
        'resource_route_key',
        'owner_key',
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
            'extension_type' => ExtensionOwnerType::class,
            'type' => PermissionType::class,
        ];
    }

    /**
     * 상위 권한과의 관계를 정의합니다.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'parent_id');
    }

    /**
     * 하위 권한들과의 관계를 정의합니다.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Permission::class, 'parent_id')->orderBy('order');
    }

    /**
     * 재귀적으로 하위 권한들을 로드합니다.
     */
    public function allChildren(): HasMany
    {
        return $this->children()->with('allChildren');
    }

    /**
     * 최상위 권한인지 확인합니다.
     */
    public function isRoot(): bool
    {
        return is_null($this->parent_id);
    }

    /**
     * 할당 가능한 권한인지 확인합니다 (리프 노드).
     */
    public function isAssignable(): bool
    {
        return $this->children()->count() === 0;
    }

    /**
     * 최상위 권한만 조회하는 스코프
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * 권한을 가진 역할들과의 관계를 정의합니다.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, (new RolePermission)->getTable())
            ->withPivot(['granted_at', 'granted_by', 'scope_type'])
            ->withTimestamps();
    }

    /**
     * 권한을 가진 사용자들과의 관계를 정의합니다 (역할을 통해).
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, (new RolePermission)->getTable())
            ->join((new UserRole)->getTable(), (new RolePermission)->getTable().'.role_id', '=', (new UserRole)->getTable().'.role_id')
            ->select((new User)->getTable().'.*');
    }

    /**
     * 확장별 권한을 조회하는 스코프
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  ExtensionOwnerType  $type  확장 타입
     * @param  string|null  $identifier  확장 식별자
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByExtension($query, ExtensionOwnerType $type, ?string $identifier = null)
    {
        $query->where('extension_type', $type);

        if ($identifier !== null) {
            $query->where('extension_identifier', $identifier);
        }

        return $query;
    }

    /**
     * 코어 권한만 조회하는 스코프
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCoreOnly($query)
    {
        return $query->where('extension_type', ExtensionOwnerType::Core);
    }

    /**
     * 사용자 정의 권한만 조회하는 스코프
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUserCreatedOnly($query)
    {
        return $query->whereNull('extension_type');
    }

    /**
     * 코어 시스템에서 생성된 권한인지 확인합니다.
     */
    public function isCore(): bool
    {
        return $this->extension_type === ExtensionOwnerType::Core;
    }

    /**
     * 확장(모듈/플러그인)이 소유한 권한인지 확인합니다.
     */
    public function isOwnedByExtension(): bool
    {
        return $this->extension_type !== null;
    }

    /**
     * 관리자용 권한인지 확인합니다.
     *
     * @return bool 관리자용 권한 여부
     */
    public function isAdminPermission(): bool
    {
        return $this->type === PermissionType::Admin;
    }

    /**
     * 사용자용 권한인지 확인합니다.
     *
     * @return bool 사용자용 권한 여부
     */
    public function isUserPermission(): bool
    {
        return $this->type === PermissionType::User;
    }

    /**
     * 관리자용 권한만 조회합니다.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAdminPermissions($query)
    {
        return $query->where('type', PermissionType::Admin);
    }

    /**
     * 사용자용 권한만 조회합니다.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUserPermissions($query)
    {
        return $query->where('type', PermissionType::User);
    }

    /**
     * 지정된 로케일의 권한 이름 반환
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
     * 지정된 로케일의 권한 설명 반환
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
     * 현재 로케일의 권한 이름 반환
     */
    protected function localizedName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getLocalizedName()
        );
    }

    /**
     * 현재 로케일의 권한 설명 반환
     */
    protected function localizedDescription(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getLocalizedDescription()
        );
    }

    /**
     * 훅 매핑 관계
     *
     * @return HasMany<PermissionHook>
     */
    public function hooks(): HasMany
    {
        return $this->hasMany(PermissionHook::class);
    }

    /**
     * 특정 훅에 이 권한이 매핑되어 있는지 확인
     *
     * @param  string  $hookName  훅 이름
     */
    public function isMappedToHook(string $hookName): bool
    {
        return $this->hooks()->where('hook_name', $hookName)->exists();
    }
}
