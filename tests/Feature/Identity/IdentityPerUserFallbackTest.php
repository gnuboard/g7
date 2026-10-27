<?php

namespace Tests\Feature\Identity;

use App\Contracts\Extension\UserScopedIdentityProviderInterface;
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
 * 목적별 본인인증 수단의 회원별 폴백 (#798 K17 · W17)
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
        $this->registerTestProvider();
        $manager = app(IdentityVerificationManager::class);

        $normal = $this->makeMember(['email' => 'idv.'.Str::lower(Str::random(6)).'@example.test']);
        $this->assertSame('g7:core.mail', $manager->resolveForPurpose('sensitive_action', null, $normal)->getId());
        $this->assertSame(TestIdentityProvider::ID, $manager->resolveForPurpose('sensitive_action', null, $this->undeliverable())->getId());

        $challenge = app(IdentityVerificationService::class)->start('sensitive_action', $this->undeliverable());
        $this->assertSame(TestIdentityProvider::ID, $challenge->providerId);
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
        $provider = new class(app(TestIdentityProvider::class), $captured) extends TestIdentityProvider
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
