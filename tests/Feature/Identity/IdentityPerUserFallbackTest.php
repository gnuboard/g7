<?php

namespace Tests\Feature\Identity;

use App\Contracts\Extension\UserScopedIdentityProviderInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Extension\IdentityVerification\DTO\VerificationChallenge;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Extension\IdentityVerification\Providers\MailIdentityProvider;
use App\Models\User;
use App\Services\IdentityVerificationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\Support\Identity\TestIdentityProvider;
use Tests\TestCase;

/**
 * 목적별 본인인증 수단의 회원별 폴백
 *
 * 받을 메일이 없는 회원(대체 주소 `…@….invalid`)에게는 메일 인증번호를 보낼 수 없다. 대상 회원을 아는 경로에서 해석된 수단이 그 회원에게
 * 쓸 수 없으면(`UserScopedIdentityProviderInterface::canChallengeUser()` 거짓) 같은 목적을 지원하는 다음 수단으로 넘어간다.
 */
class IdentityPerUserFallbackTest extends TestCase
{
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /**
     * 메일을 막습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    /**
     * 대체 주소 회원을 만듭니다.
     *
     * @return User 회원
     */
    private function undeliverable(): User
    {
        return $this->makeMember(['email' => Str::lower(Str::random(12)).'@sso.invalid']);
    }

    /**
     * 시험 수단을 등록합니다.
     *
     * @return void 반환값 없음
     */
    private function registerTestProvider(): void
    {
        app(IdentityVerificationManager::class)->register(app(TestIdentityProvider::class));
    }

