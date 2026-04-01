<?php

namespace App\Http\Resources;

use App\Enums\ConsentType;
use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Helpers\TimezoneHelper;
use App\Traits\HasCountryAttributes;
use Carbon\Carbon;
use Illuminate\Http\Request;

class UserResource extends BaseApiResource
{
    use HasCountryAttributes;

    /**
     * 사용자 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> 변환된 사용자 데이터 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->getValue('uuid'),
            'name' => $this->getValue('name'),
            'nickname' => $this->getValue('nickname'),
            'email' => $this->getValue('email'),
            'avatar' => $this->resource->getAvatarUrl(),
            'language' => $this->getValue('language'),
            'language_label' => $this->getValue('language')
                ? __('user.language.'.$this->getValue('language'))
                : null,
            'country' => $this->getValue('country'),
            'status' => $this->getValue('status'),
            'status_label' => $this->getStatusLabel(),
            'status_variant' => $this->getStatusVariant(),
            'is_admin' => $this->resource->isAdmin(),
            'homepage' => $this->getValue('homepage'),
            'mobile' => $this->getValue('mobile'),
            'phone' => $this->getValue('phone'),
            'zipcode' => $this->getValue('zipcode'),
            'address' => $this->getValue('address'),
            'address_detail' => $this->getValue('address_detail'),
            'signature' => $this->getValue('signature'),
            'bio' => $this->getValue('bio'),
            'last_login_at' => $this->getValue('last_login_at')
                ? $this->formatDateTimeStringForUser($this->getValue('last_login_at'))
                : null,
            'email_verified_at' => $this->getValue('email_verified_at')
                ? $this->formatDateTimeStringForUser($this->getValue('email_verified_at'))
                : null,
            'timezone' => $this->getValue('timezone'),

            // 관계형 데이터 (필요시 로드)
            'modules_count' => $this->when(
                isset($this->modules_count),
                $this->getValue('modules_count')
            ),
            'plugins_count' => $this->when(
                isset($this->plugins_count),
                $this->getValue('plugins_count')
            ),
            'menus_count' => $this->when(
                isset($this->menus_count),
                $this->getValue('menus_count')
            ),

            // 관계형 데이터
            'modules' => $this->whenLoaded('modules', function () {
                return $this->getValue('modules', collect())->map(function ($module) {
                    $moduleResource = new ModuleResource($module);

                    return [
                        'id' => $moduleResource->getValue('id'),
                        'name' => $module->getLocalizedName(),
                        'slug' => $moduleResource->getValue('slug'),
                        'is_active' => $moduleResource->getValue('is_active'),
                    ];
                });
            }),

            'plugins' => $this->whenLoaded('plugins', function () {
                return $this->getValue('plugins', collect())->map(function ($plugin) {
                    $pluginResource = new PluginResource($plugin);

                    return [
                        'id' => $pluginResource->getValue('id'),
                        'name' => $plugin->getLocalizedName(),
                        'slug' => $pluginResource->getValue('slug'),
                        'is_active' => $pluginResource->getValue('is_active'),
                    ];
                });
            }),

            'menus' => $this->whenLoaded('menus', function () {
                return $this->getValue('menus', collect())->map(function ($menu) {
                    $menuResource = new MenuResource($menu);

                    return [
                        'id' => $menuResource->getValue('id'),
                        'title' => $menu->getLocalizedName(),
                        'url' => $menuResource->getValue('url'),
                        'is_active' => $menuResource->getValue('is_active'),
                    ];
                });
            }),

            'roles' => $this->whenLoaded('roles', function () {
                return $this->getValue('roles', collect())->map(function ($role) {
                    return [
                        'id' => $role->id,
                        'identifier' => $role->identifier,
                        'name' => $role->getLocalizedName(),
                    ];
                });
            }),

            // 권한은 역할을 통해 간접 로드됨 (roles.permissions)
            'permissions' => $this->whenLoaded('roles', function () {
                $allPermissions = collect();
                foreach ($this->resource->roles as $role) {
                    if ($role->relationLoaded('permissions')) {
                        $allPermissions = $allPermissions->merge($role->permissions);
                    }
                }

                return $allPermissions->unique('id')->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'identifier' => $permission->identifier,
                        'name' => $permission->getLocalizedName(),
                    ];
                })->values();
            }),

            // 약관 동의 이력 (전체 배열 - 플러그인 참조용)
            'consents' => $this->whenLoaded('consents', function () {
                return $this->resource->consents->map(fn ($c) => [
                    'consent_type' => $c->consent_type->value,
                    'agreed_at' => $this->formatDateTimeStringForUser($c->agreed_at),
                    'revoked_at' => $this->formatDateTimeStringForUser($c->revoked_at),
                ]);
            }),

            // 코어 UI용 개별 동의 키 (관리자 화면 직접 바인딩용)
            'terms_consent' => $this->whenLoaded('consents', function () {
                $consent = $this->resource->consents
                    ->first(fn ($c) => $c->consent_type === ConsentType::Terms);

                return $consent ? [
                    'agreed_at' => $this->formatDateTimeStringForUser($consent->agreed_at),
                ] : null;
            }),

            'privacy_consent' => $this->whenLoaded('consents', function () {
                $consent = $this->resource->consents
                    ->first(fn ($c) => $c->consent_type === ConsentType::Privacy);

                return $consent ? [
                    'agreed_at' => $this->formatDateTimeStringForUser($consent->agreed_at),
                ] : null;
            }),

            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 리소스별 권한 매핑을 반환합니다.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_read' => 'core.users.read',
            'can_create' => 'core.users.create',
            'can_update' => 'core.users.update',
            'can_delete' => 'core.users.delete',
            'can_assign_roles' => 'core.permissions.update',
        ];
    }

    /**
     * 리소스별 권한을 해석합니다.
     *
     * 슈퍼관리자 및 관리자 계정은 삭제할 수 없으므로 can_delete를 강제 false로 설정합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, bool> 권한 배열
     */
    protected function resolveAbilities(Request $request): array
    {
        $abilities = parent::resolveAbilities($request);

        if ($this->resource->isSuperAdmin() || $this->resource->isAdmin()) {
            $abilities['can_delete'] = false;
        }

        return $abilities;
    }

