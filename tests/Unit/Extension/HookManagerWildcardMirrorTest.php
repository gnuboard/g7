<?php

namespace Tests\Unit\Extension;

use App\Extension\HookManager;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 훅 액션의 Laravel 이벤트 미러 — 콜백마다 리스너 대신 디스패처당 와일드카드 1개 (#860 W5)
 *
 * 외부 `Event::listen('hook.x')`·`Event::dispatch('hook.x')` 계약과 「액션 정확히 1회」(2026-02-15 이중 실행 사고의
 * `$dispatching` 가드)를 그대로 지키면서, 액션 등록 수만큼 쌓이던 이름 리스너를 없앤다.
 */
class HookManagerWildcardMirrorTest extends TestCase
{
    /**
     * 훅 정적 상태를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        HookManager::resetAll();
    }

    /**
     * 훅 정적 상태를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::resetAll();
        parent::tearDown();
    }

    /**
     * 같은 훅에 액션을 여러 개 등록해도 그 훅 이벤트의 리스너는 와일드카드 1개뿐입니다.
     *
     * @effects action_mirror_uses_single_wildcard_listener
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 액션_n개여도_리스너는_와일드카드_1개다(): void
    {
        foreach (range(1, 3) as $i) {
            HookManager::addAction('w5.single', fn () => null);
            HookManager::addAction("w5.other{$i}", fn () => null);
        }

        $this->assertCount(1, Event::getListeners('hook.w5.single'));
        $this->assertCount(1, Event::getListeners('hook.w5.other2'));
        $this->assertArrayNotHasKey('hook.w5.single', Event::getRawListeners(), '이름 리스너가 남으면 안 된다');
    }

    /**
     * 외부에서 훅 이벤트를 직접 보내면 등록된 액션이 같은 인자로 정확히 1회, 우선순위 순으로 실행됩니다.
     *
     * @effects external_dispatch_runs_registered_action_exactly_once
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 외부_dispatch_는_액션을_정확히_1회_실행한다(): void
    {
        $calls = [];
        HookManager::addAction('w5.ext', function ($a, $b) use (&$calls) {
            $calls[] = "late:{$a}:{$b}";
        }, 20);
        HookManager::addAction('w5.ext', function ($a, $b) use (&$calls) {
            $calls[] = "early:{$a}:{$b}";
        }, 5);

        Event::dispatch('hook.w5.ext', [1, 2]);

        $this->assertSame(['early:1:2', 'late:1:2'], $calls);
    }

    /**
     * doAction 은 액션 콜백과 외부 리스너를 각각 정확히 1회 실행합니다.
     *
     * @effects do_action_runs_each_callback_exactly_once
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function do_action_은_콜백과_외부_리스너를_각_1회_실행한다(): void
    {
        $callback = 0;
        $external = 0;
        HookManager::addAction('w5.do', function () use (&$callback) {
            $callback++;
        });
        Event::listen('hook.w5.do', function () use (&$external) {
            $external++;
        });

        HookManager::doAction('w5.do');

        $this->assertSame(1, $callback);
        $this->assertSame(1, $external);
    }

    /**
     * removeAction 은 그 콜백만 지우고 같은 훅의 외부 리스너와 다른 액션은 남깁니다.
     *
     * @effects remove_action_preserves_external_listeners
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function remove_action_은_외부_리스너를_보존한다(): void
    {
        $removed = 0;
        $kept = 0;
        $external = 0;
        $remove = function () use (&$removed) {
            $removed++;
        };
        HookManager::addAction('w5.remove', $remove);
        HookManager::addAction('w5.remove', function () use (&$kept) {
            $kept++;
        });
        Event::listen('hook.w5.remove', function () use (&$external) {
            $external++;
        });

        HookManager::removeAction('w5.remove', $remove);
        Event::dispatch('hook.w5.remove');
        HookManager::doAction('w5.remove');

        $this->assertSame(0, $removed);
        $this->assertSame(2, $kept, '외부 dispatch 1 + doAction 1');
        $this->assertSame(2, $external, '외부 dispatch 1 + doAction 1');
    }

    /**
     * clearAction 은 그 훅의 액션과 외부 리스너를 모두 지웁니다(현행 「전부 지움」).
     *
     * @effects clear_action_forgets_all_listeners
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function clear_action_은_모든_리스너를_지운다(): void
    {
        $callback = 0;
        $external = 0;
        HookManager::addAction('w5.clear', function () use (&$callback) {
            $callback++;
        });
        Event::listen('hook.w5.clear', function () use (&$external) {
            $external++;
        });

        HookManager::clearAction('w5.clear');
        Event::dispatch('hook.w5.clear');
        HookManager::doAction('w5.clear');

        $this->assertSame(0, $callback);
        $this->assertSame(0, $external);
    }

    /**
     * 디스패처가 바뀌면(새 앱·Event::fake) 새 디스패처에도 미러가 걸리고, 같은 실제 디스패처에 두 번 걸리지 않습니다.
     *
     * @effects fresh_dispatcher_gets_its_own_wildcard_mirror
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 새_디스패처에도_미러가_1개만_걸린다(): void
    {
        $count = 0;
        HookManager::addAction('w5.fresh', function () use (&$count) {
            $count++;
        });

        $fresh = new Dispatcher($this->app);
        Event::swap($fresh);
        HookManager::addAction('w5.fresh.other', fn () => null);

        Event::dispatch('hook.w5.fresh');
        $this->assertSame(1, $count, '새 디스패처에서 1회');
        $this->assertCount(1, $fresh->getListeners('hook.w5.fresh'));

        // 일부 이벤트만 가짜로 — 훅 이벤트는 실제 디스패처로 넘어가므로 미러가 겹치면 2회가 된다
        Event::fake(['w5.unrelated']);
        HookManager::addAction('w5.fresh.again', fn () => null);

        Event::dispatch('hook.w5.fresh');
        $this->assertSame(2, $count, '가짜 디스패처를 거쳐도 1회');
        $this->assertCount(1, $fresh->getListeners('hook.w5.fresh'));
    }
}
