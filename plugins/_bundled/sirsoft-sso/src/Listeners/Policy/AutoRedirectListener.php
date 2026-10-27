<?php

namespace Plugins\Sirsoft\Sso\Listeners\Policy;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\Sso\Services\Policy\DiscoveryService;

/**
 * 로그인 화면 자동 이동 — 코어 필터 `core.auth.login_auto_redirect` 에 사이트 전체 강제의 자동 이동 IdP 경로를 답합니다.
 *
 * @since 1.0.0
 */
class AutoRedirectListener implements HookListenerInterface
{
    /**
     * @param  DiscoveryService  $discovery  판정 서비스
     */
    public function __construct(private readonly DiscoveryService $discovery) {}

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.auth.login_auto_redirect' => ['method' => 'redirect', 'type' => 'filter', 'priority' => 20, 'sync' => true],
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
     * 자동 이동 경로 — 앞선 확장이 이미 답했으면 그대로 둔다.
     *
     * @param  mixed  $path  앞선 경로
     * @param  string  $guard  로그인 경로
     * @return mixed 경로 또는 null
     */
    public function redirect(mixed $path = null, string $guard = 'user'): mixed
    {
        if (is_string($path) && $path !== '') {
            return $path;
        }

        return $this->discovery->autoRedirect($guard);
    }
}
