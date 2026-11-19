<?php

namespace Plugins\Sirsoft\Sso\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Contracts\Extension\IdentityVerificationInterface;
use Plugins\Sirsoft\Sso\Identity\SsoReauthIdentityProvider;

/**
 * 「회사 계정으로 재확인」 본인인증 수단을 코어 본인인증 레지스트리에 등록합니다.
 *
 * 민감 작업 재확인(목적 `sensitive_action`)만 지원하고, 회원별 판정(`canChallengeUser`)이 활성 SSO 연결 보유 회원으로 좁힌다. 코어 기본
 * 수단(메일)이 모든 목적을 지원하므로 다른 회원의 인증 흐름은 바뀌지 않는다.
 *
 * 우선순위 25 — 소셜 로그인 플러그인의 재확인 수단(30)보다 먼저 등록한다. 코어는 메일을 받을 수 없는 회원에게 등록 순서상 쓸 수 있는 첫
 * 수단을 고르므로, 회사 계정과 소셜 계정이 둘 다 연결된 회원의 「본인 확인 계속하기」는 회사 계정 다시 로그인으로 간다.
 *
 * @since 1.0.0
 */
class RegisterSsoReauthProviderListener implements HookListenerInterface
{
    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.identity.registered_providers' => ['method' => 'register', 'priority' => 25, 'type' => 'filter', 'sync' => true],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 수단을 레지스트리에 더합니다.
     *
     * @param  array<string, IdentityVerificationInterface>  $providers  현재 레지스트리
     * @return array<string, IdentityVerificationInterface> 더한 레지스트리
     */
    public function register(array $providers): array
    {
        $providers[SsoReauthIdentityProvider::PROVIDER_ID] = app(SsoReauthIdentityProvider::class);

        return $providers;
    }
}
