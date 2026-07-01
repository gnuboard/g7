<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Unit\Services;

use App\Extension\IdentityVerification\DTO\VerificationResult;
use App\Services\IdentityVerificationService;
use Carbon\Carbon;
use Plugins\Sirsoft\VerificationKginicis\Exceptions\AlreadyConsumedException;
use Plugins\Sirsoft\VerificationKginicis\Exceptions\DecryptException;
use Plugins\Sirsoft\VerificationKginicis\Exceptions\InvalidAuthUrlException;
use Plugins\Sirsoft\VerificationKginicis\Exceptions\RemoteCallException;
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisChallengeMappingRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Services\InicisCallbackResolver;
use Plugins\Sirsoft\VerificationKginicis\Services\InicisGatewayInterface;
use Plugins\Sirsoft\VerificationKginicis\Support\InicisIdentityHasher;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;

/**
 * InicisCallbackResolver Service 단위 테스트.
 *
 * 외부 의존성 (gateway / mappingRepository / identityService) 은 mock 으로 격리.
 * 비즈니스 분기만 검증 — 실제 hook 체인 검증은 Listener 통합 테스트 (별도 파일) 가 담당.
 *
 * 시나리오 매니페스트 → tests/scenarios/inicis_callback_resolve.yaml 참조.
 */
class InicisCallbackResolverTest extends PluginTestCase
{
    private IdentityVerificationService $identityService;

    private InicisGatewayInterface $gateway;

    private InicisChallengeMappingRepositoryInterface $mappingRepository;

    private InicisCallbackResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->identityService = $this->createMock(IdentityVerificationService::class);
        $this->gateway = $this->createMock(InicisGatewayInterface::class);
        $this->mappingRepository = $this->createMock(InicisChallengeMappingRepositoryInterface::class);

