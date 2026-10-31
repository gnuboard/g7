<?php

namespace Tests\Feature\User;

use App\Contracts\Extension\UserScopedIdentityProviderInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Exceptions\Auth\PasswordAuthDisallowedException;
use App\Extension\Auth\PasswordAuthDecision;
use App\Extension\HookManager;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\Support\Identity\TestIdentityProvider;
use Tests\TestCase;

/**
 * 회원 응답이 비밀번호를 만들 수 있는지와 본인 재확인 수단을 미리 알린다 (7.2.0)
 *
 * 비밀번호 인증 정책(`core.auth.password_auth_decision`, 예: 회사 계정 로그인 강제)이 비밀번호 설정을 막는 회원에게 화면이 「비밀번호 처음 설정
 * → 본인 확인」을 안내하고 외부 화면 왕복까지 시킨 뒤 저장에서 403 을 냈다. 회원 응답에 그 사실이 없었기 때문이다. 이제 마이페이지 응답
 * (`can_set_password`·`password_set_blocked_message`)과 관리자 회원 상세(`password_status.can_set`·`set_blocked_message`)가 저장 때와
 * 같은 판정(본인은 `set_password`, 관리자가 남의 비밀번호를 정하면 `admin_set_password`)으로 미리 알린다.
 *
 * 메일·비밀번호를 쓸 수 없는 회원의 마이페이지 응답에는 코어가 민감 작업 재확인에 실제로 고를 수단(`reauth_provider`)을 싣는다 — 화면 안내가
 * 실제 수단과 같은 판정을 쓰게.
 */
class PasswordSetAvailabilityTest extends TestCase
{
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /** @var array<int, array<string, mixed>> 정책 필터가 받은 판정 대상 */
    private array $subjects = [];

    /**
     * 비밀번호 설정을 막는 정책 필터를 둡니다 (회사 계정 로그인 강제를 흉내 낸다).
     *
     * @param  int  $userId  막을 회원 ID
     * @return void 반환값 없음
     */
    private function denySetPasswordFor(int $userId): void
    {
        HookManager::addFilter('core.auth.password_auth_decision', function ($decision, array $subject) use ($userId) {
            $this->subjects[] = $subject;
            $user = $subject['user'] ?? null;

            if (in_array($subject['purpose'], ['set_password', 'admin_set_password'], true) && $user instanceof User && (int) $user->getKey() === $userId) {
                return PasswordAuthDecision::deny(PasswordAuthDecision::BASIS_USER, methodLabel: ['ko' => '모의 회사 계정', 'en' => 'Mock company account']);
            }

            return $decision;
        });
    }

    /**
     * 막힌 회원에게 보일 문구 — 저장 403 의 문구와 같은 것.
     *
     * @return string 문구
     */
    private function blockedMessage(): string
    {
        return PasswordAuthDisallowedException::localizedMessage(
            PasswordAuthDecision::deny(PasswordAuthDecision::BASIS_USER, methodLabel: ['ko' => '모의 회사 계정', 'en' => 'Mock company account'])
        );
    }

    /**
     * 그 회원으로 요청합니다 — 앞 요청이 인증한 회원이 남지 않게 인증 가드를 비운다(한 시험에서 여러 회원으로 요청).
     *
     * @param  User  $user  회원
     * @return static 요청 빌더
     */
    private function requestAs(User $user): static
    {
        return $this->asToken($this->issueToken($user));
    }

    /**
     * 그 토큰으로 요청합니다 — 앞 요청이 인증한 회원이 남지 않게 인증 가드를 비운다.
     *
     * @param  string  $token  토큰
     * @return static 요청 빌더
     */
    private function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /**
     * 비밀번호 없는 회원을 만듭니다.
     *
     * @param  string  $domain  이메일 도메인 (`sso.invalid` 면 메일을 받을 수 없는 회원)
     * @return User 회원
     */
    private function passwordless(string $domain = 'sso.invalid'): User
    {
        return $this->makeMember(['email' => Str::lower(Str::random(12)).'@'.$domain, 'password' => null]);
    }

