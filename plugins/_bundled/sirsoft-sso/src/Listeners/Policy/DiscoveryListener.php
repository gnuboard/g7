<?php

namespace Plugins\Sirsoft\Sso\Listeners\Policy;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\Auth\LoginDiscoveryResult;
use Plugins\Sirsoft\Sso\Services\Policy\DiscoveryService;

/**
 * 이메일 먼저 입력 판정 — 코어 필터 `core.auth.login_discovery` 에 회사 계정 도메인을 답합니다.
 *
 * @since 1.0.0
 */
class DiscoveryListener implements HookListenerInterface
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
            'core.auth.login_discovery' => ['method' => 'discover', 'type' => 'filter', 'priority' => 20, 'sync' => true],
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
     * 판정합니다 — 앞선 확장이 이미 외부 수단으로 답했으면 그대로 둔다.
     *
     * @param  mixed  $result  앞선 판정
     * @param  string  $identifier  로그인 칸 입력
     * @param  string  $guard  로그인 경로
     * @return mixed 판정
     */
    public function discover(mixed $result = null, string $identifier = '', string $guard = 'user'): mixed
    {
        if ($result instanceof LoginDiscoveryResult && $result->method === LoginDiscoveryResult::METHOD_EXTERNAL) {
            return $result;
        }

        return $this->discovery->discover($identifier, $guard) ?? $result;
    }
}
