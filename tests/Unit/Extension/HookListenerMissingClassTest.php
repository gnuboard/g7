<?php

namespace Tests\Unit\Extension;

use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Jobs\DispatchHookListenerJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 구독은 남았는데 리스너 클래스가 사라진 경우 — 그 리스너만 건너뛰고 훅은 계속 돈다 (#725).
 *
 * 확장 업데이트가 리스너 클래스를 지우면, 업데이트를 실행한 프로세스에는 부팅 때 등록한 옛 구독이 남아 있다. 리스너는
 * 훅이 발화할 때 지연 로딩되므로(`app($listenerClass)`) 그 뒤 같은 훅이 발화하면 「Target class … does not exist」 로
 * 터졌다. 결제 플러그인 4종의 업데이트 복원 리스너를 지운 판으로 올리면 업데이트는 끝났는데 마지막 업데이트 완료 훅에서
 * 이 예외가 나 명령이 실패로 끝나고 「백업에서 복원」 이라는 틀린 안내가 나왔다(#725 실측).
 */
class HookListenerMissingClassTest extends TestCase
{
    /**
     * 사라진 리스너 클래스 이름 (어디에도 정의되지 않는다).
     */
    private const GONE = 'Plugins\\Probe725\\Listeners\\RemovedByUpdateListener';

    /**
     * 훅 등록 상태를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        HookManager::resetAll();
        HookListenerRegistrar::clear();
    }

    /**
     * 훅 등록 상태를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::resetAll();
        HookListenerRegistrar::clear();
        parent::tearDown();
    }

    /**
     * 동기 액션 — 사라진 리스너는 건너뛰고 같은 훅의 다른 리스너는 실행되며, 경고는 한 번만 남는다.
     *
     * @scenario cache_state=present_valid,registration_source=plugin,listener_type=action_sync,regeneration_trigger=extension_update
     *
     * @effects listener_removed_by_update_is_skipped_without_failing_the_hook
     *
     * @return void 반환값 없음
     */
    public function test_sync_action_skips_listener_whose_class_was_removed(): void
    {
        Log::spy();
        HookListenerRegistrar::registerFromCache(self::GONE, ['probe725.after_update' => ['method' => 'handle', 'type' => 'action', 'sync' => true]], 'probe725');

        $calls = 0;
        HookManager::addAction('probe725.after_update', function () use (&$calls): void {
            $calls++;
        }, 30);

        HookManager::doAction('probe725.after_update', 'probe725');
        HookManager::doAction('probe725.after_update', 'probe725');

        $this->assertSame(2, $calls, '사라진 리스너 때문에 같은 훅의 다른 리스너가 실행되지 않았다');
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, '리스너 클래스가 없어')
                && ($context['listener'] ?? null) === self::GONE)
            ->once();
    }

    /**
     * 필터 — 사라진 리스너는 값을 바꾸지 않고 그대로 넘긴다.
     *
     * @scenario cache_state=present_valid,registration_source=plugin,listener_type=filter,regeneration_trigger=extension_update
     *
     * @effects listener_removed_by_update_is_skipped_without_failing_the_hook
     *
     * @return void 반환값 없음
     */
    public function test_filter_passes_value_through_when_listener_class_was_removed(): void
    {
        HookListenerRegistrar::registerFromCache(self::GONE, ['probe725.filter' => ['method' => 'filter', 'type' => 'filter']], 'probe725');

        $this->assertSame(['kept'], HookManager::applyFilters('probe725.filter', ['kept']));
    }

    /**
     * 큐 액션 — 사라진 리스너는 큐에 싣지 않는다(워커가 같은 예외로 실패할 일을 만들지 않는다).
     *
     * @scenario cache_state=present_valid,registration_source=plugin,listener_type=action_queued,regeneration_trigger=extension_update
     *
     * @effects listener_removed_by_update_is_skipped_without_failing_the_hook
     *
     * @return void 반환값 없음
     */
    public function test_queued_action_is_not_dispatched_when_listener_class_was_removed(): void
    {
        Queue::fake();
        HookListenerRegistrar::registerFromCache(self::GONE, ['probe725.queued' => ['method' => 'handle', 'type' => 'action']], 'probe725');

        HookManager::doAction('probe725.queued', 'probe725');

        Queue::assertNotPushed(DispatchHookListenerJob::class);
    }
}
