<?php

namespace Tests\Unit\Services;

use App\Services\NotificationRecipientCapability;
use Tests\TestCase;

/**
 * 알림 수신자 권한 판정 메모의 수명이 요청·큐 잡 하나로 끝나는지 (#599 validate).
 *
 * 메모를 `singleton` 으로 두면 큐 워커가 재시작될 때까지 판정이 굳는다 — 알림은 대부분 큐 워커에서
 * 발송되므로, 권한을 뺏긴 사람도 관리자 링크가 든 알림을 계속 받는다. Laravel 은 잡 사이에 `scoped`
 * 인스턴스만 비운다.
 */
class NotificationRecipientCapabilityScopeTest extends TestCase
{
    /**
     * 컨테이너가 scoped 로 비우면 새 인스턴스(빈 메모)를 준다.
     *
     * @scenario lifetime=queue_job
     *
     * @effects recipient_capability_memo_is_reset_between_jobs
     *
     * @return void 반환값 없음
     */
    public function test_the_memo_is_reset_when_the_scope_ends(): void
    {
        $first = $this->app->make(NotificationRecipientCapability::class);

        $this->assertSame($first, $this->app->make(NotificationRecipientCapability::class), '한 스코프 안에서는 같은 메모를 써야 한다');

        // 큐 워커가 잡마다 호출하는 것과 같은 정리
        $this->app->forgetScopedInstances();

        $this->assertNotSame($first, $this->app->make(NotificationRecipientCapability::class), '잡이 끝났는데 메모가 남았다');
    }
}
