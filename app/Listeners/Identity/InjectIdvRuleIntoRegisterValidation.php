<?php

namespace App\Listeners\Identity;

use App\Contracts\Extension\HookListenerInterface;
use App\Rules\IdvTokenRule;
use App\Services\IdentityPolicyService;

/**
 * register FormRequest 의 validation rules 에 verification_token 룰을 정책 기반으로 주입합니다.
 *
 * filter 훅 `core.auth.register_validation_rules` 를 구독합니다.
 * `core.auth.signup_before_submit` 정책이 enabled 일 때만 룰을 추가합니다.
 *
 * @since 7.0.0-beta.4
 */
class InjectIdvRuleIntoRegisterValidation implements HookListenerInterface
{
    public function __construct(
        private readonly IdentityPolicyService $policyService,
    ) {}

    /**
     * 구독 훅 메타데이터.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.auth.register_validation_rules' => [
                'method' => 'filter',
                'priority' => 10,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * filter 전용 listener — handle 은 호출되지 않습니다.
     *
     * @param  mixed  ...$args
     * @return void
     */
    public function handle(...$args): void
    {
        // filter 전용 — 기본 핸들러는 미사용
    }

    /**
     * core.auth.signup_before_submit 정책 enabled 시 verification_token 룰을 추가합니다.
     *
     * @param  array<string, mixed>  $rules  기존 검증 규칙
     * @param  mixed  ...$args  추가 컨텍스트 (현재 사용 안 함)
     * @return array<string, mixed> 정책 매칭 시 verification_token 추가, 아니면 원본
     */
    public function filter(array $rules = [], ...$args): array
    {
        $policy = $this->policyService->resolve(
            scope: 'route',
            target: 'api.auth.register',
            context: ['signup_stage' => 'before_submit', 'http_method' => 'POST'],
        );

        if (! $policy || ! $policy->enabled) {
            return $rules;
        }

        $rules['verification_token'] = ['required', 'string', new IdvTokenRule('signup')];

        return $rules;
    }
}
