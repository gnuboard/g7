<?php

namespace Plugins\Sirsoft\Sso\Listeners\Admin;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookManager;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\AdminLogRepositoryInterface;
use Plugins\Sirsoft\Sso\Services\Policy\PolicyService;

/**
 * IdP 연속 실패 감시(W16) — 플러그인 액션 `sirsoft-sso.auth.login_failed` 를 받아 기준에 닿으면 `sirsoft-sso.idp.failing` 을 하루
 * 한 번 쏜다(알림 `sso_idp_failures`).
 *
 * IdP 장애만 센다 — 결과가 `failed` 인 것. 정책 거절(`denied` — 미등록, 퇴사 등)은 IdP 가 정상 동작한 것이라 세지 않는다.
 * 기준은 정책의 `failure_alert_count` 회 / `failure_alert_window_minutes` 분(기본 5회 / 10분).
 *
 * @since 1.0.0
 */
class IdpFailureMonitorListener implements HookListenerInterface
{
    /** 알림 훅 */
    public const ACTION_FAILING = 'sirsoft-sso.idp.failing';

    /** 같은 IdP 알림 간격(초) — 하루 한 번 */
    public const NOTIFY_INTERVAL_SECONDS = 86400;

    /**
     * @param  AdminLogRepositoryInterface  $logs  시도 이력
     * @param  PolicyService  $policy  알림 기준
     * @param  CacheInterface  $cache  플러그인 캐시 (하루 한 번 표시)
     */
    public function __construct(
        private readonly AdminLogRepositoryInterface $logs,
        private readonly PolicyService $policy,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-sso.auth.login_failed' => ['method' => 'onFailed', 'priority' => 60, 'sync' => true],
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
     * 실패 1건을 받아 기준을 봅니다.
     *
     * @param  mixed  $idp  IdP (없는 키면 null)
     * @param  string  $errorCode  오류 코드
     * @param  string  $intent  의도
     * @param  string  $guard  로그인 경로
     * @param  string  $outcome  failed, denied
     * @return void 반환값 없음
     */
    public function onFailed(mixed $idp = null, string $errorCode = '', string $intent = 'login', string $guard = 'user', string $outcome = 'failed'): void
    {
        if (! $idp instanceof IdentityProvider || $outcome !== 'failed') {
            return;
        }

        $threshold = (int) $this->policy->get('failure_alert_count', 5);
        $window = (int) $this->policy->get('failure_alert_window_minutes', 10);
        $count = $this->logs->recentFailureCount((int) $idp->id, $window);

        if ($count < $threshold) {
            return;
        }

        $key = 'idp_failing_notified:'.$idp->id;
        if ($this->cache->has($key)) {
            return;
        }

        $this->cache->put($key, true, self::NOTIFY_INTERVAL_SECONDS);

        HookManager::doAction(self::ACTION_FAILING, $idp, $count, $errorCode);
    }
}
