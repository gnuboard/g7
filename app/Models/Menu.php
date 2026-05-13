<?php

namespace App\Models;

use App\Enums\ExtensionOwnerType;
use App\Enums\MenuPermissionType;
use App\Models\Concerns\HasUserOverrides;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * 메뉴 모델
 *
 * 역할 기반 접근 제어를 지원합니다.
 *
 * 사용자가 관리자 UI 로 수정한 필드(name/icon/order/url) 는 `HasUserOverrides` trait
 * 에 의해 `user_overrides` 컬럼에 자동 누적 기록되며, 이후 코어/확장 업데이트의
 * `syncMenu()` 경로에서 필드 단위로 보존됩니다.
 */
class Menu extends Model
{
    use HasFactory, HasUserOverrides;

    /**
     * `HasUserOverrides` 가 추적할 필드.
     *
     * `ExtensionMenuSyncHelper::syncMenu()` L80~107 의 upsert 시 필드 단위 보존 대상과 일치.
     *
     * @var array<int, string>
     */
    protected array $trackableFields = ['name', 'icon', 'order', 'url'];

    /**
     * 다국어 JSON 컬럼 — sub-key dot-path 단위 user_overrides 보존.
     *
     * @var array<int, string>
     */
    protected array $translatableTrackableFields = ['name'];

    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'url' => ['label_key' => 'activity_log.fields.url', 'type' => 'text'],
        'icon' => ['label_key' => 'activity_log.fields.icon', 'type' => 'text'],
        'parent_id' => ['label_key' => 'activity_log.fields.parent_id', 'type' => 'number'],
        'order' => ['label_key' => 'activity_log.fields.order', 'type' => 'number'],
        'is_active' => ['label_key' => 'activity_log.fields.is_active', 'type' => 'boolean'],
    ];

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'menus';

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
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'url',
        'icon',
        'parent_id',
        'order',
        'is_active',
        'extension_type',
        'extension_identifier',
        'user_overrides',
        'created_by',
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
            'is_active' => 'boolean',
            'order' => 'integer',
            'parent_id' => 'integer',
            'extension_type' => ExtensionOwnerType::class,
            'user_overrides' => 'array',
        ];
    }

    /**
     * 메뉴가 활성화되어 있는지 확인합니다.
     *
     * @return bool 활성화 상태 여부
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * 메뉴를 생성한 사용자와의 관계를 정의합니다.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 부모 메뉴와의 관계를 정의합니다.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'parent_id');
    }

    /**
     * 자식 메뉴들과의 관계를 정의합니다.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Menu::class, 'parent_id')->orderBy('order');
    }

    /**
     * 활성화된 자식 메뉴들과의 관계를 정의합니다.
     */
    public function activeChildren(): HasMany
    {
        return $this->children()->where('is_active', true);
    }

    /**
     * 접근 가능한 역할들 (권한 타입 포함)
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_menus')
            ->withPivot('permission_type')
            ->withTimestamps();
    }

    /**
     * 최상위 메뉴들(부모가 없는 메뉴들)을 조회하는 스코프를 정의합니다.
     */
    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_id')->orderBy('order');
    }

    /**
     * 활성화된 메뉴들만 조회하는 스코프를 정의합니다.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * 확장별 메뉴를 조회하는 스코프
     *
     * @param  ExtensionOwnerType  $type  확장 타입
     * @param  string|null  $identifier  확장 식별자
     */
    public function scopeByExtension(Builder $query, ExtensionOwnerType $type, ?string $identifier = null): Builder
    {
        $query->where('extension_type', $type);

        if ($identifier !== null) {
            $query->where('extension_identifier', $identifier);
        }

        return $query;
    }

    /**
     * 사용자에게 접근 가능한 메뉴들만 조회하는 스코프
     *
     * 권한 체크 로직:
     * 1. 코어/사용자 생성 메뉴: 사용자 역할에 read 권한 필요
     * 2. 확장 메뉴(module/plugin): 확장이 활성화되어 있고 역할 권한이 있어야 접근 가능
     *
     * admin 역할은 role_menus 피벗에 모든 메뉴가 할당되어 있으므로
     * 별도 바이패스 없이 정상적인 쿼리로 동일 결과를 도출합니다.
     *
     * @param  User  $user  접근 권한을 확인할 사용자
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        $userRoleIds = $user->roles()->pluck('roles.id')->toArray();

        return $query->where(function (Builder $query) use ($userRoleIds) {
            // 코어/사용자 생성 메뉴: 사용자 역할에 read 권한 필요
            $query->where(function (Builder $q) use ($userRoleIds) {
                $q->where(function (Builder $coreQ) {
                    $coreQ->whereNull('extension_type')
                        ->orWhere('extension_type', ExtensionOwnerType::Core);
                })
                    ->whereHas('roles', function (Builder $roleQ) use ($userRoleIds) {
                        $roleQ->whereIn('roles.id', $userRoleIds)
                            ->where('role_menus.permission_type', MenuPermissionType::Read->value);
                    });
            });
        })->orWhere(function (Builder $query) use ($userRoleIds) {
            // 확장 메뉴 (활성화된 모듈/플러그인이면서 역할 권한이 있는 경우)
            $query->whereIn('extension_type', [ExtensionOwnerType::Module, ExtensionOwnerType::Plugin])
                ->where(function (Builder $extQ) {
                    // 모듈 메뉴: modules 테이블에서 활성 상태 확인
                    $extQ->where(function (Builder $moduleQ) {
                        $moduleQ->where('extension_type', ExtensionOwnerType::Module)
                            ->whereExists(function ($subQuery) {
                                $subQuery->select(DB::raw(1))
                                    ->from('modules')
                                    ->whereColumn('modules.identifier', 'menus.extension_identifier')
                                    ->where('modules.status', 'active');
                            });
                    })
                    // 플러그인 메뉴: plugins 테이블에서 활성 상태 확인
                        ->orWhere(function (Builder $pluginQ) {
                            $pluginQ->where('extension_type', ExtensionOwnerType::Plugin)
                                ->whereExists(function ($subQuery) {
                                    $subQuery->select(DB::raw(1))
                                        ->from('plugins')
                                        ->whereColumn('plugins.identifier', 'menus.extension_identifier')
                                        ->where('plugins.status', 'active');
                                });
                        });
                })
                ->whereHas('roles', function (Builder $roleQ) use ($userRoleIds) {
                    $roleQ->whereIn('roles.id', $userRoleIds)
                        ->where('role_menus.permission_type', MenuPermissionType::Read->value);
                });
        });
    }

    /**
     * 지정된 로케일의 메뉴 이름 반환
     *
     * @param  string|null  $locale  로케일
     * @return string 메뉴 이름
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
     * 현재 로케일의 메뉴 이름 반환
     */
    protected function localizedName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->getLocalizedName()
        );
    }

    // ============================================================
    // 하위 호환 메서드 (Deprecated)
    // ============================================================

    /**
     * 메뉴 권한들과의 관계를 정의합니다.
     *
     * @deprecated role_menus 피벗 테이블 사용. roles() 메서드를 사용하세요.
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(MenuPermission::class);
    }
}
