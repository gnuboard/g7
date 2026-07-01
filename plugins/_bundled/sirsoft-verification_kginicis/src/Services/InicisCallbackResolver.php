<?php

namespace Plugins\Sirsoft\VerificationKginicis\Services;

use App\Extension\IdentityVerification\DTO\VerificationResult;
use App\Services\IdentityVerificationService;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\VerificationKginicis\Exceptions\AlreadyConsumedException;
use Plugins\Sirsoft\VerificationKginicis\Exceptions\DecryptException;
use Plugins\Sirsoft\VerificationKginicis\Exceptions\InvalidAuthUrlException;
use Plugins\Sirsoft\VerificationKginicis\Exceptions\RemoteCallException;
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisChallengeMappingRepositoryInterface;

/**
 * 이니시스 callback 의 비즈니스 로직 묶음 Service.
 *
 * Controller 가 Repository 를 직접 주입하지 않도록 (service-repository 규정) 본 Service 가
 * STEP3 호출 + mtxid 매칭 + 코어 verify 위임의 흐름 전체를 캡슐화한다.
 *
 * @since 1.0.0-beta.1
 */
class InicisCallbackResolver
{
    /**
     * @param  IdentityVerificationService  $identityService  코어 IDV Service
     * @param  InicisGatewayInterface  $gateway  STEP3 호출 + SEED 복호화
     * @param  InicisChallengeMappingRepositoryInterface  $mappingRepository  mtxid 매핑 Repository
     */
    public function __construct(
        protected readonly IdentityVerificationService $identityService,
        protected readonly InicisGatewayInterface $gateway,
        protected readonly InicisChallengeMappingRepositoryInterface $mappingRepository,
    ) {}

    /**
     * 이니시스 callback 의 STEP3 → mtxid 매칭 → verify 위임 흐름 전체를 처리한다.
     *
     * @param  array<string, mixed>  $callbackInput  이니시스 STEP2 form POST body 전체
     * @param  array<string, mixed>  $context  ip_address / user_agent
     * @return InicisCallbackOutcome 처리 결과 DTO
     */
    public function resolve(array $callbackInput, array $context = []): InicisCallbackOutcome
    {
        $resultCode = (string) ($callbackInput['resultCode'] ?? '');
        $authRequestUrl = (string) ($callbackInput['authRequestUrl'] ?? '');
        $txId = (string) ($callbackInput['txId'] ?? '');
        $token = (string) ($callbackInput['token'] ?? '');

        if ($resultCode !== '0000') {
            return InicisCallbackOutcome::failure(failureCode: $resultCode ?: 'PROVIDER_ERROR');
        }

        try {
            $step3Response = $this->gateway->verifyResult($authRequestUrl, $txId, $token);
        } catch (InvalidAuthUrlException $e) {
            Log::warning('이니시스 콜백: 위조 도메인 차단', ['url' => $e->url]);

            return InicisCallbackOutcome::failure(failureCode: 'INVALID_AUTH_URL');
        } catch (RemoteCallException $e) {
            Log::error('이니시스 STEP3 통신 실패', ['detail' => $e->detail, 'http_status' => $e->httpStatus]);

            return InicisCallbackOutcome::failure(failureCode: 'REMOTE_CALL_FAILED');
        } catch (DecryptException $e) {
            Log::error('이니시스 STEP3 SEED 복호화 실패', ['field' => $e->field]);

            return InicisCallbackOutcome::failure(failureCode: 'DECRYPT_FAILED');
        }

        $mtxid = (string) ($step3Response['mTxId'] ?? '');
        $challengeId = $mtxid !== '' ? $this->mappingRepository->findChallengeIdByMtxid($mtxid) : null;

        if ($challengeId === null) {
            Log::warning('이니시스 콜백: mTxId 매칭 실패', [
                'mtxid_hash' => $mtxid !== '' ? hash('sha256', $mtxid) : null,
            ]);

            return InicisCallbackOutcome::failure(failureCode: 'NOT_FOUND');
        }

        $input = array_merge($callbackInput, $step3Response);

        try {
            $result = $this->identityService->handleProviderCallback(
                providerId: InicisIdentityProvider::PROVIDER_ID,
                challengeId: $challengeId,
                input: $input,
                context: $context,
            );
        } catch (AlreadyConsumedException $e) {
            return InicisCallbackOutcome::failure(challengeId: $challengeId, failureCode: 'ALREADY_CONSUMED');
        }

        return $this->buildOutcomeFromResult($challengeId, $result);
    }

    /**
     * 코어 VerificationResult 를 본 plugin 의 outcome DTO 로 변환한다.
     *
     * @param  string  $challengeId  매칭된 challenge UUID
     * @param  VerificationResult  $result  코어 verify 결과
     * @return InicisCallbackOutcome
     */
    protected function buildOutcomeFromResult(string $challengeId, VerificationResult $result): InicisCallbackOutcome
    {
        if (! $result->success) {
            return InicisCallbackOutcome::failure(
                challengeId: $challengeId,
                failureCode: $result->failureCode ?? 'UNKNOWN',
            );
        }

        return InicisCallbackOutcome::success(
            challengeId: $challengeId,
            verificationToken: (string) ($result->claims['verification_token'] ?? ''),
        );
    }
}
