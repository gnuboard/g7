<?php

namespace App\Listeners\Identity;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Repositories\IdentityVerificationLogRepositoryInterface;
use App\Services\IdentityPolicyService;
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
    public function __construct(
        protected IdentityVerificationLogRepositoryInterface $logRepository,
        protected IdentityPolicyService $policyService,
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

        // 토큰의 이메일 타겟 일치 여부 검증 — consumed 여부와 무관하게 verified 상태 확인
        $log = $this->logRepository->findVerifiedForToken($token, 'signup');

        if (! $log) {
            throw new AuthorizationException(__('identity.errors.invalid_verification_token'));
        }

        // 타겟 일치 확인 (하이재킹 방지)
        $email = (string) ($data['email'] ?? '');
        if ($email !== '' && $log->target_hash !== hash('sha256', mb_strtolower($email))) {
            throw new AuthorizationException(__('identity.errors.target_mismatch'));
        }

        // 재사용 방지 — 검증된 토큰 consume (idempotent: 이미 consume 되어 있어도 OK)
        if ($log->consumed_at === null) {
            $this->logRepository->updateById($log->id, [
                'consumed_at' => now(),
            ]);
        }
    }
}
