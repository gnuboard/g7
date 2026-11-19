<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Ldap;

use App\Extension\Auth\CredentialVerifierManager;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Services\Ldap\SsoCredentialVerifier;
use Plugins\Sirsoft\Sso\Services\LoginFlowService;
use Plugins\Sirsoft\Sso\Services\ProtocolRegistry;
use Plugins\Sirsoft\Sso\Tests\Fixtures\Ldap\LdapFixtures;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * ext-ldap 이 없는 서버(W9) — LDAP 드라이버만 「사용 불가」, 검증자는 빠지고 로그인은 비밀번호 경로, 연결 시험은 확장 없음을 알린다.
 *
 * @since 1.0.0
 */
class LdapAvailabilityTest extends PluginTestCase
{
    use LdapFixtures;

    /**
     * 확장이 없으면 드라이버와 검증자가 쓸 수 없다고 답하고, 코어는 아이디 칸을 열지 않으며 디렉터리에 닿지 않는다.
     *
     * @scenario ext_ldap=missing
     *
     * @effects driver_unavailable, verifier_unavailable, username_field_closed, directory_calls_zero
     *
     * @return void 반환값 없음
     */
    public function test_missing_extension_disables_ldap_only(): void
    {
        $this->fakeDirectory();
        $this->withoutLdapExtension();
        $idp = $this->ldapIdp();

        $this->assertFalse(app(ProtocolRegistry::class)->get('ldap')->isAvailable());
        $this->assertTrue(app(ProtocolRegistry::class)->get('oidc')->isAvailable(), '다른 프로토콜은 정상');
        $this->assertFalse(app(SsoCredentialVerifier::class)->isAvailable());
        $this->assertArrayNotHasKey(SsoCredentialVerifier::ID, app(CredentialVerifierManager::class)->available());
        $this->assertFalse(app(CredentialVerifierManager::class)->acceptsUsernames('user'));

        $result = $idp->driver()->testConnection($idp);
        $this->assertFalse($result->ok);
        $this->assertSame('extension', $result->checks[0]['key']);

        try {
            $idp->driver()->authenticate($idp, 'ldap.lee', self::FAKE_USER_PASSWORD);
            $this->fail('확장 없이 인증했다');
        } catch (SsoException $e) {
            $this->assertSame('driver_unavailable', $e->errorCode);
        }

        $this->assertSame([], $this->directory->calls);
    }

    /**
     * 확장이 없으면 LDAP 도메인 회원의 G7 비밀번호 로그인은 기존 비밀번호 경로로 간다(강제 판정은 정책 영역 몫).
     *
     * @scenario ext_ldap=missing, member=has_g7_password
     *
     * @effects password_path_200
     *
     * @return void 반환값 없음
     */
    public function test_login_falls_back_to_password_path(): void
    {
        $this->fakeDirectory();
        $this->withoutLdapExtension();
        $this->ldapIdp();
        User::factory()->create(['email' => 'local@corp798.test', 'password' => Hash::make('Local-pw-1')]);

        $this->postJson('/api/auth/login', ['email' => 'local@corp798.test', 'password' => 'Local-pw-1'])->assertStatus(200);
        $this->assertSame([], $this->directory->calls);
    }

    /**
     * LDAP IdP 는 로그인 화면 버튼 목록에 나오지 않는다(로그인 칸으로 들어온다).
     *
     * @scenario public_idps=true, protocol=ldap
     *
     * @effects not_listed
     *
     * @return void 반환값 없음
     */
    public function test_ldap_idp_is_not_a_login_button(): void
    {
        $this->fakeDirectory();
        $this->ldapIdp();

        $this->assertSame([], array_column(app(LoginFlowService::class)->publicIdps('user'), 'key'));
    }
}
