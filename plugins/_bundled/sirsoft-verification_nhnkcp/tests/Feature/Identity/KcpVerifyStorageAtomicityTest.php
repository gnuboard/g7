<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Feature\Identity;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityOriginType;
use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Illuminate\Support\Str;
use Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Models\KcpIdentityRecord;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;
use RuntimeException;

/**
 * "인증 성공 = 본인확인 정보 저장 완료" 원자성 검증.
 *
 * 토큰만 먼저 발급되고 정보 저장이 실패하면 "인증은 됐는데 정보는 없는" 상태가 남아, 로그인
 * 사용자는 record 없는 인증 완료로, 비로그인 사용자는 가입 후 정보 흡수 실패로 이어진다.
 * 저장이 실패하면 인증 자체가 실패로 되돌아가야 한다.
 *
 * @since 1.0.0
 */
class KcpVerifyStorageAtomicityTest extends PluginTestCase
{
    private IdentityVerificationLogRepositoryInterface $logRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logRepository = app(IdentityVerificationLogRepositoryInterface::class);
    }

    /**
     * 로그인 사용자: record 저장이 실패하면 인증 완료 도장(토큰)도 함께 되돌아간다.
     *
     * @scenario outcome=storage_failure,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects record_save_failure_rolls_back_token_issuance
     */
    public function test_record_save_failure_rolls_back_token_issuance_for_logged_in_user(): void
    {
        $user = User::factory()->create();
        $log = $this->createPendingLog($user->id, 'self_update');

        // 저장 단계에서만 실패하도록 Repository 를 교체한다 (통신/판정 단계는 정상 통과).
        $this->app->bind(KcpIdentityRecordRepositoryInterface::class, function () {
            return new class implements KcpIdentityRecordRepositoryInterface
            {
                public function findByUserId(int $userId): ?KcpIdentityRecord
                {
                    return null;
                }

                public function findByDiHash(string $diHash): ?KcpIdentityRecord
                {
                    return null;
                }

                public function findByCiHash(string $ciHash): ?KcpIdentityRecord
                {
                    return null;
                }

                public function findByHashExceptUser(string $column, string $hash, ?int $exceptUserId = null): ?KcpIdentityRecord
                {
                    return null;
                }

                public function upsertForUser(int $userId, array $attributes): KcpIdentityRecord
                {
                    throw new RuntimeException('저장 장치 오류 시뮬레이션');
                }

                public function deleteByUserId(int $userId): bool
                {
                    return true;
                }
            };
        });

        $result = $this->provider()->verify($log->id, $this->certData());

        $this->assertFalse($result->success, '저장이 실패하면 인증도 실패해야 한다');
        $this->assertSame('STORAGE_FAILED', $result->failureCode);

        $reloaded = $this->logRepository->findById($log->id);
        $this->assertNotSame(
            IdentityVerificationStatus::Verified->value,
            $reloaded->status->value,
            '저장 실패 시 인증 완료 상태로 남으면 안 된다',
        );
        $this->assertNull($reloaded->verification_token, '저장 실패 시 토큰이 발급되면 안 된다');
        $this->assertNull($reloaded->verified_at);
        $this->assertDatabaseCount('nhnkcp_identity_records', 0);
    }

    /**
     * 비로그인 사용자: 임시 보관이 실패하면 인증 완료 도장도 함께 되돌아간다.
     *
     * @scenario outcome=storage_failure,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects guest_stash_failure_rolls_back_token_issuance
     */
    public function test_pending_stash_failure_rolls_back_token_issuance_for_guest(): void
    {
        $log = $this->createPendingLog(null, 'signup');

        // 캐시 보관만 실패시킨다. provider 는 ServiceProvider 의 contextual binding 으로 캐시를
        // 주입받으므로, 전역 바인딩이 아니라 같은 contextual 경로를 덮어써야 실제로 반영된다.
        $failingCache = \Mockery::mock(CacheInterface::class);
        $failingCache->shouldReceive('put')->andThrow(new RuntimeException('캐시 저장 오류 시뮬레이션'));
        $this->app->when(KcpIdentityProvider::class)
            ->needs(CacheInterface::class)
            ->give(fn () => $failingCache);

        $result = $this->provider()->verify($log->id, $this->certData());

        $this->assertFalse($result->success);
        $this->assertSame('STORAGE_FAILED', $result->failureCode);

        $reloaded = $this->logRepository->findById($log->id);
        $this->assertNotSame(IdentityVerificationStatus::Verified->value, $reloaded->status->value);
        $this->assertNull($reloaded->verification_token);
    }

    /**
     * 정상 경로: 저장과 토큰 발급이 함께 성립한다 (대조군).
     *
     * @scenario outcome=success,lookup=by_reg_cert_key,missing_field=none
     *
     * @effects record_and_token_are_committed_together
     */
    public function test_successful_verify_stores_record_and_issues_token_together(): void
    {
        $user = User::factory()->create();
        $log = $this->createPendingLog($user->id, 'self_update');

        $result = $this->provider()->verify($log->id, $this->certData());

        $this->assertTrue($result->success);

        $reloaded = $this->logRepository->findById($log->id);
        $this->assertSame(IdentityVerificationStatus::Verified->value, $reloaded->status->value);
        $this->assertNotEmpty($reloaded->verification_token);
        $this->assertDatabaseCount('nhnkcp_identity_records', 1);
    }

    /**
     * 설정이 주입된 provider.
     */
    private function provider(): KcpIdentityProvider
    {
        return app()->makeWith(KcpIdentityProvider::class, [
            'config' => ['duplicate_block_enabled' => false, 'duplicate_field' => 'di'],
        ]);
    }

    /**
     * 결과조회 성공 페이로드.
     *
     * @return array<string, mixed>
     */
    private function certData(): array
    {
        return [
            'res_cd' => '0000',
            'res_msg' => '정상처리',
            'user_name' => '홍길동',
            'birth_day' => '19900101',
            'phone_no' => '01012345678',
            'comm_id' => 'SKT',
            'sex_code' => '01',
            'local_code' => '01',
            'ci' => 'CI-ATOMIC-0001',
            'di' => 'DI-ATOMIC-0001',
        ];
    }

    /**
     * 인증 대기 challenge 로그.
     *
     * @param  int|null  $userId  사용자 ID (비로그인은 null)
     * @param  string  $purpose  IDV purpose
     */
    private function createPendingLog(?int $userId, string $purpose): IdentityVerificationLog
    {
        return $this->logRepository->create([
            'id' => (string) Str::uuid(),
            'provider_id' => KcpIdentityProvider::PROVIDER_ID,
            'purpose' => $purpose,
            'channel' => KcpIdentityProvider::CHANNEL,
            'user_id' => $userId,
            'target_hash' => hash('sha256', 'atomicity@example.com'),
            'status' => IdentityVerificationStatus::Sent->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 0,
            'origin_type' => IdentityOriginType::Route->value,
            'expires_at' => now()->addMinutes(15),
        ]);
    }
}
