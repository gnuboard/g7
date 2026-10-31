<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Policy;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Plugins\Sirsoft\Sso\Listeners\Policy\PasswordResetBlockedNotifyListener;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Services\Policy\EnforcementPolicy;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * LDAP/AD IdP 로 로그인하는 회원의 강제 안내 — LDAP 은 버튼이 없고 같은 로그인 폼에 사내 아이디(또는 회사 이메일)와 회사 비밀번호를
 * 넣는 방식이라, 「○○(으)로 로그인」 버튼이나 「이 사이트는 회사 계정으로만」 대신 폼 로그인을 안내해야 한다.
 *
 * 리디렉션 IdP(OIDC, SAML)와 함께 연결된 회원은 버튼이 있는 쪽을 안내한다.
 *
 * @since 1.0.0
 */
class DirectoryLoginGuidanceTest extends PluginTestCase
{
    use PolicyFixtures;

    /**
     * 테스트 환경을 준비합니다 (거절 문구를 실제 번역으로 보려고 플러그인 문구를 올린다).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadPluginLang();
    }

    /**
     * 판정 서비스.
     *
     * @return EnforcementPolicy 서비스
     */
    private function policy(): EnforcementPolicy
    {
        return app(EnforcementPolicy::class);
    }

    /**
     * 활성 LDAP IdP 를 만듭니다.
     *
     * @param  string  $key  IdP 키
     * @param  array<string, mixed>  $config  LDAP 설정 (accept_username 등)
     * @param  array<string, mixed>  $attributes  더할 속성
     * @return IdentityProvider 만든 IdP
     */
    private function makeLdapIdp(string $key, array $config = [], array $attributes = []): IdentityProvider
    {
        return $this->makeIdp($key, array_merge([
            'protocol' => 'ldap',
            'label' => ['ko' => '사내 디렉터리', 'en' => 'Corporate directory'],
            'config' => array_merge(['host' => 'ldap.dir798.test', 'port' => 389, 'base_dn' => 'dc=dir798,dc=test'], $config),
            'secrets' => [],
            'owned_domains' => [],
        ], $attributes));
    }

    /**
     * LDAP 에만 연결된 회원이 G7 비밀번호로 로그인하면 「이 사이트는」 이 아니라 사내 아이디 로그인 안내를 받는지 확인한다.
     *
     * @scenario unit=linked, phase=user, linked_protocols=ldap, accept_username=true
     *
     * @effects directory_username_guidance, no_redirect_button
     *
     * @return void 반환값 없음
     */
    public function test_ldap_linked_member_login_rejection_guides_to_directory_form(): void
    {
        $ldap = $this->makeLdapIdp('dir', ['accept_username' => true]);
        $member = $this->makeMember();
        $this->linkAccount($member, $ldap);
        $this->setPolicy(['enforce_linked' => true]);

        $response = $this->postJson('/api/auth/login', ['email' => $member->email, 'password' => $this->password]);

        $response->assertStatus(403);
        $this->assertSame('auth.password_auth_disabled', $response->json('errors.code'));
        $this->assertNull($response->json('errors.redirect_url') ?? $response->json('data.redirect_url'));
        $this->assertSame(__('sirsoft-sso::policy.directory_login_username'), $response->json('message'));
        $this->assertNotSame(__(EnforcementPolicy::REASON_SITE), $response->json('message'));

        // 관리자 로그인 화면도 같은 안내 — 버튼 경로 없음
        $admin = $this->policy()->decide(['identifier' => $member->email, 'user' => $member, 'guard' => 'admin', 'purpose' => 'login']);
        $this->assertFalse($admin->allowed);
        $this->assertNull($admin->redirectUrl);
        $this->assertSame(EnforcementPolicy::REASON_DIRECTORY_USERNAME, $admin->reasonKey);
    }

    /**
     * 사내 아이디를 받지 않고 소유 도메인만 있는 LDAP IdP 는 회사 이메일 로그인을 안내하는지 확인한다.
     *
     * @scenario unit=linked, phase=user, linked_protocols=ldap, accept_username=false
     *
     * @effects directory_email_guidance
     *
     * @return void 반환값 없음
     */
    public function test_ldap_without_username_guides_to_company_email(): void
    {
        $ldap = $this->makeLdapIdp('dir', [], ['owned_domains' => ['dir798.test']]);
        $member = $this->makeMember();
        $this->linkAccount($member, $ldap);
        $this->setPolicy(['enforce_linked' => true]);

        $decision = $this->policy()->decide(['identifier' => $member->email, 'user' => $member, 'guard' => 'user', 'purpose' => 'login']);

        $this->assertFalse($decision->allowed);
        $this->assertNull($decision->redirectUrl);
        $this->assertSame(EnforcementPolicy::REASON_DIRECTORY_EMAIL, $decision->reasonKey);
    }

