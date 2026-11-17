<?php

namespace Tests\Feature\Identity;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityVerificationStatus;
use App\Extension\IdentityVerification\Providers\MailIdentityProvider;
use App\Models\IdentityVerificationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\Identity\StaleReadIdentityLogRepository;
use Tests\TestCase;

/**
 * 메일 인증번호 확인이 상태·시도 횟수·토큰 발급을 원자적으로 다루는지 검증한다 (KVE-2026-3190, 동형 O1·O3).
 */
class MailIdentityProviderStateGuardTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = '123456';

    /**
     * 인증번호를 아는 메일 challenge 를 만듭니다.
     *
     * @param  IdentityVerificationStatus  $status  초기 상태
     * @return IdentityVerificationLog 만든 challenge
     */
    private function makeChallenge(IdentityVerificationStatus $status = IdentityVerificationStatus::Sent): IdentityVerificationLog
    {
        return IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => MailIdentityProvider::ID,
            'purpose' => 'signup',
            'channel' => 'email',
            'target_hash' => hash('sha256', 'state-guard@example.test'),
            'status' => $status->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 5,
            'metadata' => ['code_hash' => Hash::make(self::CODE)],
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    /**
     * 주어진 저장소를 쓰는 메일 provider 를 만듭니다.
     *
     * @param  IdentityVerificationLogRepositoryInterface|null  $repository  저장소 (기본: 컨테이너)
     * @return MailIdentityProvider 메일 본인인증 프로바이더
     */
    private function provider(?IdentityVerificationLogRepositoryInterface $repository = null): MailIdentityProvider
    {
        return new MailIdentityProvider($repository ?? app(IdentityVerificationLogRepositoryInterface::class));
    }

    /**
     * 이미 확인을 마친 인증번호는 다시 통과하지 않는다 (O1 — 상태 비교가 enum 과 문자열이라 늘 거짓이었다).
     *
     * @effects used_code_rejected
     *
     * @return void 반환값 없음
     */
    public function test_verified_challenge_cannot_be_verified_again(): void
    {
        $log = $this->makeChallenge();

        $first = $this->provider()->verify($log->id, ['code' => self::CODE]);
        $second = $this->provider()->verify($log->id, ['code' => self::CODE]);

        $this->assertTrue($first->success);
        $this->assertFalse($second->success);
        $this->assertSame('INVALID_STATE', $second->failureCode);
    }

    /**
     * 재발송으로 취소된·발송 실패한·만료 처리된 challenge 는 맞는 코드라도 통과하지 않는다.
     *
     * @effects terminal_state_rejected
     *
     * @return void 반환값 없음
     */
    public function test_terminal_state_challenges_are_rejected(): void
    {
        foreach ([IdentityVerificationStatus::Cancelled, IdentityVerificationStatus::Failed, IdentityVerificationStatus::Expired] as $status) {
            $log = $this->makeChallenge($status);

            $result = $this->provider()->verify($log->id, ['code' => self::CODE]);

            $this->assertFalse($result->success, $status->value.' 상태 challenge 가 통과했습니다.');
            $this->assertSame('INVALID_STATE', $result->failureCode);
        }
    }

    /**
     * 같은 행을 먼저 읽은 두 요청이 각자 오답을 내도 시도 횟수가 하나도 사라지지 않는다 (KVE-2026-3190).
     *
     * @effects attempts_not_lost_under_concurrency
     *
     * @return void 반환값 없음
     */
    public function test_stale_reads_do_not_lose_attempts(): void
    {
        $log = $this->makeChallenge();
        $repository = new StaleReadIdentityLogRepository;
        $repository->freeze($log);

        $this->provider($repository)->verify($log->id, ['code' => '000001']);
        $this->provider($repository)->verify($log->id, ['code' => '000002']);

        $this->assertSame(2, (int) $log->fresh()->attempts);
    }

    /**
     * 시도 상한에 이르면 동시 요청이 몰려도 상한을 넘겨 맞혀 볼 수 없다.
     *
     * @effects attempts_capped_at_max
     *
     * @return void 반환값 없음
     */
    public function test_attempts_never_exceed_max_under_stale_reads(): void
    {
        $log = $this->makeChallenge();
        $repository = new StaleReadIdentityLogRepository;
        $repository->freeze($log);

        $codes = [];
        for ($i = 0; $i < 7; $i++) {
            $codes[] = $this->provider($repository)->verify($log->id, ['code' => sprintf('%06d', 900000 + $i)])->failureCode;
        }

        $fresh = $log->fresh();
        $this->assertSame(5, (int) $fresh->attempts);
        $this->assertSame(IdentityVerificationStatus::Failed, $fresh->status);
        $this->assertSame('MAX_ATTEMPTS', end($codes));
    }

    /**
     * 같은 challenge 에 맞는 코드를 동시에 내도 확인 토큰은 하나만 발급된다 (O3).
     *
     * @effects single_token_per_challenge
     *
     * @return void 반환값 없음
     */
    public function test_concurrent_correct_codes_issue_single_token(): void
    {
        $log = $this->makeChallenge();
        $repository = new StaleReadIdentityLogRepository;
        $repository->freeze($log);

        $first = $this->provider($repository)->verify($log->id, ['code' => self::CODE]);
        $second = $this->provider($repository)->verify($log->id, ['code' => self::CODE]);

        $this->assertTrue($first->success);
        $this->assertFalse($second->success);
        // 실제 경합에서는 실패 뒤 재조회가 최신 값(verified)을 읽어 INVALID_STATE 다. 이 대역은 재조회까지 낡은 값을
        // 돌려주므로 MAX_ATTEMPTS 로 보일 수 있다 — 어느 쪽이든 두 번째 요청은 토큰을 받지 못한다.
        $this->assertContains($second->failureCode, ['INVALID_STATE', 'MAX_ATTEMPTS']);
        $this->assertArrayNotHasKey('verification_token', $second->claims);
        $this->assertSame($first->claims['verification_token'], $log->fresh()->verification_token);
    }

    /**
     * 취소는 진행 중인 challenge 만 바꾸고 이미 확인된 challenge 는 그대로 둔다.
     *
     * @effects cancel_only_pending
     *
     * @return void 반환값 없음
     */
    public function test_cancel_does_not_overwrite_verified_challenge(): void
    {
        $log = $this->makeChallenge();
        $this->provider()->verify($log->id, ['code' => self::CODE]);

        $this->provider()->cancel($log->id);

        $this->assertSame(IdentityVerificationStatus::Verified, $log->fresh()->status);
    }
}