    /**
     * 정책이 막는 회원의 마이페이지 응답은 비밀번호를 만들 수 없다고 알리고, 저장 403 과 같은 문구를 싣는다. 막지 않는 회원은 그대로.
     *
     * @scenario surface=profile, policy=deny|allow
     *
     * @effects profile_reports_password_unsettable, profile_reports_password_settable
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 마이페이지_응답이_비밀번호를_만들_수_없음을_알린다(): void
    {
        $blocked = $this->passwordless();
        $other = $this->passwordless('example.test');
        $this->denySetPasswordFor((int) $blocked->getKey());

        $this->requestAs($blocked)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.has_password', false)
            ->assertJsonPath('data.can_set_password', false)
            ->assertJsonPath('data.password_set_blocked_message', $this->blockedMessage());

        $this->requestAs($other)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.can_set_password', true)
            ->assertJsonPath('data.password_set_blocked_message', null);

        // 미리 알린 판정과 저장 판정이 같다 — 같은 회원이 저장하면 403 `auth.password_auth_disabled` 와 같은 문구
        $this->requestAs($blocked)
            ->putJson('/api/me/password', ['password' => 'N3w!Passw0rd#x', 'password_confirmation' => 'N3w!Passw0rd#x'])
            ->assertStatus(403)
            ->assertJsonPath('errors.code', 'auth.password_auth_disabled')
            ->assertJsonPath('message', $this->blockedMessage());

        $purposes = array_unique(array_map(fn (array $s) => $s['purpose'].'/'.$s['guard'], $this->subjects));
        $this->assertSame(['set_password/user'], array_values($purposes));
    }

    /**
     * 관리자 회원 상세의 비밀번호 상태도 같은 판정을 싣는다 — 남의 회원은 `admin_set_password`, 자기 계정은 `set_password`.
     *
     * @scenario surface=admin, target=other|self
     *
     * @effects admin_detail_reports_password_unsettable
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 관리자_회원_상세가_비밀번호를_만들_수_없음을_알린다(): void
    {
        $admin = $this->makeAdmin(['email' => 'g28.admin.'.Str::lower(Str::random(8)).'@sso.invalid', 'password' => null, 'is_super' => true]);
        $member = $this->passwordless();
        $this->denySetPasswordFor((int) $member->getKey());
        $this->denySetPasswordFor((int) $admin->getKey());
        $token = $this->issueToken($admin);

        $this->asToken($token)->getJson('/api/admin/users/'.$member->uuid)
            ->assertOk()
            ->assertJsonPath('data.password_status.has_password', false)
            ->assertJsonPath('data.password_status.can_set', false)
            ->assertJsonPath('data.password_status.set_blocked_message', $this->blockedMessage());
        $this->assertContains('admin_set_password', array_column($this->subjects, 'purpose'));

        $this->subjects = [];
        $this->asToken($token)->getJson('/api/admin/users/'.$admin->uuid)
            ->assertOk()
            ->assertJsonPath('data.password_status.can_set', false)
            ->assertJsonPath('data.password_status.set_blocked_message', $this->blockedMessage());
        $this->assertSame(['set_password'], array_values(array_unique(array_column($this->subjects, 'purpose'))));

        $free = $this->passwordless('example.test');
        $this->asToken($token)->getJson('/api/admin/users/'.$free->uuid)
            ->assertOk()
            ->assertJsonPath('data.password_status.can_set', true)
            ->assertJsonPath('data.password_status.set_blocked_message', null);
    }

    /**
     * 메일·비밀번호를 쓸 수 없는 회원의 마이페이지 응답에 코어가 민감 작업 재확인에 고를 수단을 싣는다. 메일을 받는 회원·쓸 수 있는 수단이
     * 없는 회원은 null.
     *
     * @scenario surface=profile, member=undeliverable_with_method|undeliverable_without_method|deliverable
     *
     * @effects profile_reports_reauth_provider
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 마이페이지_응답에_실제_재확인_수단을_싣는다(): void
    {
        $linked = $this->passwordless();
        $unlinked = $this->passwordless();
        $deliverable = $this->passwordless('example.test');
        app(IdentityVerificationManager::class)->register($this->scopedProvider('test:linked_reauth', fn (User $u) => (int) $u->getKey() === (int) $linked->getKey()));

        $this->requestAs($linked)->getJson('/api/me')->assertJsonPath('data.reauth_provider', 'test:linked_reauth');
        $this->requestAs($unlinked)->getJson('/api/me')->assertJsonPath('data.reauth_provider', null);
        $this->requestAs($deliverable)->getJson('/api/me')->assertJsonPath('data.reauth_provider', null);
    }

    /**
     * 회원별 판정 계약을 구현한 시험 수단 (연결 계정으로 다시 로그인하는 수단을 흉내 낸다).
     *
     * @param  string  $id  수단 식별자
     * @param  \Closure(User): bool  $eligible  회원 판정
     * @return TestIdentityProvider 시험 수단
     */
    private function scopedProvider(string $id, \Closure $eligible): TestIdentityProvider
    {
        return new class(app(IdentityVerificationLogRepositoryInterface::class), $id, $eligible) extends TestIdentityProvider implements UserScopedIdentityProviderInterface
        {
            /**
             * 이력 저장소와 회원 판정 값을 받아 시험 수단을 만듭니다.
             *
             * @param  IdentityVerificationLogRepositoryInterface  $logRepository  본인인증 이력 저장소
             * @param  string  $scopedId  수단 식별자
             * @param  \Closure(User): bool  $eligible  회원 판정
             */
            public function __construct(IdentityVerificationLogRepositoryInterface $logRepository, private string $scopedId, private \Closure $eligible)
            {
                parent::__construct($logRepository);
            }

            /**
             * 수단 식별자를 돌려줍니다.
             *
             * @return string 식별자
             */
            public function getId(): string
            {
                return $this->scopedId;
            }

            /**
             * 민감 작업 재확인만 지원합니다.
             *
             * @param  string  $purpose  목적
             * @return bool 지원하면 true
             */
            public function supportsPurpose(string $purpose): bool
            {
                return $purpose === 'sensitive_action';
            }

            /**
             * 이 회원에게 쓸 수 있는지 판정합니다.
             *
             * @param  User  $user  회원
             * @return bool 쓸 수 있으면 true
             */
            public function canChallengeUser(User $user): bool
            {
                return ($this->eligible)($user);
            }
        };
    }
}
