<?php

namespace Plugins\Sirsoft\Sso\Services\Ldap;

use App\Contracts\Extension\ExternalCredentialVerifierInterface;
use App\Contracts\Extension\HookListenerInterface;

/**
 * LDAP/AD 로그인 검증자를 코어 외부 자격 증명 레지스트리에 등록합니다.
 *
 * 등록은 늘 하고, 실제로 쓰일지는 검증자의 `isAvailable()`(ext-ldap 과 활성 LDAP IdP)이 정한다 — 쓸 수 없으면 코어는 비밀번호 경로로
 * 간다.
 *
 * @since 1.0.0
 */
class RegisterCredentialVerifierListener implements HookListenerInterface
{
    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.auth.registered_credential_verifiers' => ['method' => 'register', 'priority' => 30, 'type' => 'filter', 'sync' => true],
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
     * 검증자를 레지스트리에 더합니다.
     *
     * @param  array<string, ExternalCredentialVerifierInterface>  $verifiers  현재 레지스트리
     * @return array<string, ExternalCredentialVerifierInterface> 더한 레지스트리
     */
    public function register(array $verifiers): array
    {
        $verifiers[SsoCredentialVerifier::ID] = app(SsoCredentialVerifier::class);

        return $verifiers;
    }
}
