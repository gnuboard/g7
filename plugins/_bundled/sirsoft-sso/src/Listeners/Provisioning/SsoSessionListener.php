<?php

namespace Plugins\Sirsoft\Sso\Listeners\Provisioning;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Services\SessionService;

/**
 * SSO 세션 기록과 로그아웃 범위를 코어 로그인, 로그아웃 훅에 잇습니다.
 *
 *  - 인계 문맥 필터: `sso_sessions` 행을 만들고 세션 참조(`external_ref`)와 세션 수명 상한(`max_session_minutes`)을 싣는다.
 *  - 로그인 완료: 이번 토큰 id 를 그 세션에 붙인다 (`login_method` 가 `sso:` 로 시작할 때만).
 *  - 로그아웃 이동 필터: 토큰을 지우기 전에 그 세션을 찾고, IdP 로그아웃이 필요하면 1회용 티켓 주소를 돌려준다.
 *  - 로그아웃 완료: 그 세션을 끝낸다.
 *
 * 세션 서비스는 요청 단위 싱글턴이다 — 로그아웃 필터가 찾은 세션을 같은 요청의 로그아웃 완료 훅이 이어받는다.
 *
 * @since 1.0.0
 */
class SsoSessionListener implements HookListenerInterface
{
    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-sso.handoff.filter_context' => ['method' => 'handoffContext', 'priority' => 20, 'type' => 'filter', 'sync' => true],
            'core.auth.after_login' => ['method' => 'afterLogin', 'priority' => 20, 'sync' => true],
            'core.auth.logout_redirect_url' => ['method' => 'logoutRedirect', 'priority' => 20, 'type' => 'filter', 'sync' => true],
            'core.auth.logout' => ['method' => 'afterLogout', 'priority' => 20, 'sync' => true],
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
     * 인계 문맥에 세션 참조와 수명 상한을 싣습니다.
     *
     * @param  mixed  $context  인계 문맥
     * @param  mixed  ...$args  [User, SsoAccount, ExternalIdentity, IdentityProvider]
     * @return mixed 문맥
     */
    public function handoffContext(mixed $context, mixed ...$args): mixed
    {
        [$user, $account, $identity, $idp] = array_pad($args, 4, null);
        if (! is_array($context) || ! $user instanceof User || ! $account instanceof SsoAccount
            || ! $identity instanceof ExternalIdentity || ! $idp instanceof IdentityProvider) {
            return $context;
        }

        return app(SessionService::class)->handoffContext($context, $user, $account, $identity, $idp);
    }

    /**
     * 로그인 완료 뒤 세션에 토큰을 붙입니다.
     *
     * @param  mixed  ...$args  [User, array $context]
     * @return void
     */
    public function afterLogin(mixed ...$args): void
    {
        $user = $args[0] ?? null;
        $context = $args[1] ?? null;
        if ($user instanceof User && is_array($context)) {
            app(SessionService::class)->onAfterLogin($user, $context);
        }
    }

    /**
     * 로그아웃 뒤 갈 곳을 정합니다.
     *
     * @param  mixed  $url  앞선 필터 결과
     * @param  mixed  ...$args  [User, array{guard, token_id}]
     * @return mixed 같은 출처 경로 또는 앞선 값
     */
    public function logoutRedirect(mixed $url, mixed ...$args): mixed
    {
        $user = $args[0] ?? null;
        $meta = $args[1] ?? [];
        if (! $user instanceof User) {
            return $url;
        }

        return app(SessionService::class)->logoutRedirect($url, $user, is_array($meta) ? $meta : []);
    }

    /**
     * 로그아웃이 끝나면 세션을 끝냅니다.
     *
     * @param  mixed  ...$args  [User]
     * @return void
     */
    public function afterLogout(mixed ...$args): void
    {
        $user = $args[0] ?? null;
        if ($user instanceof User) {
            app(SessionService::class)->onLogout($user);
        }
    }
}
