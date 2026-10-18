<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Tests\Feature\Identity;

use App\Models\IdentityVerificationLog;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\VerificationNhnkcp\Exceptions\RemoteCallException;
use Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Models\KcpCertTransaction;
use Plugins\Sirsoft\VerificationNhnkcp\Support\KcpIdentityHasher;
use Plugins\Sirsoft\VerificationNhnkcp\Tests\PluginTestCase;

/**
 * challenge 발행(거래등록) 검증.
 *
 * 거래등록이 성공해야만 challenge 를 발행한다 — 실패 시 "열 수 없는 인증창" 을 위한 로그 행이
 * 남지 않아야 한다. 성공 시 표준창 호출 정보가 프론트에 전달되고, 거래키는 해시 인덱스 +
 * 암호문으로만 저장된다 (평문 컬럼 부재).
 *
 * @since 1.0.0
 */
class KcpRequestChallengeTest extends PluginTestCase
{
    private const TEST_CONFIG = [
        'is_test_mode' => true,
        'test_site_cd' => 'AO7F3',
        'test_enc_key' => 'c2a22fa3ebe4698075bcac6b433d52e351c881b02fb83488d4283a43385b1f8e',
    ];

    /**
     * @scenario mode=test,kcp_response=success
     *
     * @effects challenge_issued_with_call_url_and_reg_cert_key
     */
    public function test_successful_registration_issues_challenge_with_standard_window_payload(): void
    {
        $this->fakeRegistration('0000');

        $user = User::factory()->create();
        $challenge = $this->provider()->requestChallenge($user, ['purpose' => 'self_update']);

        $this->assertSame(KcpIdentityProvider::PROVIDER_ID, $challenge->providerId);
        $this->assertSame('phone', $challenge->channel);
        $this->assertSame('https://testcert.kcp.co.kr/certGateway.do', $challenge->publicPayload['call_url']);
        $this->assertSame('CERTKEY000000001', $challenge->publicPayload['reg_cert_key']);
        $this->assertTrue($challenge->publicPayload['is_test_mode']);
        $this->assertSame(hash('sha256', mb_strtolower($user->email)), $challenge->targetHash);

        $log = IdentityVerificationLog::query()->whereKey($challenge->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status->value);
        $this->assertSame((int) $user->id, (int) $log->user_id);
    }

    /**
     * @scenario mode=test,kcp_response=success
     *
     * @effects transaction_row_stores_hashed_and_encrypted_reg_cert_key
     */
    public function test_transaction_row_stores_key_as_hash_and_ciphertext_only(): void
    {
        $this->fakeRegistration('0000');

        $challenge = $this->provider()->requestChallenge(['email' => 'guest@example.test'], ['purpose' => 'signup']);

        $transaction = KcpCertTransaction::query()->where('challenge_id', $challenge->id)->first();
        $this->assertNotNull($transaction);
        $this->assertSame(
            KcpIdentityHasher::hashRegCertKey('CERTKEY000000001'),
            $transaction->reg_cert_key_hash,
            '거래키는 조회용 keyed-hash 로만 인덱싱되어야 한다',
        );
        $this->assertNotSame('CERTKEY000000001', $transaction->reg_cert_key_encrypted, '평문 보관 금지');
        $this->assertSame('CERTKEY000000001', Crypt::decryptString($transaction->reg_cert_key_encrypted));
        $this->assertSame($challenge->metadata['ordr_idxx'], $transaction->ordr_idxx);
        $this->assertNull($transaction->consumed_at);
    }

    /**
     * @scenario mode=test,kcp_response=res_cd_error
     *
     * @effects res_cd_error_raises_remote_call_exception_without_challenge_row
     */
    public function test_rejected_registration_does_not_create_any_row(): void
    {
        $this->fakeRegistration('8133', '가맹점 정보가 올바르지 않습니다.');

        try {
            $this->provider()->requestChallenge(['email' => 'guest@example.test'], ['purpose' => 'signup']);
            $this->fail('거래등록 거절 시 예외가 발생해야 한다');
        } catch (RemoteCallException $e) {
            $this->assertStringContainsString('8133', $e->detail);
        }

        $this->assertSame(0, IdentityVerificationLog::query()->count(), 'challenge 로그가 남으면 안 된다');
        $this->assertSame(0, KcpCertTransaction::query()->count(), '거래 행이 남으면 안 된다');
    }

    /**
     * @scenario mode=test,kcp_response=http_error
     *
     * @effects http_error_raises_remote_call_exception_without_challenge_row
     */
    public function test_communication_failure_does_not_create_any_row(): void
    {
        Http::fake([
            'testcert.kcp.co.kr/*' => Http::response('gateway timeout', 504),
        ]);

        $this->expectException(RemoteCallException::class);

        try {
            $this->provider()->requestChallenge(['email' => 'guest@example.test'], ['purpose' => 'signup']);
        } finally {
            $this->assertSame(0, IdentityVerificationLog::query()->count());
            $this->assertSame(0, KcpCertTransaction::query()->count());
        }
    }

    /**
     * @scenario mode=live,kcp_response=success
     *
     * @effects live_mode_uses_live_host_and_prefixed_site_cd
     */
    public function test_live_mode_calls_live_host_with_prefixed_site_code(): void
    {
        Http::fake([
            'cert.kcp.co.kr/*' => Http::response([
                'res_cd' => '0000',
                'res_msg' => '정상처리',
                'call_url' => 'https://cert.kcp.co.kr/certGateway.do',
                'reg_cert_key' => 'LIVEKEY000000001',
            ]),
        ]);

        $provider = $this->provider([
            'is_test_mode' => false,
            'live_site_cd' => 'A1B2C',
            'live_enc_key' => 'live-enc-key',
        ]);

        $challenge = $provider->requestChallenge(['email' => 'guest@example.test'], ['purpose' => 'signup']);

        $this->assertSame('https://cert.kcp.co.kr/certGateway.do', $challenge->publicPayload['call_url']);
        $this->assertFalse($challenge->publicPayload['is_test_mode']);

        Http::assertSent(function ($request): bool {
            $this->assertStringStartsWith('https://cert.kcp.co.kr/', $request->url());
            $this->assertSame('SMA1B2C', $request->header('site_cd')[0] ?? null);

            return true;
        });
    }

    /**
     * 거래등록 응답을 가로챈다.
     *
     * @param  string  $resCd  응답 코드
     * @param  string  $resMsg  응답 메시지
     */
    private function fakeRegistration(string $resCd, string $resMsg = '정상처리'): void
    {
        Http::fake([
            'testcert.kcp.co.kr/*' => Http::response([
                'res_cd' => $resCd,
                'res_msg' => $resMsg,
                'call_url' => $resCd === '0000' ? 'https://testcert.kcp.co.kr/certGateway.do' : '',
                'reg_cert_key' => $resCd === '0000' ? 'CERTKEY000000001' : '',
            ]),
        ]);
    }

    /**
     * 설정이 주입된 provider 인스턴스.
     *
     * @param  array<string, mixed>  $config
     */
    private function provider(array $config = self::TEST_CONFIG): KcpIdentityProvider
    {
        return app()->makeWith(KcpIdentityProvider::class, ['config' => $config]);
    }
}
