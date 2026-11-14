<?php

namespace Plugins\Sirsoft\Totp\Tests\Feature;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Totp\Identity\TotpIdentityProvider;
use Plugins\Sirsoft\Totp\Models\TotpCredential;
use Plugins\Sirsoft\Totp\Repositories\Contracts\TotpCredentialRepositoryInterface;
use Plugins\Sirsoft\Totp\Repositories\TotpCredentialRepository;
use Plugins\Sirsoft\Totp\Services\TotpEnrollmentService;
use Plugins\Sirsoft\Totp\Support\Base32;
use Plugins\Sirsoft\Totp\Support\Totp;
use Plugins\Sirsoft\Totp\Tests\PluginTestCase;
use Tests\Support\Identity\StaleReadIdentityLogRepository;

/**
 * 같은 인증 앱 코드·백업 코드를 동시에 내도 한 번만 통과하는지 검증한다 (KVE-2026-3193, 동형 K3190 TOTP 변종).
 *
 * 단일 프로세스에서는 「두 요청이 같은 자격증명을 먼저 읽은」 상황을 낡은 자격증명을 돌려주는 저장소로 재현한다.
 * 수정 전: 마지막 사용 timestep 을 조건 없이 저장하고 백업 코드 목록을 통째로 덮어써 둘 다 통과했다.
 */
class TotpCodeReplayConcurrencyTest extends PluginTestCase
{
    private User $user;

    private string $secret;

    /**
     * 확정된 자격증명을 가진 회원을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'totp-race@test.com']);
        $this->secret = Base32::randomSecret();
        TotpCredential::query()->create([
            'user_id' => $this->user->id,
            'secret' => $this->secret,
            'confirmed_at' => now(),
            'last_used_timestep' => null,
            'backup_codes' => [Hash::make('racebackup01')],
        ]);
    }

    /**
     * 낡은(먼저 읽어 둔) 자격증명을 계속 돌려주는 저장소를 만듭니다.
     *
     * @return TotpCredentialRepositoryInterface 낡은 읽기 저장소
     */
    private function staleRepository(): TotpCredentialRepositoryInterface
    {
        $stale = TotpCredential::query()->where('user_id', $this->user->id)->firstOrFail();

        return new class($stale) extends TotpCredentialRepository
        {
            /**
             * 낡은 자격증명을 들고 있는 대역 저장소를 만듭니다.
             *
             * @param  TotpCredential  $stale  먼저 읽어 둔 자격증명
             */
            public function __construct(private TotpCredential $stale) {}

            /**
             * 낡은 자격증명 사본을 돌려줍니다.
             *
             * @param  int  $userId  회원 ID
             * @return TotpCredential|null 낡은 자격증명
             */
            public function findConfirmedByUserId(int $userId): ?TotpCredential
            {
                return clone $this->stale;
            }

            /**
             * 낡은 자격증명 사본을 돌려줍니다.
             *
             * @param  int  $userId  회원 ID
             * @return TotpCredential|null 낡은 자격증명
             */
            public function findByUserId(int $userId): ?TotpCredential
            {
                return clone $this->stale;
            }
        };
    }

    /**
     * 로그인 challenge 를 만듭니다.
     *
     * @return IdentityVerificationLog 발송 완료 상태의 로그인 challenge
     */
    private function loginChallenge(): IdentityVerificationLog
    {
        return IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => TotpIdentityProvider::PROVIDER_ID,
            'purpose' => 'login',
            'channel' => 'app',
            'user_id' => $this->user->id,
            'target_hash' => hash('sha256', $this->user->email),
            'status' => IdentityVerificationStatus::Sent->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->addMinutes(5),
        ]);
    }

    /**
     * 주어진 자격증명 저장소를 쓰는 provider 를 만듭니다.
     *
     * @param  TotpCredentialRepositoryInterface  $credentials  자격증명 저장소
     * @return TotpIdentityProvider 인증 앱 provider
     */
    private function provider(TotpCredentialRepositoryInterface $credentials): TotpIdentityProvider
    {
        return new TotpIdentityProvider(app(IdentityVerificationLogRepositoryInterface::class), $credentials);
    }

    /**
     * 같은 TOTP 코드로 두 challenge 를 동시에 확인하면 하나만 통과한다.
     *
     * @scenario case=totp_code_single_use
     *
     * @effects totp_code_single_use
     *
     * @return void 반환값 없음
     */
    public function test_same_totp_code_passes_only_once(): void
    {
        $code = Totp::codeAt($this->secret, Totp::timestepAt());
        $stale = $this->staleRepository();

        $first = $this->provider($stale)->verify($this->loginChallenge()->id, ['code' => $code]);
        $second = $this->provider($stale)->verify($this->loginChallenge()->id, ['code' => $code]);

        $this->assertTrue($first->success);
        $this->assertFalse($second->success);
    }

    /**
     * 같은 백업 코드로 두 challenge 를 동시에 확인하면 하나만 통과하고 코드는 한 번만 소진된다.
     *
     * @scenario case=totp_backup_code_single_use
     *
     * @effects totp_backup_code_single_use
     *
     * @return void 반환값 없음
     */
    public function test_same_backup_code_passes_only_once(): void
    {
        $stale = $this->staleRepository();

        $first = $this->provider($stale)->verify($this->loginChallenge()->id, ['code' => 'raceback-up01']);
        $second = $this->provider($stale)->verify($this->loginChallenge()->id, ['code' => 'racebackup01']);

        $this->assertTrue($first->success);
        $this->assertFalse($second->success);
        $this->assertSame([], TotpCredential::query()->where('user_id', $this->user->id)->firstOrFail()->backup_codes);
    }

    /**
     * 같은 challenge 에 오답을 동시에 내도 시도 횟수가 사라지지 않는다 (KVE-2026-3190 인증 앱 변종).
     *
     * @scenario case=totp_attempts_not_lost
     *
     * @effects totp_attempts_not_lost
     *
     * @return void 반환값 없음
     */
    public function test_attempts_are_not_lost_with_stale_challenge(): void
    {
        $challenge = $this->loginChallenge();
        $logs = new StaleReadIdentityLogRepository;
        $logs->freeze($challenge);
        $provider = new TotpIdentityProvider($logs, app(TotpCredentialRepositoryInterface::class));

        $provider->verify($challenge->id, ['code' => '000001']);
        $provider->verify($challenge->id, ['code' => '000002']);

        $this->assertSame(2, (int) $challenge->fresh()->attempts);
    }

    /**
     * 해제·재발급의 본인 확인도 같은 코드를 두 번 쓸 수 없다.
     *
     * @scenario case=totp_ownership_code_single_use
     *
     * @effects totp_ownership_code_single_use
     *
     * @return void 반환값 없음
     */
    public function test_ownership_code_cannot_be_reused(): void
    {
        $code = Totp::codeAt($this->secret, Totp::timestepAt());
        $stale = $this->staleRepository();
        $service = new TotpEnrollmentService($stale);

        $first = $service->regenerateBackupCodes($this->user, $code);
        $second = $service->regenerateBackupCodes($this->user, $code);

        $this->assertNotNull($first);
        $this->assertNull($second);
    }
}
