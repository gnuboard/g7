<?php

namespace Tests\Unit\Repositories;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * IdentityVerificationLogRepository 의 검증 토큰 조회 만료 검증.
 *
 * 회귀 배경(KVE-2026-2029): findVerifiedForToken() 이 status/consumed_at 만 보고
 * expires_at 술어를 갖지 않아, 만료된 본인인증 토큰이 무기한 유효하게 취급됐다.
 * 이 저장소를 공유하는 소비자(정책 미들웨어·정책 서비스·회원가입/비밀번호재설정
 * 리스너·IdvTokenRule)가 전부 같은 결함을 물려받았으므로 저장소 단일 지점에서 닫는다.
 *
 * @group identity
 * @group unit
 */
class IdentityVerificationLogRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    private IdentityVerificationLogRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(IdentityVerificationLogRepositoryInterface::class);
    }

    /**
     * 검증 완료 로그를 생성합니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 속성
     */
    private function makeVerifiedLog(array $overrides = []): IdentityVerificationLog
    {
        return IdentityVerificationLog::create(array_merge([
            'provider_id' => 'test-provider',
            'purpose' => 'sensitive_action',
            'channel' => 'email',
            'user_id' => null,
            'target_hash' => hash('sha256', 'kve2029-'.Str::random(8)),
            'status' => IdentityVerificationStatus::Verified->value,
            'attempts' => 1,
            'max_attempts' => 5,
            'verification_token' => 'kve2029-'.Str::random(32),
            'expires_at' => Carbon::now()->addMinutes(10),
            'verified_at' => Carbon::now()->subMinute(),
            'consumed_at' => null,
        ], $overrides));
    }

    /**
     * 미만료 검증 토큰은 조회된다 (정상 흐름 불변).
     */
    public function test_unexpired_verified_token_is_returned(): void
    {
        $log = $this->makeVerifiedLog();

        $found = $this->repository->findVerifiedForToken(
            $log->verification_token,
            'sensitive_action'
        );

        $this->assertNotNull($found);
        $this->assertSame($log->id, $found->id);
    }

    /**
     * 만료된 검증 토큰은 조회되지 않는다 (KVE-2026-2029).
     */
    public function test_expired_verified_token_is_not_returned(): void
    {
        $log = $this->makeVerifiedLog([
            'expires_at' => Carbon::now()->subMinute(),
        ]);

        $found = $this->repository->findVerifiedForToken(
            $log->verification_token,
            'sensitive_action'
        );

        $this->assertNull($found);
    }

    /**
     * 만료 경계: 1초 전은 통과, 1초 후는 차단.
     */
    public function test_expiry_boundary_is_closed_strictly(): void
    {
        $valid = $this->makeVerifiedLog([
            'expires_at' => Carbon::now()->addSecond(),
        ]);
        $expired = $this->makeVerifiedLog([
            'expires_at' => Carbon::now()->subSecond(),
        ]);

        $this->assertNotNull(
            $this->repository->findVerifiedForToken($valid->verification_token, 'sensitive_action')
        );
        $this->assertNull(
            $this->repository->findVerifiedForToken($expired->verification_token, 'sensitive_action')
        );
    }

    /**
     * expires_at 이 비어 있는 토큰은 조회되지 않는다 (엄격 차단).
     *
     * 보안 토큰은 모든 provider 가 challenge 생성 시 expires_at 을 세팅한다.
     * NULL 은 정상 발급 산물이 아니므로 허용 분기를 두지 않는다.
     */
    public function test_token_without_expiry_is_not_returned(): void
    {
        $log = $this->makeVerifiedLog([
            'expires_at' => null,
        ]);

        $found = $this->repository->findVerifiedForToken(
            $log->verification_token,
            'sensitive_action'
        );

        $this->assertNull($found);
    }

    /**
     * 이미 소비된 토큰은 조회되지 않는다 (기존 동작 보존).
     */
    public function test_consumed_token_is_not_returned(): void
    {
        $log = $this->makeVerifiedLog([
            'consumed_at' => Carbon::now(),
        ]);

        $found = $this->repository->findVerifiedForToken(
            $log->verification_token,
            'sensitive_action'
        );

        $this->assertNull($found);
    }

    /**
     * 목적(purpose)이 다르면 조회되지 않는다 (기존 동작 보존).
     */
    public function test_token_with_other_purpose_is_not_returned(): void
    {
        $log = $this->makeVerifiedLog();

        $found = $this->repository->findVerifiedForToken(
            $log->verification_token,
            'signup'
        );

        $this->assertNull($found);
    }
}
