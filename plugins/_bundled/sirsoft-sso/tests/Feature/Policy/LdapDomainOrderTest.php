<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Policy;

use App\Contracts\Extension\ExternalCredentialVerifierInterface;
use App\Extension\Auth\ExternalCredentialMatch;
use App\Extension\Auth\ExternalCredentialResult;
use App\Extension\HookManager;
use App\Models\User;
use Plugins\Sirsoft\Sso\Services\Policy\EnforcementPolicy;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;
use Plugins\Sirsoft\Sso\Tests\Support\PolicyFixtures;

/**
 * LDAP 소유 도메인 강제의 판정 순서(W27, 함정 37) — 디렉터리 검증자가 일치하면 정책을 묻지 않고 디렉터리로 가고, 일치하지 않으면
 * (서버 확장 없음, IdP 꺼짐) 경로 없는 거절 + 「사내 계정 서버에 연결할 수 없습니다」.
 *
 * @since 1.0.0
 */
class LdapDomainOrderTest extends PluginTestCase
{
    use PolicyFixtures;

    /**
     * 검증자가 일치하지 않으면 경로 없는 거절인지 확인한다.
     *
     * @scenario verifier=absent, domain=ldap_enforced
     *
     * @effects deny_without_redirect, reason_ldap_unavailable
     *
     * @return void 반환값 없음
     */
    public function test_unmatched_ldap_domain_is_denied_without_redirect(): void
    {
        $this->makeIdp('dir', ['protocol' => 'ldap', 'owned_domains' => ['dir798.test'], 'enforce_owned_domains' => true]);

        $decision = app(EnforcementPolicy::class)->decide(['identifier' => 'lee@dir798.test', 'user' => null, 'guard' => 'user', 'purpose' => 'login']);

        $this->assertFalse($decision->allowed);
        $this->assertNull($decision->redirectUrl);
        $this->assertSame(EnforcementPolicy::REASON_LDAP_UNAVAILABLE, $decision->reasonKey);

        // 서버에 디렉터리 검증자가 없는 경우(ext-ldap 없음) — 등록된 검증자를 모두 걷어낸다.
        HookManager::addFilter('core.auth.registered_credential_verifiers', fn () => [], 999);

        $response = $this->postJson('/api/auth/login', ['email' => 'lee@dir798.test', 'password' => 'x']);
        $response->assertStatus(403);
        $this->assertSame('auth.password_auth_disabled', $response->json('errors.code'));
    }

    /**
     * 검증자가 일치하면 디렉터리 검증으로 로그인하고 정책 거절을 타지 않는지 확인한다.
     *
     * @scenario verifier=matches, domain=ldap_enforced
     *
     * @effects verifier_called, logged_in
     *
     * @return void 반환값 없음
     */
    public function test_matching_verifier_runs_before_policy(): void
    {
        $this->makeIdp('dir', ['protocol' => 'ldap', 'owned_domains' => ['dir798.test'], 'enforce_owned_domains' => true]);
        $member = $this->makeMember(['email' => 'park@dir798.test', 'password' => null]);
        $verifier = new class($member) implements ExternalCredentialVerifierInterface
        {
            /** @var int 검증 호출 수 */
            public int $verified = 0;

            /**
             * 일치시킬 회원을 받습니다.
             *
             * @param  User  $user  일치시킬 회원
             */
            public function __construct(private readonly User $user) {}

            /**
             * 검증자 ID.
             *
             * @return string 검증자 식별자
             */
            public function getId(): string
            {
                return 'test-dir';
            }

            /**
             * 사용 가능 여부.
             *
             * @return bool 항상 true
             */
            public function isAvailable(): bool
            {
                return true;
            }

            /**
             * 사내 아이디 허용 여부.
             *
             * @param  string  $guard  경로
             * @return bool 항상 false
             */
            public function acceptsUsernames(string $guard): bool
            {
                return false;
            }

            /**
             * 식별자를 맡는지.
             *
             * @param  string  $identifier  식별자
             * @param  string  $guard  경로
             * @return ExternalCredentialMatch|null 일치
             */
            public function match(string $identifier, string $guard): ?ExternalCredentialMatch
            {
                return str_ends_with($identifier, '@dir798.test') ? new ExternalCredentialMatch($this->user, $identifier) : null;
            }

            /**
             * 비밀번호를 확인합니다.
             *
             * @param  ExternalCredentialMatch  $match  일치
             * @param  string  $password  비밀번호
             * @param  string  $guard  경로
             * @return ExternalCredentialResult 결과
             */
            public function verify(ExternalCredentialMatch $match, string $password, string $guard): ExternalCredentialResult
            {
                $this->verified++;

                return ExternalCredentialResult::authenticated($this->user, ['login_method' => 'sso:dir', 'skip_two_factor' => true]);
            }
        };
        HookManager::addFilter('core.auth.registered_credential_verifiers', function (array $verifiers) use ($verifier) {
            $verifiers[$verifier->getId()] = $verifier;

            return $verifiers;
        });

        $response = $this->postJson('/api/auth/login', ['email' => 'park@dir798.test', 'password' => 'directory-secret']);

        $response->assertOk();
        $this->assertSame(1, $verifier->verified);
        $this->assertNotEmpty($response->json('data.token'));
    }
}
