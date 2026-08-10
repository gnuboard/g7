<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Services;

use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Extension\IdentityVerification\DTO\VerificationResult;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Services\IdentityVerificationService;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\VerificationNhnkcp\Exceptions\AlreadyConsumedException;
use Plugins\Sirsoft\VerificationNhnkcp\Exceptions\DecryptException;
use Plugins\Sirsoft\VerificationNhnkcp\Exceptions\RemoteCallException;
use Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Models\KcpCertTransaction;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpCertTransactionRepositoryInterface;

/**
 * KCP 콜백 판정 파이프라인 Service.
 *
 * Controller 가 Repository/게이트웨이를 직접 다루지 않도록(service-repository 규정) 콜백 처리
 * 전 과정을 본 Service 가 캡슐화한다. 판정 순서는 고정이며 어떤 분기든 outcome DTO 로 끝난다:
 *
 *   1. 거래 식별 — 콜백의 거래키(우선) 또는 param_opt_1 의 challenge_id(폴백)
 *   2. 1회 소비 마킹 — 이미 소비된 거래는 ALREADY_CONSUMED (replay 차단)
 *   3. res_cd != 0000 — 취소(9999)/실패를 코어 verify 에 그대로 위임해 로그 상태를 남긴다
 *   4. 결과조회 실호출 — 통신 실패 REMOTE_CALL_FAILED / 복호화 실패 DECRYPT_FAILED
 *   5. 코어 handleProviderCallback 위임 — 신원 가드/성인/중복/저장/토큰 발급은 Provider 책임
 *
 * @since 1.0.0
 */
class KcpCallbackResolver
{
    /**
     * 코어 verify 에 닿지 못하고 여기서 끝나는 실패 상태 목록.
     *
     * 이 상태들은 로그를 갱신하지 않으면 인증 이력 화면에 "보냄(sent)" 으로 남아, 사용자가
     * 인증창을 열어 두고 그냥 떠난 경우와 구분되지 않는다. 거래는 이미 소비된 뒤라 되돌릴 수도
     * 없으므로, 운영자가 원인을 볼 수 있도록 실패로 확정한다.
     */
    protected const TERMINAL_LOG_STATUSES = ['verified', 'failed', 'cancelled', 'expired'];

    /**
     * @param  IdentityVerificationService  $identityService  코어 IDV Service
     * @param  IdentityVerificationManager  $providerManager  코어 provider 레지스트리 (설정 주입된 인스턴스 조회)
     * @param  KcpCertTransactionRepositoryInterface  $transactionRepository  거래 매핑 Repository
     * @param  IdentityVerificationLogRepositoryInterface  $logRepository  코어 인증 로그 Repository
     */
    public function __construct(
        protected readonly IdentityVerificationService $identityService,
        protected readonly IdentityVerificationManager $providerManager,
        protected readonly KcpCertTransactionRepositoryInterface $transactionRepository,
        protected readonly IdentityVerificationLogRepositoryInterface $logRepository,
    ) {}

    /**
     * 코어 verify 에 닿지 못한 실패를 로그에 남긴다.
     *
     * 이미 확정된 로그(성공·실패·취소·만료)는 건드리지 않는다 — 늦게 도착한 중복 콜백이
     * 성공 기록을 실패로 덮어쓰는 것을 막기 위함이다.
     *
     * @param  string  $challengeId  challenge UUID
     * @param  string  $failureCode  실패 사유 코드
     * @return KcpCallbackOutcome 동일한 실패 결과 (호출부에서 그대로 반환)
     */
    protected function failWithLog(string $challengeId, string $failureCode): KcpCallbackOutcome
    {
        $log = $this->logRepository->findById($challengeId);

        // status 는 코어 모델에서 enum 으로 캐스팅된다 — 문자열 비교를 위해 값을 꺼낸다.
        $status = $log?->status;
        $status = $status instanceof \BackedEnum ? (string) $status->value : (string) $status;

        if ($log !== null && ! in_array($status, self::TERMINAL_LOG_STATUSES, true)) {
            $this->logRepository->updateById($challengeId, [
                'status' => 'failed',
                'metadata' => array_merge((array) $log->metadata, ['failure_code' => $failureCode]),
            ]);
        }

        return KcpCallbackOutcome::failure(challengeId: $challengeId, failureCode: $failureCode);
    }