    /**
     * 소유자 필드를 반환합니다.
     *
     * User 리소스의 경우 자기 자신이 소유자이므로 id 필드를 사용합니다.
     *
     * @return string|null 소유자 필드명
     */
    protected function ownerField(): ?string
    {
        return 'id';
    }

    /**
     * 관리자용 상세 정보를 포함한 배열을 반환합니다.
     *
     * 주의: 이 메서드는 toArray() 외부에서 수동 호출되므로
     * $this->when() 대신 삼항 연산자를 사용해야 합니다.
     *
     * @return array<string, mixed> 상세 정보가 포함된 사용자 데이터
     */
    public function withAdminInfo(): array
    {
        $data = array_merge($this->toArray(request()), [
            'admin_memo' => $this->getValue('admin_memo'),
            'ip_address' => $this->getValue('ip_address'),
            'withdrawn_at' => $this->getValue('withdrawn_at')
                ? $this->formatDateTimeStringForUser($this->getValue('withdrawn_at'))
                : null,
            'blocked_at' => $this->getValue('blocked_at')
                ? $this->formatDateTimeStringForUser($this->getValue('blocked_at'))
                : null,
        ]);

        // Filter 훅: 모듈이 자신의 데이터를 응답에 병합
        return HookManager::applyFilters('core.user.filter_resource_data', $data, $this->resource);
    }

