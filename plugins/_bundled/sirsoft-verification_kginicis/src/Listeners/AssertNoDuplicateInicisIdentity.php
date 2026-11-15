<?php

namespace Plugins\Sirsoft\VerificationKginicis\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Services\IdentityPolicyService;
use App\Services\IdentityTokenAcceptanceService;
use Illuminate\Auth\Access\AuthorizationException;
use Plugins\Sirsoft\VerificationKginicis\Enums\InicisDuplicateField;
use Plugins\Sirsoft\VerificationKginicis\Exceptions\IdentityDuplicateException;
use Plugins\Sirsoft\VerificationKginicis\Identity\InicisIdentityProvider;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityClaimRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityLogQueryRepositoryInterface;
use Plugins\Sirsoft\VerificationKginicis\Repositories\InicisIdentityRecordRepositoryInterface;

/**
 * 가입 직전 동일인 중복 가입 차단 listener.
 *
 * 흐름 (core.auth.before_register):
 *  1. 코어 AssertIdentityVerifiedBeforeRegister (priority 10) 가 token 검증 + 1회 인정(정책이 있을 때)
 *  2. 본 listener (priority 20) 가 같은 token 을 「이니시스 결과·가입 이메일」 결박으로 인정받아(같은 요청에서 이미
 *     인정됐으면 그 결과를 돌려받는다, 1.1.4) metadata.di_hash/ci_hash 회수
 *     → `inicis_identity_records` 에서 동일 hash 매칭, 없으면 선점표(`inicis_identity_claims`)도 확인
 *     → 발견 시 IdentityDuplicateException
 *  3. 실제 선점은 가입 트랜잭션 안의 ClaimInicisIdentityOnRegistering 이 한다(동시 가입 경합 차단 — KVE-2026-3192 동형)
 *
 * 가입 정책이 이니시스를 지정했는데 다른 수단의 결과가 오면 거부한다(fail-closed, KVE-2026-3188 동형 — 1.1.4).
 *
 * 운영자 settings:
 *  - `duplicate_block_enabled` (bool, 기본 true) — false 시 동일인 판정 생략 (가족 휴대폰 공유/B2B)
 *  - `duplicate_field` ('di' | 'ci', 기본 'di') — 동일인 판정 키 선택
 *
 * 보안:
 *  - 이니시스 결과가 아니거나 가입 이메일과 결박되지 않은 결과는 인정하지 않는다 (다른 IDV provider 책임 영역 —
 *    단, 정책이 이니시스를 지정했으면 거부)
 *  - hash 가 NULL 인 경우 통과 (외국인 등 hash 발급 불가 케이스 — 의도된 통과)
 *  - 예외 메시지에 PII (이메일/이름) 미노출 — heads-up 방어
 *
 * @since 1.0.0-beta.1
 */
class AssertNoDuplicateInicisIdentity implements HookListenerInterface
{
    /** plugin 식별자 — g7_plugin_settings() 헬퍼 키 */
    protected const PLUGIN_IDENTIFIER = 'sirsoft-verification_kginicis';

    /**
     * @param  InicisIdentityLogQueryRepositoryInterface  $logQueryRepository  감사 metadata 기록
     * @param  InicisIdentityRecordRepositoryInterface  $recordRepository  inicis_identity_records 조회
     * @param  IdentityTokenAcceptanceService  $tokenAcceptance  본인인증 결과 1회 인정(코어, 1.1.4)
     * @param  IdentityPolicyService  $policyService  가입 정책 해석(코어)
     * @param  InicisIdentityClaimRepositoryInterface  $claimRepository  동일인 선점표(1.1.4)
     */
    public function __construct(
        protected readonly InicisIdentityLogQueryRepositoryInterface $logQueryRepository,
        protected readonly InicisIdentityRecordRepositoryInterface $recordRepository,
        protected readonly IdentityTokenAcceptanceService $tokenAcceptance,
        protected readonly IdentityPolicyService $policyService,
        protected readonly InicisIdentityClaimRepositoryInterface $claimRepository,
    ) {}