    /**
     * LDAP 과 OIDC 에 함께 연결된 회원은 로그인 거절과 비밀번호 찾기 안내 모두 버튼이 있는 OIDC 를 가리키는지 확인한다.
     *
     * @scenario unit=linked, phase=user, linked_protocols=ldap+oidc
     *
     * @effects redirect_idp_preferred, mail_guidance_redirect_idp
     *
     * @return void 반환값 없음
     */
    public function test_mixed_links_prefer_redirect_idp(): void
    {
        Mail::fake();
        Notification::fake();
        $ldap = $this->makeLdapIdp('dir', ['accept_username' => true]);
        $oidc = $this->makeIdp('corp');
        $member = $this->makeMember();
        $this->linkAccount($member, $ldap);
        $this->linkAccount($member, $oidc);
        $this->setPolicy(['enforce_linked' => true]);
        $fired = [];
        HookManager::addAction(PasswordResetBlockedNotifyListener::ACTION_REDIRECTED, function ($user, $guide) use (&$fired) {
            $fired[] = $guide?->key;
        });

        $decision = $this->policy()->decide(['identifier' => $member->email, 'user' => $member, 'guard' => 'user', 'purpose' => 'login']);
        $this->assertSame('/plugins/sirsoft-sso/login/corp?guard=user', $decision->redirectUrl);

        $this->postJson('/api/auth/forgot-password', ['email' => $member->email])->assertOk();

        $this->assertSame(['corp'], $fired);
    }

    /**
     * LDAP 에만 연결된 회원의 비밀번호 찾기 안내 메일 훅이 그 LDAP IdP 를 넘기는지 확인한다 (본문은 알림 정의 시험이 그린다).
     *
     * @scenario unit=linked, purpose=reset_request, linked_protocols=ldap
     *
     * @effects mail_guidance_ldap_idp
     *
     * @return void 반환값 없음
     */
    public function test_ldap_only_reset_request_passes_ldap_idp(): void
    {
        Mail::fake();
        Notification::fake();
        $ldap = $this->makeLdapIdp('dir', ['accept_username' => true]);
        $this->makeIdp('corp');
        $member = $this->makeMember();
        $this->linkAccount($member, $ldap);
        $this->setPolicy(['enforce_linked' => true]);
        $fired = [];
        HookManager::addAction(PasswordResetBlockedNotifyListener::ACTION_REDIRECTED, function ($user, $guide) use (&$fired) {
            $fired[] = $guide?->key;
        });

        $this->postJson('/api/auth/forgot-password', ['email' => $member->email])->assertOk();

        $this->assertSame(['dir'], $fired);
    }

    /**
     * LDAP 소유 도메인 강제의 비밀번호 찾기는 「사내 계정 서버에 연결할 수 없습니다」가 아니라 회사 이메일 로그인 안내인지 확인한다.
     *
     * @scenario unit=domain, phase=identifier, purpose=reset_request, domain=ldap_enforced
     *
     * @effects reset_422_directory_guidance
     *
     * @return void 반환값 없음
     */
    public function test_ldap_domain_reset_request_guides_to_company_password(): void
    {
        $this->makeLdapIdp('dir', [], ['owned_domains' => ['dir798.test'], 'enforce_owned_domains' => true]);
        $this->makeMember(['email' => 'lee@dir798.test']);

        $response = $this->postJson('/api/auth/forgot-password', ['email' => 'lee@dir798.test']);

        $response->assertStatus(422);
        $this->assertSame('auth.password_auth_disabled', $response->json('errors.code'));
        $this->assertSame(__('sirsoft-sso::policy.directory_login_email'), $response->json('message'));

        // 로그인 시도는 그대로 「디렉터리 서버에 닿지 못함」(검증자가 일치하지 않았다는 뜻)
        $login = $this->policy()->decide(['identifier' => 'lee@dir798.test', 'user' => null, 'guard' => 'user', 'purpose' => 'login']);
        $this->assertSame(EnforcementPolicy::REASON_LDAP_UNAVAILABLE, $login->reasonKey);
    }

