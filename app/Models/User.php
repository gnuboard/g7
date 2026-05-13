<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\MenuPermissionType;
use App\Enums\PermissionType;
use App\Enums\ScopeType;
use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use App\Contracts\UniqueIdServiceInterface;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'name' => ['label_key' => 'activity_log.fields.name', 'type' => 'text'],
        'nickname' => ['label_key' => 'activity_log.fields.nickname', 'type' => 'text'],
        'email' => ['label_key' => 'activity_log.fields.email', 'type' => 'text'],
        'language' => ['label_key' => 'activity_log.fields.language', 'type' => 'text'],
        'timezone' => ['label_key' => 'activity_log.fields.timezone', 'type' => 'text'],
        'country' => ['label_key' => 'activity_log.fields.country', 'type' => 'text'],
        'status' => ['label_key' => 'activity_log.fields.status', 'type' => 'enum', 'enum' => \App\Enums\UserStatus::class],
        'is_super' => ['label_key' => 'activity_log.fields.is_super', 'type' => 'boolean'],
        'homepage' => ['label_key' => 'activity_log.fields.homepage', 'type' => 'text'],
        'mobile' => ['label_key' => 'activity_log.fields.mobile', 'type' => 'text'],
        'phone' => ['label_key' => 'activity_log.fields.phone', 'type' => 'text'],
        'zipcode' => ['label_key' => 'activity_log.fields.zipcode', 'type' => 'text'],
        'address' => ['label_key' => 'activity_log.fields.address', 'type' => 'text'],
        'address_detail' => ['label_key' => 'activity_log.fields.address_detail', 'type' => 'text'],
        'bio' => ['label_key' => 'activity_log.fields.bio', 'type' => 'text'],
        'admin_memo' => ['label_key' => 'activity_log.fields.admin_memo', 'type' => 'text'],
    ];

    /**
     * 권한별 effective scope 캐시 (인스턴스 레벨)
     *
     * @var array<string, string|null|false>
     */
    protected array $effectiveScopeCache = [];

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'users';

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

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'name',
        'nickname',
        'email',
        'password',
        'language',
        'is_super',
        'timezone',
        'country',
        'status',
        'homepage',
        'mobile',
        'phone',
        'zipcode',
        'address',
        'address_detail',
        'signature',
        'bio',
        'avatar',
        'admin_memo',
        'ip_address',
        'last_login_at',
        'withdrawn_at',
        'blocked_at',
        'identity_verified_at',
        'identity_verified_provider',
        'identity_verified_purpose_last',
        'identity_hash',
        'mobile_verified_at',
        'failed_login_attempts',
        'locked_until',
        'last_failed_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'id',
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'blocked_at' => 'datetime',
            'identity_verified_at' => 'datetime',
            'mobile_verified_at' => 'datetime',
            'is_super' => 'boolean',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
            'last_failed_login_at' => 'datetime',
        ];
    }

    /**
     * Route Model Binding에 사용할 키 이름을 반환합니다.
     *
     * @return string 라우트 키 이름
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * 모델 부팅 시 UUID 자동 생성을 등록합니다.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $user) {
            if (empty($user->uuid)) {
                $user->uuid = app(UniqueIdServiceInterface::class)->generateUuid();
            }
        });
    }

    /**
     * 사용자의 약관 동의 이력들과의 관계를 정의합니다.
     */
    public function consents(): HasMany
    {
        return $this->hasMany(UserConsent::class);
    }

    /**
     * 사용자가 생성한 모듈들과의 관계를 정의합니다.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function modules()
    {
        return $this->hasMany(Module::class, 'created_by');
    }

    /**
     * 사용자가 생성한 플러그인들과의 관계를 정의합니다.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function plugins()
    {
        return $this->hasMany(Plugin::class, 'created_by');
    }

    /**
     * 사용자가 가진 역할들과의 관계를 정의합니다.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot(['assigned_at', 'assigned_by'])
            ->withTimestamps();
    }

    /**
     * 사용자의 개별 메뉴 권한들과의 관계를 정의합니다.
     *
     * @deprecated role_menus 피벗 테이블 사용. 역할 기반 권한을 사용하세요.
     */
    public function menuPermissions(): HasMany
    {
        return $this->hasMany(MenuPermission::class);
    }

    /**
     * 특정 권한을 가지고 있는지 확인합니다.
     *
     * @param  string  $permission  권한 식별자
     * @param  PermissionType|null  $type  권한 타입 (null이면 타입 구분 없이 체크)
     */
    public function hasPermission(string $permission, ?PermissionType $type = null): bool
    {
        return $this->roles()
            ->whereHas('permissions', function ($query) use ($permission, $type) {
                $query->where('identifier', $permission);
                if ($type !== null) {
                    $query->where('type', $type);
                }
            })
            ->exists();
    }

    /**
     * 여러 권한을 가지고 있는지 확인합니다.
     *
     * @param  array  $permissions  권한 식별자 배열
     * @param  bool  $requireAll  모든 권한이 필요한지 여부 (true: AND, false: OR)
     * @param  PermissionType|null  $type  권한 타입 (null이면 타입 구분 없이 체크)
     */
    public function hasPermissions(array $permissions, bool $requireAll = true, ?PermissionType $type = null): bool
    {
        $userPermissions = $this->roles()
            ->whereHas('permissions', function ($query) use ($permissions, $type) {
                $query->whereIn('identifier', $permissions);
                if ($type !== null) {
                    $query->where('type', $type);
                }
            })
            ->with(['permissions' => function ($query) use ($permissions, $type) {
                $query->whereIn('identifier', $permissions);
                if ($type !== null) {
                    $query->where('type', $type);
                }
            }])
            ->get()
            ->pluck('permissions')
            ->flatten()
            ->pluck('identifier')
            ->unique()
            ->count();

        return $requireAll ? $userPermissions === count($permissions) : $userPermissions > 0;
    }

    /**
     * 해당 권한에 대한 effective scope를 반환합니다.
     *
     * 사용자가 보유한 역할들의 scope_type을 수집하여 union 정책을 적용합니다.
     * 우선순위: null(전체) > 'role'(소유역할) > 'self'(본인)
     * 인스턴스 레벨 캐싱으로 동일 권한 반복 조회 시 DB 쿼리를 방지합니다.
     *
     * @param  string  $identifier  권한 식별자
     * @return string|null null(전체), 'role'(소유역할), 'self'(본인)
     */
    public function getEffectiveScopeForPermission(string $identifier): ?string
    {
        // 캐시 히트 시 즉시 반환 (false = 캐시된 null과 구분)
        if (array_key_exists($identifier, $this->effectiveScopeCache)) {
            return $this->effectiveScopeCache[$identifier];
        }

        $scopeTypes = $this->roles()
            ->whereHas('permissions', function ($query) use ($identifier) {
                $query->where('identifier', $identifier);
            })
            ->with(['permissions' => function ($query) use ($identifier) {
                $query->where('identifier', $identifier);
            }])
            ->get()
            ->pluck('permissions')
            ->flatten()
            ->pluck('pivot.scope_type');

        // 권한 미보유 시 null 반환 (기본값: 전체 접근)
        if ($scopeTypes->isEmpty()) {
            return $this->effectiveScopeCache[$identifier] = null;
        }

        // union 정책: 하나라도 null → 전체 접근
        if ($scopeTypes->contains(null)) {
            return $this->effectiveScopeCache[$identifier] = null;
        }

        // ScopeType Enum 값을 문자열로 변환하여 비교
        $values = $scopeTypes->map(fn ($scope) => $scope instanceof ScopeType ? $scope->value : $scope);

        // 하나라도 'role' → role 적용
        if ($values->contains('role')) {
            return $this->effectiveScopeCache[$identifier] = 'role';
        }

        // 모두 'self' → self
        return $this->effectiveScopeCache[$identifier] = 'self';
    }

    /**
     * 특정 역할을 가지고 있는지 확인합니다.
     *
     * @param  string  $role  역할 식별자
     */
    public function hasRole(string $role): bool
    {
        return $this->roles()->where('identifier', $role)->exists();
    }

    /**
     * 여러 역할을 가지고 있는지 확인합니다.
     *
     * @param  array  $roles  역할 식별자 배열
     * @param  bool  $requireAll  모든 역할이 필요한지 여부 (true: AND, false: OR)
     */
    public function hasRoles(array $roles, bool $requireAll = true): bool
    {
        $userRoles = $this->roles()->whereIn('identifier', $roles)->count();

        return $requireAll ? $userRoles === count($roles) : $userRoles > 0;
    }

    /**
     * 관리자인지 확인합니다.
     *
     * 사용자가 보유한 권한 중 type='admin'인 권한이 하나라도 있으면 관리자로 판단합니다.
     *
     * @return bool 관리자 여부
     */
    public function isAdmin(): bool
    {
        return $this->roles()
            ->whereHas('permissions', function ($query) {
                $query->where('type', PermissionType::Admin);
            })
            ->exists();
    }

    /**
     * 슈퍼 관리자인지 확인합니다.
     *
     * 슈퍼 관리자는 삭제할 수 없으며, 다른 관리자의 권한을 관리할 수 있습니다.
     *
     * @return bool 슈퍼 관리자 여부
     */
    public function isSuperAdmin(): bool
    {
        return $this->is_super === true;
    }

    /**
     * 슈퍼 관리자만 조회합니다.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSuperAdmins($query)
    {
        return $query->where('is_super', true);
    }

    /**
     * 특정 메뉴에 대한 권한을 가지고 있는지 확인합니다.
     *
     * role_menus 피벗 테이블을 통해 역할 기반 권한을 확인합니다.
     *
     * @param  int  $menuId  메뉴 ID
     * @param  MenuPermissionType|string  $permissionType  권한 유형 (read, write, delete)
     */
    public function hasMenuPermission(int $menuId, MenuPermissionType|string $permissionType = 'read'): bool
    {
        $type = $permissionType instanceof MenuPermissionType
            ? $permissionType->value
            : $permissionType;

        // 역할 기반 권한 확인 (role_menus 피벗 테이블)
        return $this->roles()
            ->whereHas('menus', function ($query) use ($menuId, $type) {
                $query->where('menus.id', $menuId)
                    ->wherePivot('permission_type', $type);
            })
            ->exists();
    }

    /**
     * 특정 slug의 메뉴에 대한 접근 권한을 가지고 있는지 확인합니다.
     *
     * role_menus 피벗 테이블을 통해 역할 기반 메뉴 접근 권한을 확인합니다.
     *
     * @param  string  $slug  메뉴 slug (예: 'admin-users')
     * @param  MenuPermissionType|string  $permissionType  권한 유형 (read, write, delete)
     * @return bool 메뉴 접근 권한 보유 여부
     */
    public function hasMenuAccessBySlug(string $slug, MenuPermissionType|string $permissionType = 'read'): bool
    {
        $type = $permissionType instanceof MenuPermissionType
            ? $permissionType->value
            : $permissionType;

        return $this->roles()
            ->whereHas('menus', function ($query) use ($slug, $type) {
                $query->where('menus.slug', $slug)
                    ->where('role_menus.permission_type', $type);
            })
            ->exists();
    }

    /**
     * 사용자가 생성한 메뉴들과의 관계를 정의합니다.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function menus()
    {
        return $this->hasMany(Menu::class, 'created_by');
    }

    /**
     * 사용자의 시간대를 반환합니다.
     * 설정되지 않은 경우 기본 사용자 시간대를 반환합니다.
     */
    public function getTimezone(): string
    {
        return $this->timezone ?? config('app.default_user_timezone', 'Asia/Seoul');
    }

    /**
     * 사용자의 아바타 첨부파일과의 관계를 정의합니다.
     *
     * attachments 테이블의 다형성 관계를 사용합니다.
     * collection='avatar'로 아바타 전용 첨부파일을 구분합니다.
     */
    public function avatarAttachment(): MorphOne
    {
        return $this->morphOne(Attachment::class, 'attachmentable')
            ->where('collection', 'avatar');
    }

    /**
     * 아바타 이미지 URL을 반환합니다.
     *
     * attachments 테이블의 다형성 관계를 사용하여 아바타를 조회합니다.
     * 관계가 없으면 레거시 avatar 필드를 확인합니다.
     *
     * @return string|null 아바타 URL (없으면 null)
     */
    public function getAvatarUrl(): ?string
    {
        // 새로운 방식: attachments 테이블 다형성 관계
        $attachment = $this->avatarAttachment;
        if ($attachment) {
            return $attachment->download_url;
        }

        // 레거시 방식: avatar 필드 (하위 호환)
        if (! empty($this->avatar)) {
            return url('storage/attachments/avatars/'.$this->avatar);
        }

        return null;
    }

    /**
     * 활성화된 사용자만 조회합니다.
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('withdrawn_at')
            ->where('status', '!=', UserStatus::Withdrawn);
    }

    /**
     * 탈퇴한 사용자만 조회합니다.
     */
    public function scopeWithdrawn(Builder $query): void
    {
        $query->whereNotNull('withdrawn_at')
            ->where('status', UserStatus::Withdrawn);
    }

    /**
     * 탈퇴한 사용자인지 확인합니다.
     *
     * @return bool 탈퇴 여부
     */
    public function isWithdrawn(): bool
    {
        return $this->withdrawn_at !== null && $this->status === UserStatus::Withdrawn->value;
    }

    /**
     * 사용자를 탈퇴 처리합니다.
     *
     * 이름, 이메일, 닉네임에 suffix를 추가하여 익명화하고,
     * 상태를 'withdrawn'으로 변경하며 탈퇴 일시를 기록합니다.
     *
     * @return bool 저장 성공 여부
     */
    public function withdraw(): bool
    {
        $now = now();
        $dateSuffix = $now->format('Ymd'); // 예: 20260127

        // 이름에 suffix 추가 (있는 경우만)
        if ($this->name) {
            $this->name = $this->name.'_탈퇴_'.$dateSuffix;
        }

        // 이메일에 suffix 추가 (필수)
        $this->email = $this->email.'_deleted_'.$dateSuffix;

        // 닉네임에 suffix 추가 (있는 경우만, 날짜 없이)
        if ($this->nickname) {
            $this->nickname = $this->nickname.'_탈퇴';
        }

        // 상태 변경
        $this->status = UserStatus::Withdrawn->value;
        $this->withdrawn_at = $now;

        return $this->save();
    }
}
