<?php

namespace Plugins\Sirsoft\Sso\Tests\Support;

use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Services\ProtocolRegistry;

/**
 * 프로비저닝, 역할, 속성, 세션 시험 도우미 (P3 시험 클래스가 `PluginTestCase` 와 함께 쓴다).
 *
 * @since 1.0.0
 */
trait ProvisioningTestHelpers
{
    /**
     * 시험 IdP 를 만듭니다 (프로토콜은 시험이 고른다, 드라이버는 따로 꽂는다).
     *
     * @param  array<string, mixed>  $attributes  바꿀 속성
     * @return IdentityProvider 대상 IdP
     */
    protected function makeIdp(array $attributes = []): IdentityProvider
    {
        return IdentityProvider::query()->create(array_merge([
            'key' => 'p3_'.Str::lower(Str::random(8)),
            'protocol' => 'oidc',
            'enabled' => true,
            'label' => ['ko' => '시험 회사', 'en' => 'Test company'],
            'config' => [],
            'owned_domains' => ['corp798.test'],
            'provisioning_mode' => 'preregistered',
            'deprovision' => ['scim' => true, 'periodic' => false, 'periodic_minutes' => 60, 'on_login' => true, 'withdraw_after_days' => null],
        ], $attributes));
    }

    /**
     * IdP 에 SCIM 토큰을 발급해 두고 원문을 돌려줍니다.
     *
     * @param  IdentityProvider  $idp  대상 IdP
     * @return string 토큰 원문
     */
    protected function issueScimToken(IdentityProvider $idp): string
    {
        $token = Str::random(64);
        $idp->forceFill(['scim_token_hash' => hash('sha256', $token), 'scim_token_issued_at' => now()])->save();

        return $token;
    }

    /**
     * SCIM 요청을 보냅니다.
     *
     * @param  string  $method  HTTP 메서드
     * @param  IdentityProvider  $idp  대상 IdP
     * @param  string  $path  `/Users` 등 (v2 뒤)
     * @param  string|null  $token  Bearer 토큰
     * @param  array<string, mixed>  $body  본문
     * @return TestResponse 응답
     */
    protected function scim(string $method, IdentityProvider $idp, string $path, ?string $token, array $body = []): TestResponse
    {
        $headers = ['Accept' => 'application/scim+json', 'Content-Type' => 'application/scim+json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $this->json($method, '/api/plugins/sirsoft-sso/scim/v2/'.$idp->key.$path, $body, $headers);
    }

    /**
     * 역할을 찾거나 만듭니다.
     *
     * @param  string  $identifier  역할 identifier
     * @param  bool  $admin  관리자 권한(admin 타입 권한)을 붙일지
     * @return Role 역할
     */
    protected function role(string $identifier, bool $admin = false): Role
    {
        $role = Role::query()->where('identifier', $identifier)->first()
            ?? Role::factory()->create(['identifier' => $identifier]);

        if ($admin && ! $role->permissions()->where('type', PermissionType::Admin->value)->exists()) {
            $permission = Permission::factory()->create(['type' => PermissionType::Admin->value]);
            $role->permissions()->attach($permission->id, ['granted_at' => now()]);
        }

        return $role;
    }

    /**
     * 역할 identifier 목록을 반환합니다.
     *
     * @param  User  $user  회원
     * @return array<int, string> identifier (정렬)
     */
    protected function roleIds(User $user): array
    {
        $ids = $user->roles()->pluck('identifier')->all();
        sort($ids);

        return $ids;
    }

    /**
     * 시험용 디렉터리 드라이버를 `ldap` 프로토콜로 꽂습니다.
     *
     * @return FakeDirectoryDriver 드라이버
     */
    protected function fakeDirectory(): FakeDirectoryDriver
    {
        $driver = new FakeDirectoryDriver('ldap');
        $this->app->instance(ProtocolRegistry::BUILTIN['ldap'], $driver);
        app(ProtocolRegistry::class)->flush();

        return $driver;
    }

    /**
     * 회원에게 토큰을 n 개 만들어 줍니다.
     *
     * @param  User  $user  회원
     * @param  int  $count  개수
     * @return array<int, int> 토큰 ID
     */
    protected function tokens(User $user, int $count = 1): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = (int) $user->createToken('p3-test-'.$i)->accessToken->getKey();
        }

        return $ids;
    }
}
