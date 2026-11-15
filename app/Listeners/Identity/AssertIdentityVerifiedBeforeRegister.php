<?php

namespace App\Listeners\Identity;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Services\IdentityPolicyService;
use App\Services\IdentityTokenAcceptanceService;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * 가입 직전 동기 검증 가드 — verification_token 의 유효성을 정책 기반으로 강제합니다.
 *
 * 1. core.auth.signup_before_submit 정책이 enabled 인 경우만 동작
 * 2. verification_token 파싱 → challenge 조회
 * 3. purpose='signup' && status='verified' && target_hash == SHA256(email) 확인
 * 4. 실패 시 AuthorizationException throw → 가입 중단
 * 5. 성공 시 challenge 를 consume(재사용 방지)
 *
 * @since 7.0.0-beta.4
 */
class AssertIdentityVerifiedBeforeRegister implements HookListenerInterface
{
    /**
     * @param  IdentityVerificationLogRepositoryInterface  $logRepository  본인인증 이력 Repository (하위 호환)
     * @param  IdentityPolicyService  $policyService  정책 해석 Service
     * @param  IdentityTokenAcceptanceService  $tokenAcceptance  본인인증 결과 인정(결박·1회 소비) Service — 7.1.6
     */
    public function __construct(
        protected IdentityVerificationLogRepositoryInterface $logRepository,
        protected IdentityPolicyService $policyService,
        protected IdentityTokenAcceptanceService $tokenAcceptance,
    ) {}

    /**
     * 구독 훅 메타데이터.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.auth.before_register' => [
                'method' => 'handle',
                'priority' => 10,
                'sync' => true, // 인라인 가드 — 실패 시 예외로 가입을 즉시 중단해야 함
            ],
        ];
    }

    /**
     * core.auth.before_register 훅 핸들러.
     *
     * @param  mixed  ...$args  [0]=array $data 가입 요청 데이터, [1]=array $context (signup_stage 등)
     * @return void
     *
     * @throws AuthorizationException 정책 enabled 이고 토큰 무효/타겟 불일치 시
     */
    public function handle(...$args): void
    {
        $data = $args[0] ?? [];
        $context = is_array($args[1] ?? null) ? $args[1] : [];

        $policy = $this->policyService->resolve(
            scope: 'route',
            target: 'api.auth.register',
            context: array_merge($context, ['signup_stage' => 'before_submit']),
        );

        if (! $policy || ! $policy->enabled) {
            return;
        }

        $token = (string) ($data['verification_token'] ?? '');
        if ($token === '') {
            throw new AuthorizationException(__('identity.errors.invalid_verification_token'));
        }

        $email = (string) ($data['email'] ?? '');
        $targetHash = $email !== '' ? hash('sha256', mb_strtolower($email)) : null;
        $requiredProviderId = $this->policyService->requiredProviderId($policy);

        // 목적·지정 수단·가입 이메일이 맞는 결과만, 한 번만 인정한다 — 조회 뒤 조건 없는 소비로는 같은 결과로
        // 동시에 두 번 가입할 수 있었다(KVE 동형 O4, 7.1.6). 같은 요청에서 정책 미들웨어가 이미 인정했으면 다시 인정한다.
        $log = $this->tokenAcceptance->accept($token, 'signup', $requiredProviderId, $targetHash);

        if ($log !== null) {
            return;
        }

        // 거절 사유 구분 — 결과는 있는데 다른 이메일로 받은 것이면 대상 불일치 문구
        if ($targetHash !== null && $this->tokenAcceptance->peek($token, 'signup', $requiredProviderId) !== null) {
            throw new AuthorizationException(__('identity.errors.target_mismatch'));
        }

        throw new AuthorizationException(__('identity.errors.invalid_verification_token'));
    }
}