    /**
     * 목록용 간단한 형태의 배열을 반환합니다.
     *
     * 주의: 이 메서드는 toArray() 외부에서 수동 호출되므로
     * $this->when() 대신 삼항 연산자를 사용해야 합니다.
     * $this->when()은 Laravel이 toArray()를 처리할 때만 MissingValue를 필터링합니다.
     *
     * @param  Request|null  $request  HTTP 요청
     * @return array<string, mixed> 간략한 사용자 정보
     */
    public function toListArray(?Request $request = null): array
    {
        $request = $request ?? request();
        $countryCode = $this->getValue('country');
        $language = $this->getValue('language');
        $status = $this->getValue('status');
        $userStatus = $status ? UserStatus::tryFrom($status) : null;

        return [
            'uuid' => $this->getValue('uuid'),
            'name' => $this->getValue('name'),
            'nickname' => $this->getValue('nickname'),
            'email' => $this->getValue('email'),
            'language' => $language,
            'language_label' => $language ? __('user.language.'.$language) : null,
            'country' => $countryCode,
            'country_flag' => $countryCode ? $this->getCountryFlag($countryCode) : null,
            'country_name' => $countryCode ? $this->getCountryName($countryCode) : null,
            'status' => $status,
            'status_label' => $userStatus?->label(),
            'status_variant' => $userStatus?->variant(),
            'mobile' => $this->getValue('mobile'),
            'roles' => $this->resource->relationLoaded('roles')
                ? $this->resource->roles->map(fn ($role) => [
                    'id' => $role->id,
                    'identifier' => $role->identifier,
                    'name' => $role->getLocalizedName(),
                ])->toArray()
                : [],
            'email_verified_at' => $this->getValue('email_verified_at')
                ? $this->formatDateTimeStringForUser($this->getValue('email_verified_at'))
                : null,
            'last_login_at' => $this->getValue('last_login_at')
                ? $this->formatDateTimeStringForUser($this->getValue('last_login_at'))
                : null,
            'created_at' => $this->getValue('created_at')
                ? $this->formatDateForUser($this->getValue('created_at'))
                : null,
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 프로필용 안전한 형태의 배열을 반환합니다 (민감한 정보 제외).
     *
     * 주의: 이 메서드는 toArray() 외부에서 수동 호출되므로
     * $this->when() 대신 삼항 연산자를 사용해야 합니다.
     *
     * @return array<string, mixed> 안전한 프로필 정보
     */
    public function toProfileArray(): array
    {
        $status = $this->getValue('status');
        $userStatus = $status ? UserStatus::tryFrom($status) : null;

        $data = [
            'uuid' => $this->getValue('uuid'),
            'name' => $this->getValue('name'),
            'nickname' => $this->getValue('nickname'),
            'email' => $this->getValue('email'),
            'avatar' => $this->resource->getAvatarUrl(),
            'language' => $this->getValue('language'),
            'timezone' => $this->getValue('timezone'),
            'country' => $this->getValue('country'),
            'status' => $status,
            'status_label' => $userStatus?->label(),
            'status_variant' => $userStatus?->variant(),
            'homepage' => $this->getValue('homepage'),
            'mobile' => $this->getValue('mobile'),
            'phone' => $this->getValue('phone'),
            'zipcode' => $this->getValue('zipcode'),
            'address' => $this->getValue('address'),
            'address_detail' => $this->getValue('address_detail'),
            'signature' => $this->getValue('signature'),
            'bio' => $this->getValue('bio'),
            'is_super' => $this->resource->isSuperAdmin(),
            'is_admin' => $this->resource->isAdmin(),
            'withdrawn_at' => $this->getValue('withdrawn_at')
                ? $this->formatDateTimeStringForUser($this->getValue('withdrawn_at'))
                : null,
            'last_login_at' => $this->getValue('last_login_at')
                ? $this->formatDateTimeStringForUser($this->getValue('last_login_at'))
                : null,
            'last_login_human' => $this->getValue('last_login_at')
                ? TimezoneHelper::toUserCarbon(Carbon::parse($this->getValue('last_login_at')))?->diffForHumans()
                : null,
            'created_at' => $this->getValue('created_at')
                ? $this->formatDateTimeStringForUser($this->getValue('created_at'))
                : null,
            'is_owner' => request()->user()?->uuid === $this->resource->uuid,
        ];

        // Filter 훅: 모듈이 자신의 데이터를 프로필 응답에 병합
        return HookManager::applyFilters('core.user.filter_resource_data', $data, $this->resource);
    }

    /**
     * 통계용 데이터 배열을 반환합니다.
     *
     * 주의: 이 메서드는 toArray() 외부에서 수동 호출되므로
     * $this->when() 대신 삼항 연산자를 사용해야 합니다.
     *
     * @return array<string, mixed> 통계 정보가 포함된 사용자 데이터
     */
    public function toStatisticsArray(): array
    {
        return [
            'uuid' => $this->getValue('uuid'),
            'name' => $this->getValue('name'),
            'email' => $this->getValue('email'),
            'modules_count' => $this->getValue('modules_count', 0),
            'plugins_count' => $this->getValue('plugins_count', 0),
            'menus_count' => $this->getValue('menus_count', 0),
            'last_login_at' => $this->getValue('last_login_at')
                ? $this->formatDateTimeStringForUser($this->getValue('last_login_at'))
                : null,
            'created_at' => $this->getValue('created_at')
                ? $this->formatDateTimeStringForUser($this->getValue('created_at'))
                : null,
        ];
    }

    /**
     * 사용자 상태 라벨을 반환합니다.
     *
     * @return string|null 상태 라벨
     */
    protected function getStatusLabel(): ?string
    {
        $status = $this->getValue('status');
        $userStatus = $status ? UserStatus::tryFrom($status) : null;

        return $userStatus?->label();
    }

    /**
     * 사용자 상태 variant를 반환합니다.
     *
     * @return string|null 상태 variant (success, secondary, danger, warning)
     */
    protected function getStatusVariant(): ?string
    {
        $status = $this->getValue('status');
        $userStatus = $status ? UserStatus::tryFrom($status) : null;

        return $userStatus?->variant();
    }
}
