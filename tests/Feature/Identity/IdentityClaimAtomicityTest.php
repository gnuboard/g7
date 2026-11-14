<?php

namespace Tests\Feature\Identity;

use App\Enums\IdentityVerificationStatus;
use App\Extension\IdentityVerification\Providers\MailIdentityProvider;
use App\Models\IdentityVerificationLog;
use App\Services\IdentityVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\Identity\StaleReadIdentityLogRepository;
use Tests\TestCase;

/**
 * 링크 방식 인증 결과(토큰)를 시작 화면이 한 번만 받아 가는지 검증한다 (KVE 동형 O5).
 *
 * 수정 전: claim() 이 읽은 metadata 를 통째로 덮어써, 같은 행을 먼저 읽은 두 요청이 둘 다 토큰을 받았다.
 */
class IdentityClaimAtomicityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 링크 확인을 마친 challenge 와 시작 화면 비밀값을 만듭니다.
     *
     * @return array{0: IdentityVerificationLog, 1: string} challenge 와 비밀값
     */
    private function verifiedLinkChallenge(): array
    {
        $secret = Str::random(48);
        $log = IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => MailIdentityProvider::ID,
            'purpose' => 'signup',
            'channel' => 'email',
            'target_hash' => hash('sha256', 'claim@example.test'),
            'status' => IdentityVerificationStatus::Verified->value,
            'render_hint' => 'link',
            'attempts' => 1,
            'max_attempts' => 5,
            'metadata' => ['claim_secret_hash' => hash('sha256', $secret)],
            'verification_token' => 'claim-token-'.Str::random(20),
            'verified_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        return [$log, $secret];
    }

    /**
     * 같은 행을 먼저 읽은 두 번째 요청은 토큰을 받지 못하고 claimed 를 받는다.
     *
     * @effects claim_token_once
     *
     * @return void 반환값 없음
     */
    public function test_second_claim_with_stale_metadata_is_claimed(): void
    {
        [$log, $secret] = $this->verifiedLinkChallenge();
        $repository = new StaleReadIdentityLogRepository;
        $repository->freeze($log);
        $service = app()->make(IdentityVerificationService::class, ['logRepository' => $repository]);

        $first = $service->claim($log->id, $secret);
        $second = $service->claim($log->id, $secret);

        $this->assertSame('verified', $first['status']);
        $this->assertSame($log->verification_token, $first['verification_token']);
        $this->assertSame('claimed', $second['status']);
        $this->assertArrayNotHasKey('verification_token', $second);
    }

    /**
     * 비밀값 재발급은 다른 요청이 남긴 metadata(받아 간 시각)를 지우지 않는다.
     *
     * @effects claim_metadata_merged
     *
     * @return void 반환값 없음
     */
    public function test_issue_claim_secret_keeps_other_metadata(): void
    {
        [$log] = $this->verifiedLinkChallenge();
        $log->update(['status' => IdentityVerificationStatus::Sent->value]);
        $repository = new StaleReadIdentityLogRepository;
        $repository->freeze($log);

        // 다른 요청이 그 사이 metadata 에 값을 남겼다
        $fresh = $log->fresh();
        $fresh->metadata = array_merge($fresh->metadata, ['delivered_at' => 'x']);
        $fresh->save();

        $service = app()->make(IdentityVerificationService::class, ['logRepository' => $repository]);
        $this->assertNotNull($service->issueClaimSecret($log->id));

        $this->assertSame('x', $log->fresh()->metadata['delivered_at'] ?? null);
    }
}
