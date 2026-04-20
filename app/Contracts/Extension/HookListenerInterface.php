<?php

namespace App\Contracts\Extension;

interface HookListenerInterface
{
    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * Action 훅은 기본적으로 환경설정의 큐 드라이버에 따라 자동으로 큐/동기 실행됩니다.
     * 반드시 동기 실행이 필요한 경우에만 'sync' => true를 선언하세요.
     * Filter 훅은 반환값 체인이므로 항상 동기 실행됩니다.
     *
     * @return array<string, array{
     *   method?: string,
     *   priority?: int,
     *   type?: 'action'|'filter',
     *   sync?: bool,
     * }> [
     *   'hook.name' => [
     *     'method' => 'methodName',     // 실행할 메서드 (기본: 'handle')
     *     'priority' => 10,             // 실행 우선순위 (기본: 10, 낮을수록 먼저)
     *     'type' => 'action',           // 'action' 또는 'filter' (기본: 'action')
     *     'sync' => false,              // true: 큐 드라이버 무관하게 동기 실행 (기본: false)
     *   ]
     * ]
     */
    public static function getSubscribedHooks(): array;

    /**
     * 훅 이벤트를 처리합니다.
     *
     * @param mixed ...$args 훅에서 전달된 인수들
     * @return void
     */
    public function handle(...$args): void;
}
