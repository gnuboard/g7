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
            'core.identity.registered_providers' => ['method' => 'register', 'priority' => 30, 'type' => 'filter', 'sync' => true],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
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