        $this->resolver = new InicisCallbackResolver(
            identityService: $this->identityService,
            gateway: $this->gateway,
            mappingRepository: $this->mappingRepository,
        );
    }

    public function test_returns_provider_error_when_result_code_not_zero(): void
    {
        $this->gateway->expects($this->never())->method('verifyResult');

        $outcome = $this->resolver->resolve(
            callbackInput: ['resultCode' => '9999', 'resultMsg' => '실패'],
            context: [],
        );

        $this->assertFalse($outcome->success);
        $this->assertSame('9999', $outcome->failureCode);
    }

    public function test_returns_provider_error_when_result_code_blank(): void
    {
        $outcome = $this->resolver->resolve(callbackInput: [], context: []);

        $this->assertFalse($outcome->success);
        $this->assertSame('PROVIDER_ERROR', $outcome->failureCode);
    }

    public function test_invalid_auth_url_translates_to_invalid_auth_url_failure(): void
    {
        $this->gateway->method('verifyResult')->willThrowException(
            new InvalidAuthUrlException('https://evil.example.com/api'),
        );

        $outcome = $this->resolver->resolve(
            callbackInput: $this->successCallbackInput(),
            context: [],
        );

        $this->assertFalse($outcome->success);
        $this->assertSame('INVALID_AUTH_URL', $outcome->failureCode);
    }

    public function test_remote_call_failure_translates_to_remote_call_failed(): void
    {
        $this->gateway->method('verifyResult')->willThrowException(
            new RemoteCallException('curl 실패', 500),
        );

        $outcome = $this->resolver->resolve(
            callbackInput: $this->successCallbackInput(),
            context: [],
        );

        $this->assertSame('REMOTE_CALL_FAILED', $outcome->failureCode);
    }

    public function test_decrypt_failure_translates_to_decrypt_failed(): void
    {
        $this->gateway->method('verifyResult')->willThrowException(
            new DecryptException('userName'),
        );

        $outcome = $this->resolver->resolve(
            callbackInput: $this->successCallbackInput(),
            context: [],
        );

        $this->assertSame('DECRYPT_FAILED', $outcome->failureCode);
    }

    public function test_not_found_when_mtxid_unmatched(): void
    {
        $this->gateway->method('verifyResult')->willReturn([
            'mTxId' => 'mtx-orphan',
            'userName' => '홍길동',
        ]);
        $this->mappingRepository->method('findChallengeIdByMtxid')->willReturn(null);

        $outcome = $this->resolver->resolve(
            callbackInput: $this->successCallbackInput(),
            context: [],
        );

        $this->assertSame('NOT_FOUND', $outcome->failureCode);
        $this->assertNull($outcome->challengeId);
    }

    public function test_not_found_when_mtxid_blank(): void
    {
        $this->gateway->method('verifyResult')->willReturn(['mTxId' => '']);
        $this->mappingRepository->expects($this->never())->method('findChallengeIdByMtxid');

        $outcome = $this->resolver->resolve(
            callbackInput: $this->successCallbackInput(),
            context: [],
        );

        $this->assertSame('NOT_FOUND', $outcome->failureCode);
    }

    public function test_already_consumed_when_provider_throws_replay(): void
    {
        $this->gateway->method('verifyResult')->willReturn(['mTxId' => 'mtx-1']);
        $this->mappingRepository->method('findChallengeIdByMtxid')->willReturn('ch-uuid-1');
        $this->identityService->method('handleProviderCallback')->willThrowException(
            new AlreadyConsumedException('ch-uuid-1', 'mtx-1'),
        );

        $outcome = $this->resolver->resolve(
            callbackInput: $this->successCallbackInput(),
            context: [],
        );

        $this->assertSame('ALREADY_CONSUMED', $outcome->failureCode);
        $this->assertSame('ch-uuid-1', $outcome->challengeId);
    }

    public function test_success_returns_token_and_challenge_id_from_verification_result(): void
    {
        $this->gateway->method('verifyResult')->willReturn(['mTxId' => 'mtx-2']);
        $this->mappingRepository->method('findChallengeIdByMtxid')->willReturn('ch-uuid-2');
        $this->identityService->method('handleProviderCallback')->willReturn(
            VerificationResult::success(
                challengeId: 'ch-uuid-2',
                providerId: InicisIdentityProvider::PROVIDER_ID,
                verifiedAt: Carbon::now(),
                identityHash: InicisIdentityHasher::hash('DI-VAL'),
                claims: ['verification_token' => 'tok-success'],
            ),
        );

        $outcome = $this->resolver->resolve(
            callbackInput: $this->successCallbackInput(),
            context: [],
        );

        $this->assertTrue($outcome->success);
        $this->assertSame('ch-uuid-2', $outcome->challengeId);
        $this->assertSame('tok-success', $outcome->verificationToken);
    }

    public function test_failure_from_verification_result_carries_core_failure_code(): void
    {
        $this->gateway->method('verifyResult')->willReturn(['mTxId' => 'mtx-3']);
        $this->mappingRepository->method('findChallengeIdByMtxid')->willReturn('ch-uuid-3');
        $this->identityService->method('handleProviderCallback')->willReturn(
            VerificationResult::failure(
                challengeId: 'ch-uuid-3',
                providerId: InicisIdentityProvider::PROVIDER_ID,
                failureCode: 'EXPIRED',
            ),
        );

        $outcome = $this->resolver->resolve(
            callbackInput: $this->successCallbackInput(),
            context: [],
        );

        $this->assertFalse($outcome->success);
        $this->assertSame('ch-uuid-3', $outcome->challengeId);
        $this->assertSame('EXPIRED', $outcome->failureCode);
    }

    /**
     * @return array<string, mixed>
     */
    private function successCallbackInput(): array
    {
        return [
            'resultCode' => '0000',
            'resultMsg' => '성공',
            'authRequestUrl' => 'https://kssa.inicis.com/api/v1/result',
            'txId' => 'tx-1',
            'token' => 'sk-1',
        ];
    }
}
