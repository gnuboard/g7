<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Services\IdentityPolicyService;
use App\Services\IdentityTokenAcceptanceService;
use Illuminate\Auth\Access\AuthorizationException;
use Plugins\Sirsoft\VerificationNhnkcp\Enums\KcpDuplicateField;
use Plugins\Sirsoft\VerificationNhnkcp\Exceptions\IdentityDuplicateException;
use Plugins\Sirsoft\VerificationNhnkcp\Identity\KcpIdentityProvider;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityClaimRepositoryInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Repositories\KcpIdentityLogQueryRepositoryInterface;
use Plugins\Sirsoft\VerificationNhnkcp\Services\KcpDuplicateIdentityChecker;

/**
 * 가입 직전 동일인 중복 가입 차단 listener (권위 게이트).
 *
 * 흐름 (core.auth.before_register):
 *  1. 코어 AssertIdentityVerifiedBeforeRegister (priority 10) 가 토큰 검증 + 1회 인정(정책이 있을 때)
 *  2. 본 listener (priority 20) 가 같은 토큰을 「KCP 결과·가입 이메일」 결박으로 인정받아(같은 요청에서 이미 인정됐으면
 *     그 결과를 돌려받는다, 1.1.4) metadata 의 di_hash/ci_hash 회수 → 동일 hash record·선점이 있으면 IdentityDuplicateException
 *  3. 실제 선점은 가입 트랜잭션 안의 ClaimKcpIdentityOnRegistering 이 한다(동시 가입 경합 차단 — KVE-2026-3192)
 *
 * 가입 정책이 KCP 를 지정했는데 다른 수단의 결과가 오면 거부한다(fail-closed, KVE-2026-3188 동형 — 1.1.4).
 *
 * 인증 시점(KcpIdentityProvider::verify)에도 동일 판정을 수행해 사용자에게 조기 안내하지만,
 * 실제 가입을 막는 최종 게이트는 본 listener 다. 두 지점은 KcpDuplicateIdentityChecker 를
 * 공유하므로 판정 규칙이 어긋나지 않는다.
 *
 * 운영자 settings:
 *  - `duplicate_block_enabled` (bool, 기본 true) — false 면 즉시 통과 (가족 휴대폰 공유 / B2B)
 *  - `duplicate_field` ('di' | 'ci', 기본 'di') — 동일인 판정 키 선택
 *
 * @since 1.0.0
 */
class AssertNoDuplicateKcpIdentity implements HookListenerInterface
{
    /** plugin 식별자 — g7_plugin_settings() 헬퍼 키 */
    protected const PLUGIN_IDENTIFIER = 'sirsoft-verification_nhnkcp';

    /**
     * @param  KcpIdentityLogQueryRepositoryInterface  $logQueryRepository  감사 metadata 기록
     * @param  KcpDuplicateIdentityChecker  $duplicateChecker  동일인 판정 Service
     * @param  IdentityTokenAcceptanceService  $tokenAcceptance  본인인증 결과 1회 인정(코어, 1.1.4)
     * @param  IdentityPolicyService  $policyService  가입 정책 해석(코어)
     * @param  KcpIdentityClaimRepositoryInterface  $claimRepository  동일인 선점표(1.1.4)
     */
    public function __construct(
        protected readonly KcpIdentityLogQueryRepositoryInterface $logQueryRepository,
        protected readonly KcpDuplicateIdentityChecker $duplicateChecker,
        protected readonly IdentityTokenAcceptanceService $tokenAcceptance,
        protected readonly IdentityPolicyService $policyService,
        protected readonly KcpIdentityClaimRepositoryInterface $claimRepository,
    ) {}