    /**
     * 사이트 전체 강제인데 버튼으로 시작할 IdP 가 없고 사내 아이디를 받는 LDAP IdP 만 있으면 폼 로그인을 안내하는지 확인한다.
     *
     * @scenario unit=site, phase=identifier, enabled_protocols=ldap
     *
     * @effects site_directory_guidance
     *
     * @return void 반환값 없음
     */
    public function test_site_enforcement_with_only_ldap_guides_to_directory_form(): void
    {
        $this->makeLdapIdp('dir', ['accept_username' => true]);
        $this->setPolicy(['enforce_site' => true]);

        $decision = $this->policy()->decide(['identifier' => 'someone@example.test', 'user' => null, 'guard' => 'user', 'purpose' => 'login']);

        $this->assertFalse($decision->allowed);
        $this->assertNull($decision->redirectUrl);
        $this->assertSame(EnforcementPolicy::REASON_DIRECTORY_USERNAME, $decision->reasonKey);
    }

    /**
     * 사이트 전체 강제가 아닌데 안내할 수단이 없는 회원(역할 강제, IdP 없음)은 「이 사이트는」이 아니라 「이 계정은」 문구인지 확인한다.
     *
     * @scenario unit=role, phase=user, enabled_protocols=none
     *
     * @effects account_contact_guidance
     *
     * @return void 반환값 없음
     */
    public function test_role_enforcement_without_idp_is_not_site_message(): void
    {
        $role = $this->makeRole('sso-staff-798');
        $member = $this->makeMember();
        $member->roles()->attach($role->id);
        $this->setPolicy(['enforce_roles' => ['sso-staff-798']]);

        $decision = $this->policy()->decide(['identifier' => $member->email, 'user' => $member, 'guard' => 'admin', 'purpose' => 'login']);

        $this->assertFalse($decision->allowed);
        $this->assertSame(EnforcementPolicy::REASON_ACCOUNT, $decision->reasonKey);

        // 사이트 전체 강제의 안내할 IdP 없음은 「이 사이트는」 그대로
        $this->setPolicy(['enforce_roles' => [], 'enforce_site' => true]);
        $site = $this->policy()->decide(['identifier' => $member->email, 'user' => $member, 'guard' => 'user', 'purpose' => 'login']);
        $this->assertSame(EnforcementPolicy::REASON_SITE, $site->reasonKey);
    }

    /**
     * 역할 강제 회원이 LDAP 에 연결돼 있으면 연결되지 않은 OIDC 버튼보다 연결된 LDAP 의 폼 로그인을 안내하는지 확인한다.
     *
     * @scenario unit=role, phase=user, linked_protocols=ldap
     *
     * @effects linked_ldap_preferred_over_unlinked_redirect
     *
     * @return void 반환값 없음
     */
    public function test_role_enforced_ldap_member_gets_directory_guidance(): void
    {
        $ldap = $this->makeLdapIdp('dir', ['accept_username' => true]);
        $this->makeIdp('corp');
        $role = $this->makeRole('sso-staff-798');
        $member = $this->makeMember();
        $member->roles()->attach($role->id);
        $this->linkAccount($member, $ldap);
        $this->setPolicy(['enforce_roles' => ['sso-staff-798']]);

        $decision = $this->policy()->decide(['identifier' => $member->email, 'user' => $member, 'guard' => 'admin', 'purpose' => 'login']);

        $this->assertNull($decision->redirectUrl);
        $this->assertSame(EnforcementPolicy::REASON_DIRECTORY_USERNAME, $decision->reasonKey);
    }

    /**
     * 강제 대상 LDAP 회원의 비밀번호 만들기·재설정 완료 거절도 폼 로그인 안내인지 확인한다.
     *
     * @scenario purpose=set_password|reset_complete, linked_protocols=ldap
     *
     * @effects set_password_directory_guidance
     *
     * @return void 반환값 없음
     */
    public function test_set_password_for_ldap_member_uses_directory_guidance(): void
    {
        $ldap = $this->makeLdapIdp('dir', ['accept_username' => true]);
        $member = $this->makeMember(['password' => null]);
        $this->linkAccount($member, $ldap);
        $this->setPolicy(['enforce_linked' => true]);

        foreach (['set_password', 'reset_complete'] as $purpose) {
            $decision = $this->policy()->decide(['identifier' => $member->email, 'user' => $member, 'guard' => 'user', 'purpose' => $purpose]);
            $this->assertSame(EnforcementPolicy::REASON_DIRECTORY_USERNAME, $decision->reasonKey, $purpose);
        }
    }
}
