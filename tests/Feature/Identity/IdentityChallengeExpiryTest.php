<?php

namespace Tests\Feature\Identity;

use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use App\Services\IdentityLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 본인인증 요청 만료 — 만료된 요청마다 활동 로그 1줄 (#138 후속 전수 점검 5번)
 *
 * 스케줄러의 만료 처리가 상태만 일괄로 바꾸고 `core.identity.challenge_expired` 를 발화하지 않아, 활동 로그
 * 리스너가 구독해 둔 「본인인증 만료」 기록이 한 번도 남지 않았다. 만료 처리는 만료된 요청마다 한 번 발화한다.
 */
class IdentityChallengeExpiryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 만료 시각이 지난 진행 중 요청만 만료되고, 그 요청마다 활동 로그가 한 줄씩 남는다.
     *
     * @scenario case=past_due_pending
     *
     * @effects expired_challenge_logged_once_each
     *
     * @return void 반환값 없음
     */
    public function test_each_expired_challenge_writes_one_activity_log(): void
    {
        $requested = $this->makeLog(IdentityVerificationStatus::Requested, now()->subMinutes(5));
        $sent = $this->makeLog(IdentityVerificationStatus::Sent, now()->subMinute());
        $notYet = $this->makeLog(IdentityVerificationStatus::Sent, now()->addMinutes(5));
        $verified = $this->makeLog(IdentityVerificationStatus::Verified, now()->subMinutes(5));

        $count = app(IdentityLogService::class)->expirePastDue();

        $this->assertSame(2, $count);
        $this->assertSame(IdentityVerificationStatus::Expired, $requested->fresh()->status);
        $this->assertSame(IdentityVerificationStatus::Expired, $sent->fresh()->status);
        $this->assertSame(IdentityVerificationStatus::Sent, $notYet->fresh()->status);
        $this->assertSame(IdentityVerificationStatus::Verified, $verified->fresh()->status);

        $this->assertSame(2, DB::table('activity_logs')->where('action', 'identity.expired')->count(), '만료된 요청마다 활동 로그 1줄');
    }

    /**
     * 이미 만료 처리된 요청은 다시 돌려도 로그를 또 남기지 않는다.
     *
     * @scenario case=rerun
     *
     * @effects expired_challenge_logged_once_each
     *
     * @return void 반환값 없음
     */
    public function test_rerun_does_not_log_again(): void
    {
        $this->makeLog(IdentityVerificationStatus::Sent, now()->subMinute());

        app(IdentityLogService::class)->expirePastDue();
        $second = app(IdentityLogService::class)->expirePastDue();

        $this->assertSame(0, $second);
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'identity.expired')->count());
    }

    /**
     * 테스트용 본인인증 이력을 만듭니다.
     *
     * @param  IdentityVerificationStatus  $status  상태
     * @param  \DateTimeInterface  $expiresAt  만료 시각
     * @return IdentityVerificationLog 생성된 이력
     */
    private function makeLog(IdentityVerificationStatus $status, \DateTimeInterface $expiresAt): IdentityVerificationLog
    {
        return IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => 'mail',
            'purpose' => 'signup',
            'channel' => 'email',
            'user_id' => null,
            'target_hash' => hash('sha256', Str::random(8).'@example.com'),
            'status' => $status->value,
            'attempts' => 0,
            'max_attempts' => 5,
            'expires_at' => $expiresAt,
        ]);
    }
}