    /**
     * 구독 훅 메타데이터.
     *
     * priority 20 — 코어 AssertIdentityVerifiedBeforeRegister (priority 10) 직후.
     * 코어가 token 검증 + 인정을 마친 뒤 본 listener 가 동일인 검증 수행.
     *
     * @return array<string, array<string, mixed>> 훅 → 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.auth.before_register' => [
                'method' => 'handle',
                'priority' => 20,
                'sync' => true, // 인라인 가드 — 실패 시 가입 즉시 중단
            ],
        ];
    }

    /**
     * core.auth.before_register 훅 핸들러.
     *
     * @param  mixed  ...$args  [0]=array $data 가입 요청 데이터, [1]=array 문맥
     * @return void 반환값 없음
     *
     * @throws IdentityDuplicateException 동일 DI/CI hash 매칭 시
     * @throws AuthorizationException 가입 정책이 이니시스를 지정했는데 이니시스 결과가 아닐 때
     */
    public function handle(...$args): void
    {
        $data = $args[0] ?? [];
        if (! is_array($data)) {
            return;
        }
        $context = is_array($args[1] ?? null) ? $args[1] : [];

        // 1. 이니시스 결과·가입 이메일 결박으로만 인정한다 — 다른 수단의 결과·다른 이메일로 받은 결과는 null
        $token = (string) ($data['verification_token'] ?? '');
        $email = (string) ($data['email'] ?? '');
        $targetHash = $email !== '' ? hash('sha256', mb_strtolower($email)) : null;

        $log = $token !== ''
            ? $this->tokenAcceptance->accept($token, 'signup', InicisIdentityProvider::PROVIDER_ID, $targetHash)
            : null;

        if ($log === null) {
            $this->assertPolicyDoesNotRequireInicis($context);

            return;
        }

        // 2. settings 로드 — duplicate_block_enabled=false 면 동일인 판정 생략
        if (! (bool) g7_plugin_settings(self::PLUGIN_IDENTIFIER, 'duplicate_block_enabled', true)) {
            return;
        }

        // 3. settings duplicate_field 기준으로 hash 회수
        $duplicateField = InicisDuplicateField::tryFrom(
            (string) g7_plugin_settings(self::PLUGIN_IDENTIFIER, 'duplicate_field', 'di')
        ) ?? InicisDuplicateField::Di;

        $metadata = is_array($log->metadata) ? $log->metadata : [];
        [$hash, $hashColumn] = $this->extractHash($metadata, $duplicateField);

        // 4. hash 부재 시 통과 (외국인 등)
        if ($hash === null) {
            return;
        }

        // 5. 기존 record 매칭 검색
        $existing = $duplicateField === InicisDuplicateField::Di
            ? $this->recordRepository->findByDiHash($hash)
            : $this->recordRepository->findByCiHash($hash);

        if ($existing !== null) {
            // 6. 운영자 감사 추적용 — 어느 hash 컬럼으로 차단됐는지 로그 metadata 에 보존.
            //    admin 인증이력 화면이 metadata.matched_field 를 노출하면 DI/CI 식별 가능.
            //    실패해도 차단 흐름 자체는 진행 — 보조 추적이라 try/catch 로 격리.
            $this->appendAuditMetadata((string) $log->id, [
                'matched_field' => $hashColumn,
                'matched_record_id' => (int) $existing->id,
            ]);

            throw new IdentityDuplicateException($hashColumn);
        }

        // 7. records 가 아직 없는 가입 직후(경합 창)라도 선점표에 있으면 조기 안내한다(1.1.4)
        $holderId = $this->claimRepository->findHolderId($duplicateField->value, $hash);

        if ($holderId !== null) {
            $this->appendAuditMetadata((string) $log->id, [
                'matched_field' => $hashColumn,
                'matched_user_id' => $holderId,
            ]);

            throw new IdentityDuplicateException($hashColumn);
        }
    }

    /**
     * 가입 정책이 이니시스를 지정했는데 이니시스 결과를 인정하지 못했으면 거부한다 (fail-closed).
     *
     * 코어 7.1.6 의 `AssertIdentityVerifiedBeforeRegister` 도 같은 판정을 한다 — 코어 listener 가 다른 구독자에 의해
     * 빠지거나 순서가 바뀌어도 이니시스 지정 가입이 다른 수단의 결과로 통과하지 못하게 이중으로 막는다.
     *
     * @param  array<string, mixed>  $context  훅 문맥
     * @return void 반환값 없음
     *
     * @throws AuthorizationException 정책 지정 수단이 이니시스일 때
     */
    protected function assertPolicyDoesNotRequireInicis(array $context): void
    {
        $policy = $this->policyService->resolve(
            scope: 'route',
            target: 'api.auth.register',
            context: array_merge($context, ['signup_stage' => 'before_submit']),
        );

        if ($policy && $policy->enabled && $this->policyService->requiredProviderId($policy) === InicisIdentityProvider::PROVIDER_ID) {
            throw new AuthorizationException(__('identity.errors.invalid_verification_token'));
        }
    }

    /**
     * 차단 사유를 로그 metadata 에 남긴다 (보조 추적 — 실패해도 차단은 진행).
     *
     * @param  string  $logId  대상 로그 id
     * @param  array<string, mixed>  $patch  병합할 metadata 키
     * @return void 반환값 없음
     */
    protected function appendAuditMetadata(string $logId, array $patch): void
    {
        try {
            $this->logQueryRepository->appendMetadata($logId, $patch);
        } catch (\Throwable) {
            // metadata 기록 실패는 차단 의도에 영향 없음 — 무시
        }
    }

    /**
     * log.metadata 에서 duplicate_field 에 해당하는 hash 와 컬럼명을 추출한다.
     *
     * @param  array<string, mixed>  $metadata  로그 metadata
     * @param  InicisDuplicateField  $field  판정 기준
     * @return array{0: string|null, 1: string} [hash, column_name]
     */
    protected function extractHash(array $metadata, InicisDuplicateField $field): array
    {
        if ($field === InicisDuplicateField::Di) {
            $hash = $metadata['di_hash'] ?? null;

            return [is_string($hash) && $hash !== '' ? $hash : null, 'di_hash'];
        }

        $hash = $metadata['ci_hash'] ?? null;

        return [is_string($hash) && $hash !== '' ? $hash : null, 'ci_hash'];
    }
}