    /**
     * 구독 훅 메타데이터.
     *
     * priority 20 — 코어 AssertIdentityVerifiedBeforeRegister (priority 10) 직후.
     *
     * @return array<string, array<string, mixed>>
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
     * @throws AuthorizationException 가입 정책이 KCP 를 지정했는데 KCP 결과가 아닐 때
     */
    public function handle(...$args): void
    {
        $data = $args[0] ?? [];
        if (! is_array($data)) {
            return;
        }
        $context = is_array($args[1] ?? null) ? $args[1] : [];

        $token = (string) ($data['verification_token'] ?? '');
        $email = (string) ($data['email'] ?? '');
        $targetHash = $email !== '' ? hash('sha256', mb_strtolower($email)) : null;

        // KCP 결과·가입 이메일 결박으로만 인정한다 — 다른 수단의 결과·다른 이메일로 받은 결과는 null
        $log = $token !== ''
            ? $this->tokenAcceptance->accept($token, 'signup', KcpIdentityProvider::PROVIDER_ID, $targetHash)
            : null;

        if ($log === null) {
            $this->assertPolicyDoesNotRequireKcp($context);

            return;
        }

        if (! (bool) g7_plugin_settings(self::PLUGIN_IDENTIFIER, 'duplicate_block_enabled', true)) {
            return;
        }

        $duplicateField = KcpDuplicateField::tryFrom(
            (string) g7_plugin_settings(self::PLUGIN_IDENTIFIER, 'duplicate_field', 'di')
        ) ?? KcpDuplicateField::Di;

        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $hash = $this->extractHash($metadata, $duplicateField);

        // hash 부재 시 통과 (식별값 미발급 케이스 — 의도된 통과)
        if ($hash === null) {
            return;
        }

        $conflictColumn = $this->duplicateChecker->findConflictColumn($duplicateField, $hash)
            // records 가 아직 없는 가입 직후(경합 창)라도 선점표에 있으면 조기 안내한다(1.1.4)
            ?? ($this->claimRepository->findHolderId($duplicateField->value, $hash) !== null
                ? $this->duplicateChecker->columnFor($duplicateField)
                : null);

        if ($conflictColumn === null) {
            return;
        }

        // 운영자 감사 추적용 — 어느 기준으로 차단됐는지 로그 metadata 에 보존한다.
        // 보조 추적이므로 실패해도 차단 흐름은 그대로 진행한다.
        try {
            $this->logQueryRepository->appendMetadata((string) $log->id, [
                'matched_field' => $conflictColumn,
                'matched_user_id' => $this->duplicateChecker->findConflictUserId($duplicateField, $hash),
            ]);
        } catch (\Throwable $e) {
            // metadata 기록 실패는 차단 의도에 영향 없음 — 무시
        }

        throw new IdentityDuplicateException($conflictColumn);
    }

    /**
     * 가입 정책이 KCP 를 지정했는데 KCP 결과를 인정하지 못했으면 거부한다 (fail-closed).
     *
     * 코어 7.1.6 의 `AssertIdentityVerifiedBeforeRegister` 도 같은 판정을 한다 — 코어 listener 가 다른 구독자에 의해
     * 빠지거나 순서가 바뀌어도 KCP 지정 가입이 다른 수단의 결과로 통과하지 못하게 이중으로 막는다.
     *
     * @param  array<string, mixed>  $context  훅 문맥
     * @return void 반환값 없음
     *
     * @throws AuthorizationException 정책 지정 수단이 KCP 일 때
     */
    protected function assertPolicyDoesNotRequireKcp(array $context): void
    {
        $policy = $this->policyService->resolve(
            scope: 'route',
            target: 'api.auth.register',
            context: array_merge($context, ['signup_stage' => 'before_submit']),
        );

        if ($policy && $policy->enabled && $this->policyService->requiredProviderId($policy) === KcpIdentityProvider::PROVIDER_ID) {
            throw new AuthorizationException(__('identity.errors.invalid_verification_token'));
        }
    }

    /**
     * log.metadata 에서 duplicate_field 에 해당하는 hash 를 추출한다.
     *
     * @param  array<string, mixed>  $metadata  로그 metadata
     * @param  KcpDuplicateField  $field  판정 기준
     * @return string|null hash 또는 null
     */
    protected function extractHash(array $metadata, KcpDuplicateField $field): ?string
    {
        $key = $field === KcpDuplicateField::Di ? 'di_hash' : 'ci_hash';
        $hash = $metadata[$key] ?? null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }
}
