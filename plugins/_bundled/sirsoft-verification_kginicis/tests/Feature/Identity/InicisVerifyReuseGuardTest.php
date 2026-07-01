<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Feature\Identity;

use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use Plugins\Sirsoft\VerificationKginicis\Exceptions\AlreadyConsumedException;
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;

/**
 * 회귀 테스트 — 응답 재사용 토큰 무제한 재발급 차단.
 *
 * 배경: verify()/cancel() 의 재처리 가드가 `$log->status === 'verified'` 로 enum 캐스팅된
 * status 와 문자열을 비교해 항상 false → 이미 처리된 mTxId 로 토큰을 무제한 재발급받을 수 있었다.
 * `$log->isVerified()` (=== IdentityVerificationStatus::Verified) 로 정정.
 *
 * @since 1.0.0-beta.1
 */
class InicisVerifyReuseGuardTest extends PluginTestCase
{
    /**
     * verified 상태 challenge 로그를 생성한다.
     *
     * @return IdentityVerificationLog
     */
    private function makeVerifiedLog(): IdentityVerificationLog
    {
        return IdentityVerificationLog::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'provider_id' => InicisIdentityProvider::PROVIDER_ID,
            'purpose' => 'signup',
            'channel' => 'ipin',
            'user_id' => null,
            'target_hash' => hash('sha256', 'reuse@example.com'),
            'status' => IdentityVerificationStatus::Verified,
            'render_hint' => 'text_code',
            'attempts' => 0,
            'max_attempts' => 0,
        ]);
    }

    private function provider(): InicisIdentityProvider
    {
        return app(InicisIdentityProvider::class);
    }

    /**
     * @scenario mode=live,live_credentials=filled
     * @effects verify_on_already_verified_log_throws_already_consumed_exception
     */
    public function test_verify_on_already_verified_log_throws_already_consumed(): void
    {
        $log = $this->makeVerifiedLog();

        $this->expectException(AlreadyConsumedException::class);

        $this->provider()->verify($log->id, ['mTxId' => 'dummy-mtxid']);
    }

    /**
     * @scenario mode=live,live_credentials=filled
     * @effects cancel_on_already_verified_log_returns_false_and_keeps_verified_status
     */
    public function test_cancel_on_already_verified_log_returns_false(): void
    {
        $log = $this->makeVerifiedLog();

        $result = $this->provider()->cancel($log->id);

        $this->assertFalse($result);

        // 상태가 cancelled 로 바뀌지 않고 verified 로 보존되어야 한다.
        $this->assertTrue($log->fresh()->isVerified());
    }
}
