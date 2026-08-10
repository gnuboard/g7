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
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;

/**
 * 테스트 모드에서 생년월일이 빠진 응답 처리 검증.
 *
 * KCP 규격상 `birth_day` 는 조건 없이 항상 내려오는 표준 필드지만(공식 "본인확인 결과 복호화"
 * 표), KCP 테스트 서버는 실제 통신사 조회를 수행하지 않아 생년월일·성별·내외국인을 빈 값으로
 * 응답한다. 운영과 동일하게 거절하면 테스트 환경에서는 어떤 방법으로도 인증을 완주할 수 없어
 * 검수가 불가능해진다.
 *
 * 따라서 테스트 모드에서만 생년월일 부재를 허용하고, 운영 모드의 판정은 그대로 유지한다.
 * 생년월일을 못 받으면 성인 여부는 계산할 수 없으므로 성인인증 목적은 계속 막는다.
 *
 * @since 1.0.0
 */
class KcpTestModeBirthdayFallbackTest extends PluginTestCase
{
    private IdentityVerificationLogRepositoryInterface $logRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logRepository = app(IdentityVerificationLogRepositoryInterface::class);
    }

    /**
     * @scenario mode=test,birth_day=absent,purpose=general
     *
     * @effects test_mode_accepts_response_without_birthday
     */
    public function test_test_mode_accepts_missing_birthday(): void
    {
        $log = $this->createPendingLog(User::factory()->create()->id, 'self_update');

        $result = $this->provider(true)->verify($log->id, $this->certData(''));

        $this->assertTrue($result->success, '테스트 모드에서는 생년월일 없이도 본인확인을 인정해야 한다');
        $this->assertNotEmpty($result->claims['verification_token'] ?? null);
        $this->assertTrue($this->logRepository->findById($log->id)->isVerified());
    }

    /**
     * @scenario mode=live,birth_day=absent,purpose=general
     *
     * @effects live_mode_rejects_response_without_birthday
     */
    public function test_live_mode_still_rejects_missing_birthday(): void
    {
        $log = $this->createPendingLog(User::factory()->create()->id, 'self_update');

        $result = $this->provider(false)->verify($log->id, $this->certData(''));

        $this->assertFalse($result->success, '운영 모드에서 생년월일 부재는 비정상 응답이다');
        $this->assertSame('INCOMPLETE_IDENTITY', $result->failureCode);
    }

    /**
     * 생년월일 외 신원 핵심값은 테스트 모드에서도 완화하지 않는다. CI/DI 가 비면 중복가입 차단이
     * 조용히 무력화되므로 어느 모드에서도 인정해서는 안 된다.
     *
     * @scenario mode=test,birth_day=absent,purpose=general
     *
     * @effects test_mode_still_rejects_missing_identity_values
     */
    public function test_test_mode_does_not_relax_other_identity_values(): void
    {
        $log = $this->createPendingLog(User::factory()->create()->id, 'self_update');

        $payload = array_merge($this->certData(''), ['di' => '']);
        $result = $this->provider(true)->verify($log->id, $payload);

        $this->assertFalse($result->success);
        $this->assertSame('INCOMPLETE_IDENTITY', $result->failureCode);
    }

    /**
     * 생년월일이 없으면 저장된 record 의 생년월일은 비어 있고 성인 여부는 참이 될 수 없다.
     * 마이페이지는 이 상태를 "미성년" 이 아니라 "확인 불가" 로 표시해야 하므로, 저장 단계에서
     * 두 상태가 구분 가능해야 한다.
     *
     * @scenario mode=test,birth_day=absent,purpose=general
     *
     * @effects missing_birthday_stored_as_null_with_adult_false
     */
    public function test_record_keeps_birthday_null_when_absent(): void
    {
        $user = User::factory()->create();
        $log = $this->createPendingLog($user->id, 'self_update');

        $this->provider(true)->verify($log->id, $this->certData(''));

        $record = app(KcpIdentityRecordRepositoryInterface::class)->findByUserId($user->id);

        $this->assertNotNull($record, '인증이 인정되었으면 record 가 생성되어야 한다');
        $this->assertNull($record->birthday_encrypted, '생년월일 미제공은 빈 암호문이 아니라 null 로 남는다');
        $this->assertFalse((bool) $record->is_adult, '생년월일을 모르면 성인으로 단정하지 않는다');
    }

    /**
     * @param  bool  $isTestMode  테스트 모드 여부
     */
    private function provider(bool $isTestMode): KcpIdentityProvider
    {
        return app()->makeWith(KcpIdentityProvider::class, [
            'config' => ['duplicate_field' => 'di', 'is_test_mode' => $isTestMode],
        ]);
    }

    /**
     * KCP 테스트 서버 응답 형태 — 생년월일·성별·내외국인이 빈 값으로 내려온다.
     *
     * @param  string  $birthday  YYYYMMDD (빈 문자열이면 미제공)
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
            'sex_code' => '',
            'local_code' => '',
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
            'target_hash' => hash('sha256', 'birthday-fallback@example.com'),
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
