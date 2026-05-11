<?php

namespace App\Listeners\Identity;

use App\Contracts\Extension\HookListenerInterface;
use App\Enums\IdentityOriginType;
use App\Models\User;
use App\Services\IdentityPolicyService;
use App\Services\IdentityVerificationService;
use Illuminate\Support\Facades\Log;

/**
 * 가입 직후 비활성 상태 유저에게 IDV challenge 를 정책 기반으로 발행합니다.
 *
 * after_register 훅에 구독되며, `core.auth.signup_after_create` 정책이 enabled 일 때만 동작합니다.
 * challenge 발행은 sync 로 수행되어야 DTO 가 손실되지 않으므로 sync=true.
 *
 * @since 7.0.0-beta.4
 */
class InitiateIdentityChallengeAfterRegister implements HookListenerInterface
{
    public function __construct(
        protected IdentityVerificationService $service,
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
            'core.auth.after_register' => [
                'method' => 'handle',
                'priority' => 5, // 다른 after_register listener 보다 먼저 실행
                'sync' => true,
            ],
        ];
    }

    /**
     * core.auth.after_register 훅 핸들러.
     *
     * @param  mixed  ...$args  [0]=User $user, [1]=array $context (signup_stage, ip_address 등)
     * @return void
     */
    public function handle(...$args): void
    {
        $user = $args[0] ?? null;
        $context = is_array($args[1] ?? null) ? $args[1] : [];

        if (! $user instanceof User) {
            return;
        }

        $policy = $this->policyService->resolve(
            scope: 'hook',
            target: 'core.auth.after_register',
            context: array_merge($context, ['signup_stage' => 'after_create']),
        );

        if (! $policy || ! $policy->enabled) {
            return;
        }

        try {
            $this->service->start(
                purpose: 'signup',
                target: $user,
                context: [
                    'ip_address' => $context['ip_address'] ?? null,
                    'user_agent' => $context['user_agent'] ?? null,
                    'origin_type' => IdentityOriginType::System->value,
                    'origin_identifier' => 'core.auth.after_register',
                    'origin_policy_key' => $policy->key,
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('[IDV] signup_after_create challenge 발행 실패', [
                'user_id' => $user->id,
                'policy_key' => $policy->key,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
