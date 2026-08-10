<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Feature\Identity;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Enums\IdentityOriginType;
use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use Illuminate\Support\Str;
use Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;

/**
 * 성인인증 가드 검증.
 *
 * 성인인증 purpose 의 challenge 는 만 19세 이상만 통과시킨다. 미성년자는 본인확인 자체가
 * 성공했더라도 토큰을 발급하지 않아 코어 정책 게이트가 자동으로 차단한다(코어 무수정).
 * 생년월일이 비어 있으면 성인 판정 이전에 신원 가드가 먼저 차단한다.
 *
 * @since 1.0.0
 */
class KcpAdultVerificationGuardTest extends PluginTestCase
{
    private IdentityVerificationLogRepositoryInterface $logRepository;

    private KcpIdentityProvider $provider;

    /** 만 19세 미만 생년월일 — 동적 산정으로 언제 실행해도 미성년자임이 보장된다 */
    private string $minorBirthday;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logRepository = app(IdentityVerificationLogRepositoryInterface::class);
        $this->provider = app()->makeWith(KcpIdentityProvider::class, ['config' => ['duplicate_field' => 'di']]);
        $this->minorBirthday = now()->subYears(10)->format('Ymd');
    }

    /**
     * @scenario purpose=adult,age=under_19
     *
     * @effects minor_blocked_with_not_adult_and_no_token
     */
    public function test_adult_purpose_blocks_minor(): void
    {
        $log = $this->createPendingLog(User::factory()->create()->id, KcpIdentityProvider::ADULT_PURPOSE);

        $result = $this->provider->verify($log->id, $this->certData($this->minorBirthday));

        $this->assertFalse($result->success, '미성년자는 성인인증을 통과하면 안 된다');
        $this->assertSame('NOT_ADULT', $result->failureCode);
        $this->assertNull($result->claims['verification_token'] ?? null);

        $reload = $this->logRepository->findById($log->id);
        $this->assertSame(IdentityVerificationStatus::Failed->value, $reload->status->value);
        $this->assertNull($reload->verification_token);
    }

    /**
     * @scenario purpose=adult,age=over_19
     *
     * @effects adult_passes_with_token
     */
    public function test_adult_purpose_passes_adult(): void
    {
        $log = $this->createPendingLog(User::factory()->create()->id, KcpIdentityProvider::ADULT_PURPOSE);

        $result = $this->provider->verify($log->id, $this->certData('19900101'));

        $this->assertTrue($result->success);
        $this->assertNotEmpty($result->claims['verification_token'] ?? null);
        $this->assertTrue($this->logRepository->findById($log->id)->isVerified());
    }

    /**
     * @scenario purpose=adult,age=exactly_19_today
     *
     * @effects nineteenth_birthday_today_counts_as_adult
     */
    public function test_nineteenth_birthday_today_passes(): void
    {
        $log = $this->createPendingLog(User::factory()->create()->id, KcpIdentityProvider::ADULT_PURPOSE);

        $result = $this->provider->verify($log->id, $this->certData(now()->subYears(19)->format('Ymd')));

        $this->assertTrue($result->success, '만 19세가 되는 당일은 성인으로 인정해야 한다');
    }

    /**
     * @scenario purpose=non_adult,age=under_19
     *
     * @effects non_adult_purpose_passes_for_minor
     */
    public function test_non_adult_purpose_is_unaffected_by_age(): void
    {
        $log = $this->createPendingLog(User::factory()->create()->id, 'self_update');

        $result = $this->provider->verify($log->id, $this->certData($this->minorBirthday));

        $this->assertTrue($result->success, '성인인증 외 purpose 는 나이 제한이 없어야 한다');
    }

    /**
     * @scenario purpose=adult,age=birthday_missing
     *
     * @effects missing_birthday_blocked_by_incomplete_identity
     */
    public function test_missing_birthday_is_blocked_by_identity_guard_first(): void
    {
        $liveProvider = app()->makeWith(KcpIdentityProvider::class, [
            'config' => ['duplicate_field' => 'di', 'is_test_mode' => false],
        ]);
        $log = $this->createPendingLog(User::factory()->create()->id, KcpIdentityProvider::ADULT_PURPOSE);

        // 운영 모드에서 생년월일 부재는 "미성년자" 가 아니라 "신원 불완전" 이 정확한 사유다.
        // KCP 규격상 생년월일은 조건 없이 항상 내려오므로, 없으면 비정상 응답이다.
        $result = $liveProvider->verify($log->id, $this->certData(''));

        $this->assertFalse($result->success);
        $this->assertSame('INCOMPLETE_IDENTITY', $result->failureCode);
    }

    /**
     * 테스트 모드는 생년월일 부재를 신원 불완전으로 보지 않는다. KCP 테스트 서버가 실제 통신사
     * 조회를 하지 않아 생년월일을 빈 값으로 응답하기 때문이며, 운영과 동일하게 거절하면 테스트
     * 환경에서는 어떤 방법으로도 인증을 완주할 수 없다.
     *
     * 다만 성인 여부는 계산할 수 없으므로 성인인증 목적은 계속 막힌다 (fail-closed).
     *
     * @scenario mode=test,birth_day=absent,purpose=adult
     *
     * @effects test_mode_missing_birthday_blocked_by_adult_guard_not_identity_guard
     */
    public function test_test_mode_missing_birthday_falls_through_to_adult_guard(): void
    {
        $log = $this->createPendingLog(User::factory()->create()->id, KcpIdentityProvider::ADULT_PURPOSE);

        $result = $this->provider->verify($log->id, $this->certData(''));

        $this->assertFalse($result->success, '성인 여부를 알 수 없으면 성인인증은 통과시키지 않는다');
        $this->assertSame('NOT_ADULT', $result->failureCode, '테스트 모드에서는 신원 가드가 아니라 성인 가드가 막아야 한다');
        $this->assertNull($result->claims['verification_token'] ?? null);
    }

    /**
     * 결과조회 성공 페이로드 — 생년월일만 파라미터화.
     *
     * @param  string  $birthday  YYYYMMDD
     * @return array<string, mixed>
     */
    private function certData(string $birthday): array
    {
        return [
            'res_cd' => '0000',
            'res_msg' => '정상처리',
            'user_name' => '홍길동',
            'birth_day' => $birthday,
            'phone_no' => '01012345678',
            'comm_id' => 'SKT',
            'sex_code' => '01',
            'local_code' => '01',
            'ci' => 'CI-'.Str::random(10),
            'di' => 'DI-'.Str::random(10),
        ];
    }

    /**
     * 인증 대기 상태의 challenge 로그를 만든다.
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $purpose  IDV purpose
     */
    private function createPendingLog(int $userId, string $purpose): IdentityVerificationLog
    {
        return $this->logRepository->create([
            'id' => (string) Str::uuid(),
            'provider_id' => KcpIdentityProvider::PROVIDER_ID,
            'purpose' => $purpose,
            'channel' => KcpIdentityProvider::CHANNEL,
            'user_id' => $userId,
            'target_hash' => hash('sha256', 'adult-test@example.com'),
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
