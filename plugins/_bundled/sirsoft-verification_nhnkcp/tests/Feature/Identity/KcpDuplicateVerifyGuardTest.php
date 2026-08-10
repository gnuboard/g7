<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Feature\Identity;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityOriginType;
use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Illuminate\Support\Str;
use Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityRecordRepositoryInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Support\KcpIdentityHasher;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;

/**
 * 인증 시점 동일인 조기 차단 검증.
 *
 * 가입 목적의 비로그인 challenge 는 인증이 끝난 시점에 동일인 여부를 판정해, 사용자가 가입 폼을
 * 다 채운 뒤에야 거절당하는 경험을 막는다. 실제 가입 차단은 가입 직전 listener 가 담당하며 두
 * 지점은 동일한 판정 Service 를 공유한다.
 *
 * @since 1.0.0
 */
class KcpDuplicateVerifyGuardTest extends PluginTestCase
{
    private IdentityVerificationLogRepositoryInterface $logRepository;

    private KcpIdentityRecordRepositoryInterface $recordRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logRepository = app(IdentityVerificationLogRepositoryInterface::class);
        $this->recordRepository = app(KcpIdentityRecordRepositoryInterface::class);
    }

    /**
     * @scenario gate=verify,basis=di,blocking=enabled,relation=duplicate
     *
     * @effects verify_blocks_duplicate_signup_with_duplicate_code
     */
    public function test_signup_with_existing_di_is_blocked_at_verify(): void
    {
        $di = 'DI-DUPLICATE-0001';
        $this->seedRecordFor(User::factory()->create()->id, KcpIdentityHasher::hash($di), null);

        $log = $this->createGuestSignupLog();
        $result = $this->provider(['duplicate_block_enabled' => true, 'duplicate_field' => 'di'])
            ->verify($log->id, $this->certData(di: $di));

        $this->assertFalse($result->success);
        $this->assertSame('DUPLICATE', $result->failureCode);
        $this->assertSame(
            IdentityVerificationStatus::Failed->value,
            $this->logRepository->findById($log->id)->status->value,
        );
    }

    /**
     * @scenario gate=verify,basis=ci,blocking=enabled,relation=duplicate
     *
     * @effects ci_basis_matches_by_ci_hash
     */
    public function test_ci_basis_blocks_by_ci_hash(): void
    {
        $ci = 'CI-DUPLICATE-0001';
        $this->seedRecordFor(User::factory()->create()->id, KcpIdentityHasher::hash('other-di'), KcpIdentityHasher::hash($ci));

        $log = $this->createGuestSignupLog();
        $result = $this->provider(['duplicate_block_enabled' => true, 'duplicate_field' => 'ci'])
            ->verify($log->id, $this->certData(ci: $ci));

        $this->assertFalse($result->success);
        $this->assertSame('DUPLICATE', $result->failureCode);
    }

    /**
     * @scenario gate=verify,basis=di,blocking=disabled,relation=duplicate
     *
     * @effects disabled_setting_passes_both_gates
     */
    public function test_disabled_setting_allows_duplicate_verify(): void
    {
        $di = 'DI-DUPLICATE-0002';
        $this->seedRecordFor(User::factory()->create()->id, KcpIdentityHasher::hash($di), null);

        $log = $this->createGuestSignupLog();
        $result = $this->provider(['duplicate_block_enabled' => false, 'duplicate_field' => 'di'])
            ->verify($log->id, $this->certData(di: $di));

        $this->assertTrue($result->success, '차단을 끄면 인증 자체는 통과해야 한다');
    }

    /**
     * @scenario gate=verify,basis=di,blocking=enabled,relation=new_person
     *
     * @effects verify_blocks_duplicate_signup_with_duplicate_code
     */
    public function test_new_person_passes(): void
    {
        $log = $this->createGuestSignupLog();
        $result = $this->provider(['duplicate_block_enabled' => true, 'duplicate_field' => 'di'])
            ->verify($log->id, $this->certData(di: 'DI-BRAND-NEW'));

        $this->assertTrue($result->success);
    }

    /**
     * @scenario gate=verify,basis=di,blocking=enabled,relation=self_reverify
     *
     * @effects non_signup_purpose_is_not_blocked
     */
    public function test_logged_in_reverification_is_not_blocked(): void
    {
        $user = User::factory()->create();
        $di = 'DI-SELF-0001';
        $this->seedRecordFor($user->id, KcpIdentityHasher::hash($di), null);

        $log = $this->createPendingLog($user->id, 'self_update');
        $result = $this->provider(['duplicate_block_enabled' => true, 'duplicate_field' => 'di'])
            ->verify($log->id, $this->certData(di: $di));

        $this->assertTrue($result->success, '본인 재인증은 중복으로 판정하면 안 된다');
    }

    /**
     * 설정이 주입된 provider.
     *
     * @param  array<string, mixed>  $config
     */
    private function provider(array $config): KcpIdentityProvider
    {
        return app()->makeWith(KcpIdentityProvider::class, ['config' => $config]);
    }

    /**
     * 기존 가입자의 본인확인 record 를 심는다.
     *
     * @param  int  $userId  사용자 ID
     * @param  string|null  $diHash  DI keyed-hash
     * @param  string|null  $ciHash  CI keyed-hash
     */
    private function seedRecordFor(int $userId, ?string $diHash, ?string $ciHash): void
    {
        $this->recordRepository->upsertForUser($userId, [
            'di_hash' => $diHash,
            'ci_hash' => $ciHash,
            'is_adult' => true,
            'is_foreigner' => false,
            'verified_at' => now(),
        ]);
    }

    /**
     * 결과조회 성공 페이로드.
     *
     * @param  string  $di  DI 평문
     * @param  string  $ci  CI 평문
     * @return array<string, mixed>
     */
    private function certData(string $di = 'DI-DEFAULT', string $ci = 'CI-DEFAULT'): array
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
            'ci' => $ci,
            'di' => $di,
        ];
    }

    /**
     * 비로그인 가입 challenge 로그.
     */
    private function createGuestSignupLog(): IdentityVerificationLog
    {
        return $this->createPendingLog(null, 'signup');
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
            'target_hash' => hash('sha256', 'dup-test@example.com'),
            'status' => IdentityVerificationStatus::Sent->value,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 0,
            'origin_type' => IdentityOriginType::Route->value,
            'origin_identifier' => 'api.identity.verify',
            'verification_token' => null,
            'expires_at' => now()->addMinutes(15),
            'metadata' => ['ordr_idxx' => 'G7-'.Str::random(8)],
        ]);
    }
}
