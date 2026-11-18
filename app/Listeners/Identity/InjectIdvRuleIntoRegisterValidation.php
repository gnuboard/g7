<?php

namespace App\Listeners\Identity;

use App\Contracts\Extension\HookListenerInterface;
use App\Rules\IdvTokenRule;
use App\Services\IdentityPolicyService;

/**
 * register FormRequest 의 validation rules 에 verification_token 룰을 정책 기반으로 주입합니다.
 *
 * filter 훅 `core.auth.register_validation_rules` 를 구독합니다.
 * `core.auth.signup_before_submit` 정책이 enabled 일 때 실린 토큰을 검사하는 룰을 추가합니다(만료·알 수 없는 토큰은 422).
 *
 * 토큰이 없는 가입은 룰이 아니라 입력 검증을 통과한 뒤의 정책 강제가 428 로 알린다(7.2.0, `RegisterRequest` 의
 * `DefersIdentityPolicyUntilValidated`). 필수 룰을 두면 검증이 정책보다 먼저 돌므로 토큰 없는 바른 입력이 인증 창(428) 대신
 * 칸에 속하지 않는 422 로 끝난다.
 *
 * @since 7.0.0-beta.4
 */
class InjectIdvRuleIntoRegisterValidation implements HookListenerInterface
{
    /**
     * 리스너를 생성합니다.
     *
     * @param  IdentityPolicyService  $policyService  본인인증 정책 서비스 (가입 전 정책 해석)
     */
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
     * core.auth.signup_before_submit 정책 enabled 시 verification_token 룰(실렸을 때만 검사)을 추가합니다.
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

        // 실렸을 때만 검사한다 — 없으면 검증 뒤 정책 강제가 428 로 인증을 요구한다(7.2.0, 입력 오류가 먼저).
        $rules['verification_token'] = ['nullable', 'string', 'max:255', new IdvTokenRule('signup')];

        return $rules;
    }
}