    /**
     * KCP 콜백 body 를 받아 판정 파이프라인 전체를 수행한다.
     *
     * @param  array<string, mixed>  $callbackInput  KCP 표준창이 form POST 한 body 전체
     * @param  array<string, mixed>  $context  ip_address / user_agent
     * @return KcpCallbackOutcome 처리 결과 DTO
     */
    public function resolve(array $callbackInput, array $context = []): KcpCallbackOutcome
    {
        $resCd = trim((string) ($callbackInput['res_cd'] ?? ''));
        $resMsg = urldecode(trim((string) ($callbackInput['res_msg'] ?? '')));

        $transaction = $this->findTransaction($callbackInput);

        if ($transaction === null) {
            Log::warning('KCP 콜백: 거래 매칭 실패', ['res_cd' => $resCd]);

            return KcpCallbackOutcome::failure(failureCode: 'NOT_FOUND');
        }

        $challengeId = (string) $transaction->challenge_id;

        // 1회 소비 — 동시 진입/replay 는 여기서 걸러진다 (원자적 조건부 UPDATE).
        if (! $this->transactionRepository->markConsumed((int) $transaction->id)) {
            return $this->failWithLog($challengeId, 'ALREADY_CONSUMED');
        }

        // 사용자 취소(9999) 또는 표준창 실패 — 결과조회 없이 코어 verify 에 위임해 로그 상태를 남긴다.
        if ($resCd !== KcpCertClient::RES_CD_SUCCESS) {
            return $this->delegateToCore($challengeId, [
                'res_cd' => $resCd !== '' ? $resCd : 'PROVIDER_ERROR',
                'res_msg' => $resMsg,
            ], $context);
        }

        $regCertKey = $this->transactionRepository->decryptRegCertKey($transaction);
        if ($regCertKey === '') {
            return $this->failWithLog($challengeId, 'DECRYPT_FAILED');
        }

        try {
            $certData = $this->client()->fetchCertData($regCertKey, (string) $transaction->ordr_idxx);
        } catch (RemoteCallException $e) {
            Log::error('KCP 결과조회 통신 실패', [
                'challenge_id' => $challengeId,
                'detail' => $e->detail,
                'http_status' => $e->httpStatus,
            ]);

            return $this->failWithLog($challengeId, 'REMOTE_CALL_FAILED');
        } catch (DecryptException $e) {
            Log::error('KCP 결과조회 복호화 실패', [
                'challenge_id' => $challengeId,
                'field' => $e->field,
            ]);

            return $this->failWithLog($challengeId, 'DECRYPT_FAILED');
        }

        // 결과조회 자체가 거절된 경우 — 그 코드를 그대로 실패 사유로 전달한다.
        $certResCd = (string) ($certData['res_cd'] ?? '');
        if ($certResCd !== KcpCertClient::RES_CD_SUCCESS) {
            Log::warning('KCP 결과조회 거절', [
                'challenge_id' => $challengeId,
                'res_cd' => $certResCd,
            ]);

            return $this->delegateToCore($challengeId, [
                'res_cd' => $certResCd !== '' ? $certResCd : 'PROVIDER_ERROR',
                'res_msg' => (string) ($certData['res_msg'] ?? ''),
            ], $context);
        }

        return $this->delegateToCore($challengeId, $certData, $context);
    }

    /**
     * 콜백 body 로 거래 행을 식별한다.
     *
     * KCP 가이드상 콜백에는 거래키가 포함되지만, 계약/버전에 따라 누락될 수 있으므로 거래등록
     * 시 `param_opt_1` 에 실어 보낸 challenge_id 를 폴백 인덱스로 사용한다.
     *
     * @param  array<string, mixed>  $callbackInput  콜백 body
     * @return KcpCertTransaction|null 거래 행 또는 null
     */
    protected function findTransaction(array $callbackInput): ?KcpCertTransaction
    {
        $regCertKey = trim((string) ($callbackInput['reg_cert_key'] ?? ''));

        if ($regCertKey !== '') {
            $transaction = $this->transactionRepository->findByRegCertKey($regCertKey);
            if ($transaction !== null) {
                return $transaction;
            }
        }

        $challengeId = trim((string) ($callbackInput['param_opt_1'] ?? ''));

        return $challengeId !== ''
            ? $this->transactionRepository->findByChallengeId($challengeId)
            : null;
    }

