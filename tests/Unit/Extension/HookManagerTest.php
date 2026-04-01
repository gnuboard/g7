<?php

namespace Tests\Unit\Extension;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * HookManager 가드 플래그 테스트
 *
 * addAction + doAction 시 콜백 중복 실행 방지를 검증합니다.
 */
class HookManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HookManager::resetAll();
    }

    protected function tearDown(): void
    {
        HookManager::resetAll();
        parent::tearDown();
    }

    /**
     * addAction + doAction 시 콜백이 정확히 1회만 실행되는지 테스트합니다.
     */
    public function test_do_action_executes_callback_exactly_once(): void
    {
        $count = 0;

        HookManager::addAction('test.guard', function () use (&$count) {
            $count++;
        });

        HookManager::doAction('test.guard');

        $this->assertEquals(1, $count, 'addAction으로 등록된 콜백이 doAction 시 정확히 1회 실행되어야 합니다.');
    }

    /**
     * 동일 훅에 여러 콜백 등록 시 각각 정확히 1회만 실행되는지 테스트합니다.
     */
    public function test_multiple_callbacks_each_execute_once(): void
    {
        $countA = 0;
        $countB = 0;

        HookManager::addAction('test.multi', function () use (&$countA) {
            $countA++;
        });

        HookManager::addAction('test.multi', function () use (&$countB) {
            $countB++;
        });

        HookManager::doAction('test.multi');

        $this->assertEquals(1, $countA, '첫 번째 콜백이 1회만 실행되어야 합니다.');
        $this->assertEquals(1, $countB, '두 번째 콜백이 1회만 실행되어야 합니다.');
    }

    /**
     * 서로 다른 훅의 가드 플래그가 독립적으로 동작하는지 테스트합니다.
     */
    public function test_dispatching_guard_is_independent_per_hook(): void
    {
        $countA = 0;
        $countB = 0;

        HookManager::addAction('test.hookA', function () use (&$countA) {
            $countA++;
        });

        HookManager::addAction('test.hookB', function () use (&$countB) {
            $countB++;
        });

        HookManager::doAction('test.hookA');
        HookManager::doAction('test.hookB');

        $this->assertEquals(1, $countA);
        $this->assertEquals(1, $countB);
    }

    /**
     * doAction을 여러 번 호출하면 각 호출마다 콜백이 1회 실행되는지 테스트합니다.
     */
    public function test_do_action_called_multiple_times(): void
    {
        $count = 0;

        HookManager::addAction('test.repeat', function () use (&$count) {
            $count++;
        });

        HookManager::doAction('test.repeat');
        HookManager::doAction('test.repeat');
        HookManager::doAction('test.repeat');

        $this->assertEquals(3, $count, 'doAction 3회 호출 시 콜백도 3회 실행되어야 합니다.');
    }

    /**
     * addAction 없이 직접 Event::listen으로 등록한 리스너가
     * doAction의 Event::dispatch를 통해 정상 실행되는지 테스트합니다.
     */
    public function test_external_event_listener_fires_via_do_action(): void
    {
        $count = 0;

        // HookManager를 거치지 않고 직접 Laravel Event로 등록
        Event::listen('hook.test.external', function () use (&$count) {
            $count++;
        });

        HookManager::doAction('test.external');

        $this->assertEquals(1, $count, '외부 Event::listen 리스너가 doAction 시 실행되어야 합니다.');
    }

    /**
     * addAction으로 등록된 콜백이 외부 Event::dispatch 호출 시에도
     * 정상 실행되는지 테스트합니다. (doAction 가드 미설정 상태)
     */
    public function test_add_action_callback_fires_via_external_event_dispatch(): void
    {
        $count = 0;

        HookManager::addAction('test.ext_dispatch', function () use (&$count) {
            $count++;
        });

        // doAction이 아닌 직접 Event::dispatch 호출
        Event::dispatch('hook.test.ext_dispatch');

        $this->assertEquals(1, $count, 'addAction 콜백이 외부 Event::dispatch 시 실행되어야 합니다.');
    }

    /**
     * doAction 시 콜백에 인수가 올바르게 전달되는지 테스트합니다.
     */
    public function test_do_action_passes_arguments(): void
    {
        $received = [];

        HookManager::addAction('test.args', function ($a, $b) use (&$received) {
            $received = [$a, $b];
        });

        HookManager::doAction('test.args', 'hello', 42);

        $this->assertEquals(['hello', 42], $received);
    }

    /**
     * 우선순위에 따라 콜백이 순서대로 실행되는지 테스트합니다.
     */
    public function test_callbacks_execute_in_priority_order(): void
    {
        $order = [];

        HookManager::addAction('test.priority', function () use (&$order) {
            $order[] = 'second';
        }, 20);

        HookManager::addAction('test.priority', function () use (&$order) {
            $order[] = 'first';
        }, 5);

        HookManager::addAction('test.priority', function () use (&$order) {
            $order[] = 'third';
        }, 30);

        HookManager::doAction('test.priority');

        $this->assertEquals(['first', 'second', 'third'], $order);
    }

    /**
     * doActionWithPermission이 가드 플래그를 올바르게 적용하는지 테스트합니다.
     * (내부에서 doAction을 호출하므로 자동 적용)
     */
    public function test_do_action_with_permission_uses_guard(): void
    {
        $count = 0;

        HookManager::addAction('test.perm_guard', function () use (&$count) {
            $count++;
        });

        // 권한 매핑이 없는 훅은 모든 사용자 허용
        HookManager::doActionWithPermission('test.perm_guard');

        $this->assertEquals(1, $count, 'doActionWithPermission도 가드가 적용되어 1회만 실행되어야 합니다.');
    }

    /**
     * resetAll이 dispatching 가드도 초기화하는지 테스트합니다.
     */
    public function test_reset_all_clears_dispatching_guard(): void
    {
        $count = 0;

        HookManager::addAction('test.reset', function () use (&$count) {
            $count++;
        });

        HookManager::doAction('test.reset');
        $this->assertEquals(1, $count);

        // resetAll 후 새로 등록해도 정상 동작
        HookManager::resetAll();

        $count = 0;
        HookManager::addAction('test.reset', function () use (&$count) {
            $count++;
        });

        HookManager::doAction('test.reset');
        $this->assertEquals(1, $count);
    }
}