    /**
     * 회원별 판정 계약을 구현한 시험 수단을 만듭니다 — 연결 계정으로 다시 로그인하는 수단(민감 작업 재확인만, 외부 화면 이동)을 흉내 낸다.
     *
     * @param  string  $id  수단 식별자
     * @param  \Closure(User): bool  $eligible  이 회원에게 쓸 수 있는지 답하는 판정
     * @return TestIdentityProvider&UserScopedIdentityProviderInterface 시험 수단
     */
    private function scopedProvider(string $id, \Closure $eligible): TestIdentityProvider
    {
        return new class(app(IdentityVerificationLogRepositoryInterface::class), $id, $eligible) extends TestIdentityProvider implements UserScopedIdentityProviderInterface
        {
            /**
             * 이력 저장소·식별자·회원 판정을 받아 시험 수단을 만듭니다.
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
             * 외부 화면으로 이동하는 수단입니다.
             *
             * @return string 렌더 힌트
             */
            public function getRenderHint(): string
            {
                return 'external_redirect';
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

            /**
             * 기본 시험 요청을 만들고 이 수단의 식별자·외부 이동 주소로 돌려줍니다.
             *
             * @param  User|array  $target  인증 대상
             * @param  array  $context  수단 맥락
             * @return VerificationChallenge 요청
             */
            public function requestChallenge(User|array $target, array $context = []): VerificationChallenge
            {
                $base = parent::requestChallenge($target, $context);

                return new VerificationChallenge(
                    id: $base->id,
                    providerId: $this->scopedId,
                    purpose: $base->purpose,
                    channel: 'linked',
                    targetHash: $base->targetHash,
                    expiresAt: $base->expiresAt,
                    renderHint: 'external_redirect',
                    redirectUrl: '/reauth/'.$this->scopedId,
                    publicPayload: ['delivery' => 'none'],
                );
            }
        };
    }

    /**
     * 메일 수단은 회원 단위 판정 계약을 구현한다 (받을 메일이 있으면 참).
     *
     * @scenario provider=mail
     *
     * @effects mail_provider_user_scoped
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 메일_수단의_회원_판정(): void
    {
        $mail = app(MailIdentityProvider::class);

        $this->assertInstanceOf(UserScopedIdentityProviderInterface::class, $mail);
        $this->assertTrue($mail->canChallengeUser($this->makeMember(['email' => 'idv.'.Str::lower(Str::random(6)).'@example.test'])));
        $this->assertFalse($mail->canChallengeUser($this->undeliverable()));
    }

    /**
     * 메일을 받을 수 있는 회원은 메일, 대체 주소 회원은 다음 수단으로 간다.
     *
     * @scenario user=deliverable|undeliverable
     *
     * @effects deliverable_uses_mail, undeliverable_falls_back
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 대체_주소_회원은_다음_수단으로(): void
    {
        app(IdentityVerificationManager::class)->register($this->scopedProvider('test:linked_reauth', fn (User $user): bool => true));
        $manager = app(IdentityVerificationManager::class);

        $normal = $this->makeMember(['email' => 'idv.'.Str::lower(Str::random(6)).'@example.test']);
        $this->assertSame('g7:core.mail', $manager->resolveForPurpose('sensitive_action', null, $normal)->getId());
        $this->assertSame('test:linked_reauth', $manager->resolveForPurpose('sensitive_action', null, $this->undeliverable())->getId());

        $challenge = app(IdentityVerificationService::class)->start('sensitive_action', $this->undeliverable());
        $this->assertSame('test:linked_reauth', $challenge->providerId);
    }

    /**
     * 회원별 판정이 없는 수단(휴대폰 본인확인처럼 누구의 신원이든 확인해 주는 수단)은 등록 순서가 앞서도 폴백으로 고르지 않는다 —
     * 메일을 받을 수 없는 회원은 그 회원에게 쓸 수 있다고 답한 수단(연결 계정으로 다시 로그인 등)으로 간다. 화면이 보내는 재확인 요청
     * (`POST /api/identity/challenges`, 수단 비움)의 응답도 그 수단이다.
     *
     * @scenario providers=unscoped_first_then_scoped, user=undeliverable, entry=resolve|api
     *
     * @effects unscoped_provider_not_auto_fallback, scoped_provider_chosen_for_undeliverable
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 회원_판정이_없는_수단은_폴백으로_고르지_않는다(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $manager = app(IdentityVerificationManager::class);
        $manager->register(app(TestIdentityProvider::class));
        $manager->register($this->scopedProvider('test:linked_reauth', fn (User $user): bool => true));

        $member = $this->undeliverable();
        $this->assertSame('test:linked_reauth', $manager->resolveForPurpose('sensitive_action', null, $member)->getId());

        $this->actingAs($member)->postJson('/api/identity/challenges', ['purpose' => 'sensitive_action', 'return_path' => '/mypage/profile/edit'])
            ->assertCreated()
            ->assertJsonPath('data.provider_id', 'test:linked_reauth')
            ->assertJsonPath('data.render_hint', 'external_redirect')
            ->assertJsonPath('data.redirect_url', '/reauth/test:linked_reauth');
    }

    /**
     * 회원별 판정 수단이 여럿이면 그 회원에게 참을 답하는 첫 수단(등록 순서)을 고른다 — 이 회원에게 쓸 수 없다고 답한 수단은 건너뛴다.
     * 쓸 수 있는 수단이 하나도 없으면 회원별 판정이 없는 수단이 등록돼 있어도 해석된 수단(메일)을 그대로 돌려준다(기존 오류 경로).
     *
     * @scenario providers=scoped_ineligible_then_eligible|unscoped_only, user=undeliverable
     *
     * @effects ineligible_scoped_skipped, no_eligible_scoped_keeps_resolved
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 쓸_수_있다고_답한_수단만_고른다(): void
    {
        $manager = app(IdentityVerificationManager::class);
        $manager->register(app(TestIdentityProvider::class));

        $member = $this->undeliverable();
        $this->assertSame('g7:core.mail', $manager->resolveForPurpose('sensitive_action', null, $member)->getId());

        $manager->register($this->scopedProvider('test:social_reauth', fn (User $user): bool => false));
        $manager->register($this->scopedProvider('test:company_reauth', fn (User $user): bool => (int) $user->getKey() === (int) $member->getKey()));

        $this->assertSame('test:company_reauth', $manager->resolveForPurpose('sensitive_action', null, $member)->getId());
        $this->assertSame('g7:core.mail', $manager->resolveForPurpose('sensitive_action', null, $this->undeliverable())->getId());
    }

    /**
     * 운영자가 목적에 회원별 판정이 없는 수단을 지정했으면(목적별 수단·정책·요청 지정) 메일을 받을 수 없는 회원에게도 그 수단을 쓴다 —
     * 지정한 수단은 회원별 폴백 대상이 아니다.
     *
     * @scenario designation=purpose_providers|explicit_provider_id, user=undeliverable
     *
     * @effects designated_unscoped_provider_kept
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 운영자가_지정한_본인확인_수단은_그대로_쓴다(): void
    {
        $manager = app(IdentityVerificationManager::class);
        $manager->register(app(TestIdentityProvider::class));
        $manager->register($this->scopedProvider('test:linked_reauth', fn (User $user): bool => true));
        $member = $this->undeliverable();

        $this->assertSame(TestIdentityProvider::ID, $manager->resolveForPurpose('sensitive_action', TestIdentityProvider::ID, $member)->getId());

        config(['settings.identity.purpose_providers.sensitive_action' => TestIdentityProvider::ID]);
        $this->assertSame(TestIdentityProvider::ID, $manager->resolveForPurpose('sensitive_action', null, $member)->getId());
    }

    /**
     * 다른 수단이 없으면 기존처럼 메일을 돌려준다 (기존 오류 경로 유지).
     *
     * @scenario providers=mail_only
     *
     * @effects no_alternative_keeps_existing
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 다른_수단이_없으면_기존과_같다(): void
    {
        $this->assertSame('g7:core.mail', app(IdentityVerificationManager::class)->resolveForPurpose('sensitive_action', null, $this->undeliverable())->getId());
    }

    /**
     * 회원을 모르는 호출은 지금과 같다.
     *
     * @scenario user=unspecified
     *
     * @effects no_user_keeps_existing
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 회원_미지정은_기존과_같다(): void
    {
        $this->registerTestProvider();

        $this->assertSame('g7:core.mail', app(IdentityVerificationManager::class)->resolveForPurpose('sensitive_action')->getId());
    }

    /**
     * 화면이 보낸 복귀 경로(`return_path`)가 수단의 요청 맥락으로 간다 — 외부 화면을 거치는 수단(회사 계정 다시 로그인 등)이 확인을
     * 마친 뒤 원래 화면으로 돌려보내는 데 쓴다. 다른 출처 주소는 버린다.
     *
     * @scenario user=undeliverable, return_path=same_origin|external|absent
     *
     * @effects challenge_return_path_reaches_provider, challenge_external_return_path_dropped
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 복귀_경로가_수단_맥락으로_간다(): void
    {
        $captured = new \ArrayObject;
        $provider = new class(app(TestIdentityProvider::class), $captured) extends TestIdentityProvider implements UserScopedIdentityProviderInterface
        {
            /**
             * 기본 시험 제공자의 상태를 옮겨 받고, 받은 복귀 경로를 담을 그릇을 둡니다.
             *
             * @param  TestIdentityProvider  $base  상태를 옮겨 올 기본 시험 제공자
             * @param  \ArrayObject  $captured  수단 맥락의 return_path 를 담는 그릇
             */
            public function __construct(TestIdentityProvider $base, private \ArrayObject $captured)
            {
                foreach (get_object_vars($base) as $name => $value) {
                    $this->{$name} = $value;
                }
            }

            /**
             * 메일을 받을 수 없는 회원도 쓸 수 있는 수단입니다(회원별 폴백 대상).
             *
             * @param  User  $user  회원
             * @return bool 항상 true
             */
            public function canChallengeUser(User $user): bool
            {
                return true;
            }

            /**
             * 수단 맥락의 return_path 를 기록한 뒤 기본 동작으로 challenge 를 만듭니다.
             *
             * @param  User|array  $target  인증 대상
             * @param  array  $context  수단 맥락
             * @return VerificationChallenge 만든 challenge
             */
            public function requestChallenge(User|array $target, array $context = []): VerificationChallenge
            {
                $this->captured->append($context['return_path'] ?? null);

                return parent::requestChallenge($target, $context);
            }
        };
        app(IdentityVerificationManager::class)->register($provider);
        $this->seed(RolePermissionSeeder::class);
        $member = $this->undeliverable();

        foreach (['/admin/settings?tab=security&reauth=app_key', 'https://evil.example/steal', null] as $path) {
            $body = ['purpose' => 'sensitive_action'] + ($path === null ? [] : ['return_path' => $path]);
            $this->actingAs($member)->postJson('/api/identity/challenges', $body)->assertCreated();
        }

        $this->assertSame(['/admin/settings?tab=security&reauth=app_key', null, null], $captured->getArrayCopy());
    }
}
