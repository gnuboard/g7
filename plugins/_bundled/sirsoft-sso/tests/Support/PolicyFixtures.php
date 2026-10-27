<?php

namespace Plugins\Sirsoft\Sso\Tests\Support;

use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Services\Policy\EmergencyAccess;
use Plugins\Sirsoft\Sso\Services\Policy\PolicyService;

/**
 * 정책, 관리자 화면 시험의 공용 준비 — 관리자(권한 지정), 최고관리자, IdP, 연결, 정책 값.
 *
 * @since 1.0.0
 */
trait PolicyFixtures
{
    /** 시험 회원 비밀번호 */
    protected string $password = 'Passw0rd!798';

    /**
     * 권한을 가진 관리자를 만듭니다.
     *
     * @param  array<int, string>  $permissions  권한 identifier
     * @param  array<string, mixed>  $attributes  회원 속성
     * @return User 관리자
     */
    protected function makeAdmin(array $permissions = [], array $attributes = []): User
    {
        $role = Role::query()->create([
            'identifier' => 'sso-admin-'.Str::lower(Str::random(8)),
            'name' => ['ko' => '시험 관리자', 'en' => 'Test Admin'],
            'description' => ['ko' => '시험', 'en' => 'Test'],
        ]);

        foreach (array_merge(['core.dashboard.read'], $permissions) as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => PermissionType::Admin]
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user = $this->makeMember($attributes);
        $user->roles()->attach($role->id);
        $user->flushPermissionCaches();

        return $user;
    }

    /**
     * 모든 SSO 권한을 가진 최고관리자를 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  회원 속성
     * @return User 최고관리자
     */
    protected function makeSuper(array $attributes = []): User
    {
        return $this->makeAdmin(self::allSsoPermissions(), $attributes + ['is_super' => true]);
    }

    /**
     * SSO 권한 전부.
     *
     * @return array<int, string> 권한 identifier
     */
    protected static function allSsoPermissions(): array
    {
        return [
            'sirsoft-sso.settings.view', 'sirsoft-sso.settings.update', 'sirsoft-sso.accounts.view',
            'sirsoft-sso.accounts.unlink', 'sirsoft-sso.accounts.provision', 'sirsoft-sso.logs.view',
        ];
    }

    /**
     * 비밀번호 있는 일반 회원을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  회원 속성
     * @return User 회원
     */
    protected function makeMember(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'meas798.'.Str::lower(Str::random(10)).'@example.test',
            'password' => Hash::make($this->password),
        ], $attributes));
    }

    /**
     * 역할을 하나 만듭니다.
     *
     * @param  string  $identifier  역할 identifier
     * @return Role 역할
     */
    protected function makeRole(string $identifier): Role
    {
        return Role::query()->firstOrCreate(['identifier' => $identifier], [
            'name' => ['ko' => $identifier, 'en' => $identifier],
            'description' => ['ko' => '시험', 'en' => 'Test'],
        ]);
    }

    /**
     * 활성 OIDC IdP 를 만듭니다 (드라이버 설정은 저장만 — 왕복하지 않는 시험용).
     *
     * @param  string  $key  IdP 키
     * @param  array<string, mixed>  $attributes  더할 속성
     * @return IdentityProvider 만든 IdP
     */
    protected function makeIdp(string $key = 'corp', array $attributes = []): IdentityProvider
    {
        return IdentityProvider::query()->create(array_merge([
            'key' => $key,
            'protocol' => 'oidc',
            'enabled' => true,
            'label' => ['ko' => '본사 계정', 'en' => 'HQ account'],
            'config' => ['discovery_url' => 'https://idp.corp798.test/.well-known/openid-configuration', 'client_id' => 'g7', 'subject_claim' => 'sub'],
            'secrets' => ['client_secret' => 'secret-798'],
            'owned_domains' => ['corp798.test'],
        ], $attributes));
    }

    /**
     * 회원과 IdP 를 연결합니다.
     *
     * @param  User  $user  회원
     * @param  IdentityProvider  $idp  대상 IdP
     * @param  string|null  $subject  IdP 고유 식별자
     * @param  array<string, mixed>  $attributes  더할 속성
     * @return SsoAccount 연결
     */
    protected function linkAccount(User $user, IdentityProvider $idp, ?string $subject = null, array $attributes = []): SsoAccount
    {
        return SsoAccount::query()->create(array_merge([
            'idp_id' => $idp->id,
            'user_id' => $user->id,
            'subject' => $subject ?? 'sub-'.Str::lower(Str::random(10)),
            'linked_via' => 'admin',
            'active' => true,
            'linked_at' => now(),
        ], $attributes));
    }

    /**
     * 플러그인 서버 문구를 번역기에 올립니다 — 시험 앱은 설치 디렉터리의 확장만 문구를 읽으므로 _bundled 원본을 직접 등록한다.
     *
     * @return void 반환값 없음
     */
    protected function loadPluginLang(): void
    {
        app('translator')->addNamespace('sirsoft-sso', dirname(__DIR__, 2).'/lang');
    }

    /**
     * 정책 값을 바로 저장합니다 (검사 없이 — 시험 준비).
     *
     * @param  array<string, mixed>  $values  바꿀 값
     * @return void 반환값 없음
     */
    protected function setPolicy(array $values): void
    {
        $policy = app(PolicyService::class);
        $policy->storeRaw(array_merge($policy->current(), $values));
        app(EmergencyAccess::class)->flush();
    }
}
