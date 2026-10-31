<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Ldap;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Extension\Auth\CredentialVerifierManager;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Models\SsoLoginAttempt;
use Plugins\Sirsoft\Sso\Services\Ldap\SsoCredentialVerifier;
use Plugins\Sirsoft\Sso\Services\LoginFlowService;
use Plugins\Sirsoft\Sso\Services\StateStore;
use Plugins\Sirsoft\Sso\Tests\Fixtures\Ldap\LdapFixtures;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 코어 외부 자격 증명 검증자 연동(K4) — 코어 로그인 API 로 들어온 아이디·비밀번호를 LDAP 으로 확인하고 회원을 정한다.
 *
 * 이메일·사내 아이디, 자동 생성, 동의 대기(409 + 묶음 쿠키), 실패 횟수 규칙(확정 회원만, 장애 미포함), 잠긴 회원 바인드 금지, 관리자
 * 경로, LDAP 도메인이 아닌 이메일의 기존 경로를 확인한다.
 *
 * @since 1.0.0
 */
class SsoCredentialVerifierTest extends PluginTestCase
{
    use LdapFixtures;

    /**
     * 시험 준비 — 가짜 디렉터리.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeDirectory();
    }

    /**
     * 코어 로그인 API 를 부릅니다.
     *
     * @param  string  $identifier  로그인 칸 입력
     * @param  string  $password  비밀번호
     * @param  string  $guard  user 또는 admin
     * @return TestResponse 응답
     */
    private function login(string $identifier, string $password, string $guard = 'user')
    {
        return $this->withCredentials()->postJson($guard === 'admin' ? '/api/auth/admin/login' : '/api/auth/login', ['email' => $identifier, 'password' => $password]);
    }

    /**
     * 기존 ldap.lee 연결 회원을 만듭니다(비밀번호 없는 SSO 회원).
     *
     * @param  IdentityProvider  $idp  대상 IdP
     * @return User 회원
     */
    private function linkedLee($idp): User
    {
        $user = User::factory()->create(['email' => 'lee@corp798.test', 'password' => null]);
        $this->link($user, $idp, self::LEE_UUID);

        return $user;
    }

    /**
     * 처음 온 직원이 사내 아이디로 로그인하면 자동 생성되고 토큰을 받는다. 연결 키는 entryUUID 다.
     *
     * @scenario identifier=username, account=new, provisioning=jit, consent=skip
     *
     * @effects login_200_token, user_created_without_password, account_subject_entry_uuid, attempt_handoff
     *
     * @return void 반환값 없음
     */
    public function test_new_employee_logs_in_with_username(): void
    {
        $idp = $this->ldapIdp();

        $response = $this->login('ldap.lee', self::FAKE_USER_PASSWORD);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data.token'));
        $account = SsoAccount::query()->where('idp_id', $idp->id)->where('subject', self::LEE_UUID)->first();
        $this->assertNotNull($account);
        $this->assertSame('jit', $account->linked_via);
        $user = User::query()->find($account->user_id);
        $this->assertSame('lee@corp798.test', $user->email);
        $this->assertFalse($user->hasPassword());
        $this->assertDatabaseHas('sso_login_attempts', ['idp_id' => $idp->id, 'user_id' => $user->id, 'outcome' => SsoLoginAttempt::OUTCOME_HANDOFF]);
    }

