<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Contracts\UniqueIdServiceInterface;
use App\Enums\MenuPermissionType;
use App\Enums\PermissionType;
use App\Enums\ScopeType;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * 닉네임 컬럼 최대 길이 (마이그레이션에서 명시한 값)
     */
    public const NICKNAME_MAX_LENGTH = 50;

    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'name' => ['label_key' => 'activity_log.fields.name', 'type' => 'text'],
        'nickname' => ['label_key' => 'activity_log.fields.nickname', 'type' => 'text'],
        'email' => ['label_key' => 'activity_log.fields.email', 'type' => 'text'],
        'language' => ['label_key' => 'activity_log.fields.language', 'type' => 'text'],
        'timezone' => ['label_key' => 'activity_log.fields.timezone', 'type' => 'text'],
        'country' => ['label_key' => 'activity_log.fields.country', 'type' => 'text'],
        'status' => ['label_key' => 'activity_log.fields.status', 'type' => 'enum', 'enum' => UserStatus::class],
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
     * 보유 권한 부여 내역 캐시 (인스턴스 레벨)
     *
     * 한 요청에서 권한 판정은 수십~수백 번 일어난다 — 레이아웃 노드마다, 목록 행마다,
     * 리소스 필드마다 부른다. 판정마다 DB 에 물으면 노드/행 수에 비례해 쿼리가 늘어난다.
     * 첫 판정에서 `roles.permissions` 를 한 번 적재해 두고 이후로는 배열만 본다.
     *
     * 캐시 수명은 **모델 인스턴스**, 즉 요청 스코프다. 크로스 요청 캐시를 두지 않으므로
     * 권한을 바꾸면 다음 요청부터 즉시 반영된다.
     *
     * 구조: 권한 식별자 ⇒ [{type, scope_type}, ...] (같은 권한을 여러 역할이 주면 여러 건)
     *
     * @var array<string, array<int, array{type: string|null, scope_type: string|null}>>|null
     */
    protected ?array $permissionGrantsCache = null;

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
        'locked_permanently',
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
            'locked_permanently' => 'boolean',
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
     * 알림 발송 시 사용할 수신자 선호 로케일을 반환합니다.
     *
     * 사용자 언어 SSoT 는 users.language 컬럼입니다. 지원 로케일이면 해당 값을,
     * 빈값/미지원 로케일이면 사이트 기본 로케일을 반환합니다. (요청자 locale 폴백 없음)
     *
     * @return string|null 지원 로케일이면 해당 값, 아니면 사이트 기본 로케일
     */
    public function preferredLocale(): ?string
    {
        $supported = config('app.supported_locales', ['ko', 'en']);

        return ($this->language && in_array($this->language, $supported, true))
            ? $this->language
            : config('app.locale', 'ko');
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
     * @return HasMany
     */
    public function modules()
    {
        return $this->hasMany(Module::class, 'created_by');
    }

    /**
     * 사용자가 생성한 플러그인들과의 관계를 정의합니다.
     *
     * @return HasMany
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
        $grants = $this->permissionGrants()[$permission] ?? null;

        if ($grants === null) {
            return false;
        }

        if ($type === null) {
            return true;
        }

        foreach ($grants as $grant) {
            if ($grant['type'] === $type->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * 보유 권한 부여 내역을 반환합니다 (인스턴스 캐시).
     *
     * 역할 → 권한을 한 번만 적재하고, 이후 판정은 전부 이 배열에서 이루어집니다.
     *
     * @return array<string, array<int, array{type: string|null, scope_type: string|null}>> 권한 식별자 ⇒ 부여 내역
     */
    protected function permissionGrants(): array
    {
        if ($this->permissionGrantsCache !== null) {
            return $this->permissionGrantsCache;
        }

        $grants = [];

        foreach ($this->roles()->with('permissions')->get() as $role) {
            foreach ($role->permissions as $permission) {
                $grants[$permission->identifier][] = [
                    'type' => $this->enumValue($permission->type),
                    'scope_type' => $this->enumValue($permission->pivot->scope_type ?? null),
                ];
            }
        }

        return $this->permissionGrantsCache = $grants;
    }

    /**
     * Enum 또는 원시 값을 문자열로 정규화합니다.
     *
     * 캐스팅 설정 유무에 따라 Enum 인스턴스와 문자열이 섞여 들어오므로 한 형태로 맞춥니다.
     *
     * @param  mixed  $value  Enum 인스턴스 · 문자열 · null
     * @return string|null 정규화된 문자열 (부재 시 null)
     */
    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return $value === null ? null : (string) $value;
    }

    /**
     * 권한 판정 캐시를 비웁니다.
     *
     * 같은 인스턴스에서 역할을 바꾼 직후 다시 판정해야 하는 경우에 호출합니다.
     * (역할 동기화 서비스가 호출하며, 일반 조회 경로에서는 필요하지 않습니다)
     */
    public function flushPermissionCaches(): void
    {
        $this->permissionGrantsCache = null;
        $this->effectiveScopeCache = [];
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
        // 같은 권한 집합을 hasPermission 과 공유한다 — 권한 개수만큼 쿼리가 늘지 않는다.
        // 비교 기준(고유 매칭 수 vs 인자 개수)은 종전과 동일하게 유지한다.
        $matched = 0;

        foreach (array_unique($permissions) as $permission) {
            if ($this->hasPermission($permission, $type)) {
                $matched++;
            }
        }

        return $requireAll ? $matched === count($permissions) : $matched > 0;
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

        // 같은 권한 집합에서 scope_type 만 뽑는다 — 권한마다 쿼리를 다시 내지 않는다.
        $values = array_column($this->permissionGrants()[$identifier] ?? [], 'scope_type');

        // 권한 미보유 시 null 반환 (기본값: 전체 접근)
        if ($values === []) {
            return $this->effectiveScopeCache[$identifier] = null;
        }

        // union 정책: 하나라도 null → 전체 접근
        if (in_array(null, $values, true)) {
            return $this->effectiveScopeCache[$identifier] = null;
        }

        // 하나라도 'role' → role 적용
        if (in_array(ScopeType::Role->value, $values, true)) {
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
        // 같은 권한 집합을 재사용한다 — 관리자 판정은 미들웨어·리소스·레이아웃에서 반복 호출된다.
        foreach ($this->permissionGrants() as $grants) {
            foreach ($grants as $grant) {
                if ($grant['type'] === PermissionType::Admin->value) {
                    return true;
                }
            }
        }

        return false;
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
     * @param  Builder  $query
     * @return Builder
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
     * @return HasMany
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
        // 멱등 가드 — 이미 탈퇴한 계정에 다시 호출해도 접미사가 겹쳐 붙지 않는다.
        if ($this->isWithdrawn()) {
            return true;
        }

        $now = now();
        $dateSuffix = $now->format('Ymd'); // 예: 20260127

        // name/email 은 마이그레이션에서 길이를 지정하지 않은 문자열 컬럼이므로
        // 스키마 기본 길이(AppServiceProvider 의 defaultStringLength)를 그대로 따른다.
        // 여기에 리터럴을 박으면 기본 길이를 바꾸는 순간 조용히 어긋난다.
        $defaultLength = SchemaBuilder::$defaultStringLength ?? 255;

        // 이름에 suffix 추가 (있는 경우만)
        if ($this->name) {
            $this->name = $this->appendWithdrawnSuffix($this->name, '_탈퇴_'.$dateSuffix, $defaultLength);
        }

        // 이메일에 suffix 추가 (필수)
        //
        // 접미사에 사용자 ID 를 포함해 구조적으로 유일하게 만든다. 날짜만 붙이면
        // 같은 이메일로 재가입한 회원이 같은 날 다시 탈퇴할 때 email unique 에
        // 걸려 탈퇴가 실패한다(공개이슈 #112).
        $this->email = $this->appendWithdrawnSuffix(
            $this->email,
            '_deleted_'.$dateSuffix.'_'.$this->id,
            $defaultLength,
        );

        // 닉네임에 suffix 추가 (있는 경우만, 날짜 없이)
        if ($this->nickname) {
            // nickname 은 마이그레이션에서 길이를 명시(50)한 컬럼이다.
            // 이 접미사는 유일성 토큰(id)이 없다 — 현재 nickname/name 에 unique 인덱스가
            // 없어 무해하지만, 향후 unique 인덱스를 추가하면 email 과 동일한 충돌
            // (같은 값 재가입 후 재탈퇴 실패)이 재발하므로 그때 id 부착으로 전환할 것.
            $this->nickname = $this->appendWithdrawnSuffix($this->nickname, '_탈퇴', self::NICKNAME_MAX_LENGTH);
        }

        // 상태 변경
        $this->status = UserStatus::Withdrawn->value;
        $this->withdrawn_at = $now;

        return $this->save();
    }

    /**
     * 탈퇴 접미사를 컬럼 길이 안에서 부착합니다.
     *
     * 원값이 길면 접미사 길이만큼 앞을 잘라 붙입니다 — 자르지 않으면 접미사가 컬럼
     * 길이를 넘겨 strict mode 저장 예외가 나고, 탈퇴가 중간에서 실패합니다.
     *
     * @param  string  $value  원본 값
     * @param  string  $suffix  부착할 접미사
     * @param  int  $maxLength  컬럼 최대 길이
     * @return string 접미사가 부착된 값
     */
    protected function appendWithdrawnSuffix(string $value, string $suffix, int $maxLength): string
    {
        $available = $maxLength - mb_strlen($suffix);

        if ($available < 0) {
            $available = 0;
        }

        return mb_substr($value, 0, $available).$suffix;
    }
}