    /**
     * 코어 IDV Service 에 verify 를 위임하고 결과를 outcome 으로 변환한다.
     *
     * @param  string  $challengeId  challenge UUID
     * @param  array<string, mixed>  $input  provider verify 입력 (콜백 + 결과조회 합산)
     * @param  array<string, mixed>  $context  ip_address / user_agent
     * @return KcpCallbackOutcome 코어 verify 결과를 변환한 판정 결과
     */
    protected function delegateToCore(string $challengeId, array $input, array $context): KcpCallbackOutcome
    {
        try {
            $result = $this->identityService->handleProviderCallback(
                providerId: KcpIdentityProvider::PROVIDER_ID,
                challengeId: $challengeId,
                input: $input,
                context: $context,
            );
        } catch (AlreadyConsumedException) {
            // 코어가 이미 소비 판정을 내린 경우 — 로그 상태는 코어가 확정했으므로 그대로 둔다.
            return KcpCallbackOutcome::failure(challengeId: $challengeId, failureCode: 'ALREADY_CONSUMED');
        }

        return $this->buildOutcomeFromResult($challengeId, $result);
    }

    /**
     * 코어 VerificationResult 를 본 plugin 의 outcome DTO 로 변환한다.
     *
     * @param  string  $challengeId  challenge UUID
     * @param  VerificationResult  $result  코어 verify 결과
     * @return KcpCallbackOutcome 브리지 전달용으로 변환된 판정 결과
     */
    protected function buildOutcomeFromResult(string $challengeId, VerificationResult $result): KcpCallbackOutcome
    {
        if (! $result->success) {
            return KcpCallbackOutcome::failure(
                challengeId: $challengeId,
                failureCode: $result->failureCode ?? 'UNKNOWN',
                failureMessage: $this->extractProviderMessage($result->failureReason),
            );
        }

        return KcpCallbackOutcome::success(
            challengeId: $challengeId,
            verificationToken: (string) ($result->claims['verification_token'] ?? ''),
        );
    }

    /**
     * 실패 사유에서 "사용자에게 그대로 보여줄 KCP 원문 메시지" 만 추출한다.
     *
     * Provider 가 우리말 안내를 가진 실패(성인 미달·중복 등)에는 다국어 키를 실어 보내므로, 그
     * 경우는 프론트가 키로 안내한다. 키가 아닌 문자열(=KCP 가 준 원문)일 때만 그대로 전달해
     * `[코드] 메시지` 형태로 노출한다 (§3.7).
     *
     * @param  string|null  $failureReason  코어 verify 결과의 실패 사유
     * @return string|null KCP 원문 메시지 또는 null (다국어 키인 경우)
     */
    protected function extractProviderMessage(?string $failureReason): ?string
    {
        $reason = trim((string) $failureReason);

        if ($reason === '' || str_contains($reason, '::')) {
            return null;
        }

        return $reason;
    }

    /**
     * 현재 운영 설정이 주입된 KCP 게이트웨이를 반환한다.
     *
     * 코어 레지스트리에 등록된 provider 인스턴스(RegisterKcpProviderListener 가 settings 를 주입)
     * 에서 게이트웨이를 얻어, 사이트코드/암호화 키 해석 로직이 한 곳에만 존재하도록 한다.
     *
     * @return KcpCertClientInterface 현재 설정의 자격증명이 주입된 게이트웨이
     */
    protected function client(): KcpCertClientInterface
    {
        $provider = $this->providerManager->get(KcpIdentityProvider::PROVIDER_ID);

        if ($provider instanceof KcpIdentityProvider) {
            return $provider->client();
        }

        // 레지스트리에 본 provider 가 없는 예외적 상황 — 자격증명 없는 게이트웨이는 호출 즉시
        // 실패하므로 상위에서 REMOTE_CALL_FAILED 로 처리된다.
        return app(KcpCertClientInterface::class);
    }
}