    /**
     * 연결된 회원은 이메일로 로그인하고, 회원 결정 훅과 SSO 로그인 훅을 내며 마지막 로그인 시각이 남는다. 로그인 수단은 sso:{키}.
     *
     * @scenario identifier=email, account=linked
     *
     * @effects login_200, after_resolve_fired, last_login_at_set, login_method_sso
     *
     * @return void 반환값 없음
     */
    public function test_linked_member_logs_in_with_email_and_fires_hooks(): void
    {
        $idp = $this->ldapIdp();
        $user = $this->linkedLee($idp);
        $fired = [];
        HookManager::addAction(LoginFlowService::ACTION_AFTER_RESOLVE, function ($u, $a, $i, $outcome) use (&$fired) {
            $fired[] = 'resolve:'.$outcome;
        });
        HookManager::addAction(LoginFlowService::ACTION_AFTER_SSO_LOGIN, function () use (&$fired) {
            $fired[] = 'sso_login';
        });

        $response = $this->login('LEE@corp798.test', self::FAKE_USER_PASSWORD);

        $response->assertStatus(200);
        $this->assertSame($user->uuid, $response->json('data.user.uuid'));
        $this->assertSame(['resolve:login', 'sso_login'], $fired);
        $this->assertNotNull(SsoAccount::query()->where('user_id', $user->id)->value('last_login_at'));
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id, 'login_method' => 'sso:ldap_open']);
    }

    /**
     * 동의가 필요한 IdP 의 처음 온 직원은 409 와 동의 대기 주소를 받고, 응답에 브라우저 묶음 쿠키가 실린다.
     *
     * @scenario account=new, consent=required, browser=first_visit
     *
     * @effects login_409_external_step, redirect_sso_pending, bind_cookie_on_response, member_not_created_yet
     *
     * @return void 반환값 없음
     */
    public function test_consent_step_returns_409_with_bind_cookie(): void
    {
        $this->ldapIdp(['require_consent' => true]);
        $before = User::query()->count();

        $response = $this->login('ldap.lee', self::FAKE_USER_PASSWORD);

        $response->assertStatus(409);
        $this->assertSame('auth.external_step_required', $response->json('errors.code'));
        $this->assertStringStartsWith('/login?sso_pending=', (string) $response->json('data.redirect_url'));
        $cookie = $response->getCookie(StateStore::BIND_COOKIE, false);
        $this->assertNotNull($cookie, '동의 대기는 이 브라우저에 묶이므로 응답에 묶음 쿠키가 실려야 한다');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $cookie->getValue());
        $this->assertSame($before, User::query()->count());
    }

    /**
     * 연결된 회원의 틀린 디렉터리 비밀번호는 G7 실패 횟수에 센다(비밀번호 없는 회원이어도). 연결 전 사람은 세지 않는다.
     *
     * @scenario password=wrong, account=linked|unlinked
     *
     * @effects login_401, failed_attempts_counted_for_linked_only
     *
     * @return void 반환값 없음
     */
    public function test_wrong_password_counts_only_for_linked_member(): void
    {
        $idp = $this->ldapIdp();
        $user = $this->linkedLee($idp);

        $this->login('ldap.lee', 'wrong-password')->assertStatus(401);
        $this->assertSame(1, (int) $user->fresh()->failed_login_attempts);

        $parkFails = User::query()->where('email', 'park@corp798.test')->count();
        $this->login('ad.park', 'wrong-password')->assertStatus(401);
        $this->assertSame($parkFails, User::query()->where('email', 'park@corp798.test')->count());
        $this->assertDatabaseHas('sso_login_attempts', ['idp_id' => $idp->id, 'reason_code' => 'ldap_invalid_credentials']);
    }

    /**
     * 디렉터리 장애는 401 이지만 실패 횟수에 세지 않고 IdP 마지막 오류에 남는다.
     *
     * @scenario server=down, account=linked
     *
     * @effects login_401, failed_attempts_zero, idp_last_error_ldap_unavailable
     *
     * @return void 반환값 없음
     */
    public function test_directory_outage_is_not_counted(): void
    {
        $idp = $this->ldapIdp();
        $user = $this->linkedLee($idp);
        $this->directory->failOn = 'bind';

        $this->login('lee@corp798.test', self::FAKE_USER_PASSWORD)->assertStatus(401);

        $this->assertSame(0, (int) $user->fresh()->failed_login_attempts);
        $this->assertSame('ldap_unavailable', $idp->fresh()->last_error_code);
    }

    /**
     * 잠긴 연결 회원은 디렉터리에 비밀번호를 보내지 않고 423 으로 끝난다(디렉터리 계정 잠금 보호).
     *
     * @scenario account=linked_locked
     *
     * @effects login_423, user_bind_zero
     *
     * @return void 반환값 없음
     */
    public function test_locked_member_is_not_bound(): void
    {
        $idp = $this->ldapIdp();
        $user = $this->linkedLee($idp);
        $user->forceFill(['locked_until' => now()->addMinutes(10), 'failed_login_attempts' => 5])->save();

        $this->login('ldap.lee', self::FAKE_USER_PASSWORD)->assertStatus(423);

        $userBinds = array_filter($this->directory->calls, static fn ($c) => $c[0] === 'bind' && $c[1]['dn'] === 'uid=ldap.lee,ou=people,dc=corp798,dc=test');
        $this->assertSame([], $userBinds);
    }

    /**
     * 관리자 경로 — 관리자 회원은 로그인, 일반 회원은 403(관리자 아님).
     *
     * @scenario guard=admin, account=admin|member
     *
     * @effects admin_200, member_403
     *
     * @return void 반환값 없음
     */
    public function test_admin_guard(): void
    {
        $idp = $this->ldapIdp();
        $member = $this->linkedLee($idp);

        $this->login('ldap.lee', self::FAKE_USER_PASSWORD, 'admin')->assertStatus(403);

        $admin = $this->adminMember('park@corp798.test');
        $ad = $this->adIdp(['owned_domains' => ['corp798.test'], 'sort_order' => 1]);
        $this->link($admin, $ad, self::PARK_GUID);
        $idp->forceFill(['owned_domains' => ['other.test']])->save();

        $this->login('park@corp798.test', self::FAKE_USER_PASSWORD, 'admin')->assertStatus(200);
        $this->assertNotNull($member->fresh());
    }

    /**
     * 관리자 권한(admin 타입 권한을 가진 역할)이 있는 비밀번호 없는 회원을 만듭니다 — 시험 DB 에는 권한 시드가 없다.
     *
     * @param  string  $email  이메일
     * @return User 관리자 회원
     */
    private function adminMember(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'password' => null, 'status' => 'active']);
        $permission = Permission::firstOrCreate(['identifier' => 'core.users.update'], [
            'name' => json_encode(['ko' => '사용자 수정', 'en' => 'Update Users']),
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => PermissionType::Admin,
        ]);
        $role = Role::firstOrCreate(['identifier' => 'admin'], [
            'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);
        if (! $role->permissions()->where('permissions.id', $permission->id)->exists()) {
            $role->permissions()->attach($permission->id);
        }
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * LDAP 도메인이 아닌 이메일은 디렉터리를 거치지 않고 기존 비밀번호 로그인으로 간다.
     *
     * @scenario identifier=email(other_domain)
     *
     * @effects password_path_200, directory_calls_zero
     *
     * @return void 반환값 없음
     */
    public function test_non_ldap_domain_uses_password_path(): void
    {
        $this->ldapIdp();
        User::factory()->create(['email' => 'outside@example.test', 'password' => Hash::make('Outside-pw-1')]);

        $this->login('outside@example.test', 'Outside-pw-1')->assertStatus(200);

        $this->assertSame([], $this->directory->calls);
    }

    /**
     * 사내 아이디 허용 IdP 가 있을 때만 로그인 칸이 아이디를 받는다(그 밖에는 이메일 형식 검증).
     *
     * @scenario accept_username=on|off
     *
     * @effects accepts_usernames_flag, validation_422_when_off
     *
     * @return void 반환값 없음
     */
    public function test_username_acceptance_follows_setting(): void
    {
        $idp = $this->ldapIdp(['config' => ['accept_username' => false]]);
        $this->assertFalse(app(CredentialVerifierManager::class)->acceptsUsernames('user'));
        $this->login('ldap.lee', self::FAKE_USER_PASSWORD)->assertStatus(422);

        $idp->forceFill(['config' => array_merge($idp->config, ['accept_username' => true])])->save();
        $this->assertTrue(app(CredentialVerifierManager::class)->acceptsUsernames('user'));
        $this->assertArrayHasKey(SsoCredentialVerifier::ID, app(CredentialVerifierManager::class)->available());
    }
}
