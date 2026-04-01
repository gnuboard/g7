<?php

namespace App\Contracts\Extension;

interface HookListenerInterface
{
    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array [
     *   'hook.name' => [
     *     'method' => 'methodName',
     *     'priority' => 10
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
