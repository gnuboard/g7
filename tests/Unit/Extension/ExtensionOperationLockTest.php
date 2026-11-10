<?php

namespace Tests\Unit\Extension;

use App\Exceptions\ExtensionOperationInProgressException;
use App\Exceptions\ExtensionOperationLockUnavailableException;
use App\Extension\Helpers\ExtensionOperationLock;
use App\Extension\Support\LifecycleWarnings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\SimulatesOperationLockStore;
use Tests\TestCase;

/**
 * 확장 작업 잠금 — 저장소 오류 시 거절(fail-closed), 재진입 합류, 진행 중 연장, cache:clear 보존 (#161)
 *
 * 7.1.4 까지 잠금 저장소가 오류를 내면 잠금 없이 진행해(fail-open) 같은 확장의 업데이트가 동시에 여러 번 돌았다. 이제 503 으로
 * 거절하고 원본 오류는 error 로그에만 남긴다. 같은 프로세스가 같은 대상을 다시 잡으면 합류하고, 오래 걸리는 작업은 단계마다
 * 잠금을 늘린다. file 캐시의 잠금은 데이터와 다른 폴더라 cache:clear 가 지우지 않는다.
 */
class ExtensionOperationLockTest extends TestCase
{
    use SimulatesOperationLockStore;

    /**
     * 시험마다 바꾼 캐시 스토어·시각을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->restoreOperationLockStore();
        Carbon::setTestNow();
        LifecycleWarnings::current()->clear();

        parent::tearDown();
    }

    /**
     * 잠금 저장소가 오류를 내면 503 예외로 거절하고 원본 오류를 error 로그에 남긴다.
     *
     * @scenario case=lock_store_down_manager
     *
     * @effects lock_store_error_refuses_operation
     *
     * @return void 반환값 없음
     */
    public function test_store_error_throws_unavailable_and_logs_error(): void
    {
        $this->breakLockStore();
        Log::spy();

        try {
            ExtensionOperationLock::acquire('module', 'acme-down');
            $this->fail('잠금 저장소 오류인데 잠금 없이 진행했다');
        } catch (ExtensionOperationLockUnavailableException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertSame(['operation_lock_unavailable' => true], $e->getErrors());
            $this->assertSame(__('extensions.errors.operation_lock_unavailable', ['name' => 'acme-down']), $e->getMessage());
            $this->assertStringNotContainsString('i161', $e->getMessage());
        }

        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => str_contains($message, '잠금 저장소 오류')
            && ($context['identifier'] ?? null) === 'acme-down'
            && str_contains($context['error'] ?? '', 'i161'))->once();
    }

    /**
     * 잠금 저장소 오류가 「일부 항목 실패」 경고로 남지 않는다 — 7.1.4 는 경고를 남기고 진행했다.
     *
     * @scenario case=lock_store_down_manager
     *
     * @effects lock_store_error_refuses_operation
     *
     * @return void 반환값 없음
     */
    public function test_store_error_adds_no_lifecycle_warning(): void
    {
        $this->breakLockStore();
        LifecycleWarnings::current()->clear();

        try {
            ExtensionOperationLock::acquire('plugin', 'acme-down');
        } catch (ExtensionOperationLockUnavailableException) {
            // 기대한 거절
        }

        $this->assertSame([], LifecycleWarnings::current()->all());
    }

    /**
     * 같은 프로세스가 같은 대상을 다시 잡으면 합류하고, 가장 바깥 핸들이 풀릴 때 실제로 풀린다. 다른 대상·다른 프로세스는 막힌다.
     *
     * @scenario case=lifecycle_lock_held
     *
     * @effects reentrant_lock_joins_and_releases_at_depth_zero
     *
     * @return void 반환값 없음
     */
    public function test_reentrant_acquire_joins_and_releases_at_depth_zero(): void
    {
        $outer = ExtensionOperationLock::acquire('module', 'acme-reenter', 'install');
        $inner = ExtensionOperationLock::acquire('module', 'acme-reenter', 'install');

        // 다른 프로세스 흉내(같은 키를 다른 소유자로)는 막힌다
        $this->assertFalse(Cache::lock(ExtensionOperationLock::key('module', 'acme-reenter'), 5)->get());

        $inner->release();
        $this->assertFalse(Cache::lock(ExtensionOperationLock::key('module', 'acme-reenter'), 5)->get(), '안쪽 핸들이 바깥 잠금을 풀었다');

        $outer->release();
        $probe = Cache::lock(ExtensionOperationLock::key('module', 'acme-reenter'), 5);
        $this->assertTrue($probe->get(), '바깥 핸들이 풀린 뒤에도 잠금이 남았다');
        $probe->release();
    }

    /**
     * 다른 프로세스가 잡은 대상이면 409 로 거절하고, 업데이트가 아닌 작업은 「다른 작업 진행 중」 문구를 쓴다.
     *
     * @scenario case=lifecycle_lock_held
     *
     * @effects lifecycle_rejected_409_while_same_extension_locked
     *
     * @return void 반환값 없음
     */
    public function test_held_by_other_process_throws_in_progress_with_operation_message(): void
    {
        $held = Cache::lock(ExtensionOperationLock::key('template', 'acme-busy'), 60);
        $this->assertTrue($held->get());

        try {
            ExtensionOperationLock::acquire('template', 'acme-busy', 'activate');
            $this->fail('다른 프로세스가 잡은 대상인데 거절되지 않았다');
        } catch (ExtensionOperationInProgressException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame(['operation_in_progress' => true], $e->getErrors());
            $this->assertSame('extensions.errors.operation_already_running', $e->getMessageKey());
        } finally {
            $held->release();
        }
    }

    /**
     * 연장은 30초 간격으로만 저장소를 건드리고(force 는 즉시), 늘린 뒤 touch 를 부른다.
     *
     * @scenario case=lifecycle_lock_held
     *
     * @effects heartbeat_refreshes_lock_and_touches_row
     *
     * @return void 반환값 없음
     */
    public function test_keep_alive_throttles_and_refreshes(): void
    {
        $this->useSpyLockStore();
        Carbon::setTestNow('2026-11-06 12:00:00');
        $touched = 0;
        $touch = function () use (&$touched) {
            $touched++;
        };

        $lock = ExtensionOperationLock::acquire('module', 'acme-heartbeat');
        $progress = $lock->wrapProgress(null, $touch);

        $progress('backup', 'x');
        $this->assertSame(0, self::$i161Refreshes, '30초가 지나지 않았는데 연장했다');

        Carbon::setTestNow('2026-11-06 12:00:31');
        $progress('files', 'y');
        $this->assertSame(1, self::$i161Refreshes);
        $this->assertSame(1, $touched);

        $lock->keepAlive($touch, force: true);
        $this->assertSame(2, self::$i161Refreshes, 'force 연장이 간격 제한에 막혔다');
        $this->assertSame(2, $touched);

        $lock->release();
    }

    /**
     * 잠금이 사라졌으면(캐시 비우기·만료) 같은 소유자로 다시 잡는다.
     *
     * @scenario case=lifecycle_lock_held
     *
     * @effects heartbeat_reacquires_lost_lock
     *
     * @return void 반환값 없음
     */
    public function test_keep_alive_reacquires_after_flush(): void
    {
        $this->useSpyLockStore();
        $key = ExtensionOperationLock::key('module', 'acme-flushed');
        $lock = ExtensionOperationLock::acquire('module', 'acme-flushed');

        Cache::lock($key)->forceRelease();
        $probe = Cache::lock($key, 5);
        $this->assertTrue($probe->get());
        $probe->release();

        $lock->keepAlive(force: true);

        $this->assertFalse(Cache::lock($key, 5)->get(), '잃은 잠금을 다시 잡지 않았다');
        $lock->release();
    }

    /**
     * 연장을 지원하지 않는 잠금이면 경고 한 번만 남기고 작업을 막지 않는다.
     *
     * @scenario case=lifecycle_lock_held
     *
     * @effects heartbeat_refreshes_lock_and_touches_row
     *
     * @return void 반환값 없음
     */
    public function test_keep_alive_survives_refresh_unsupported(): void
    {
        $this->useSpyLockStore(refreshUnsupported: true);
        Log::spy();
        $touched = 0;

        $lock = ExtensionOperationLock::acquire('plugin', 'acme-norefresh');
        $lock->keepAlive(function () use (&$touched) {
            $touched++;
        }, force: true);
        $lock->keepAlive(null, force: true);

        $this->assertSame(1, $touched, '연장 미지원이 touch 까지 막았다');
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, '연장을 지원하지 않습니다'))->once();
        $lock->release();
    }

    /**
     * 저장소 오류면 isFree 는 예외 없이 null(판정 불가)을 돌려준다.
     *
     * @scenario case=lock_store_down_manager
     *
     * @effects stuck_judgement_unavailable_when_store_down
     *
     * @return void 반환값 없음
     */
    public function test_is_free_returns_null_on_store_error(): void
    {
        $this->assertTrue(ExtensionOperationLock::isFree('module', 'acme-free'));

        $held = Cache::lock(ExtensionOperationLock::key('module', 'acme-held'), 60);
        $held->get();
        $this->assertFalse(ExtensionOperationLock::isFree('module', 'acme-held'));
        $held->release();

        $this->breakLockStore();
        $this->assertNull(ExtensionOperationLock::isFree('module', 'acme-free'));
    }

    /**
     * file 캐시를 비워도(cache:clear) 잡힌 잠금은 남는다 — 잠금 폴더가 데이터 폴더와 다르다.
     *
     * @scenario case=lock_store_down_manager
     *
     * @effects cache_clear_preserves_held_locks
     *
     * @return void 반환값 없음
     */
    public function test_file_lock_survives_cache_flush(): void
    {
        $store = Cache::store('file');
        $lock = $store->lock('i161-flush-probe', 60);
        $this->assertTrue($lock->get());

        try {
            $store->put('i161-flush-data', 'x', 60);
            $store->flush();

            $this->assertNull($store->get('i161-flush-data'), 'flush 가 데이터를 지우지 않았다');
            $this->assertFalse($store->lock('i161-flush-probe', 60)->get(), 'cache:clear 가 잡힌 잠금을 지웠다');
        } finally {
            $lock->release();
        }
    }

    /**
     * file 캐시의 잠금 경로는 데이터 경로와 다르다.
     *
     * @scenario case=lock_store_down_manager
     *
     * @effects cache_clear_preserves_held_locks
     *
     * @return void 반환값 없음
     */
    public function test_lock_path_differs_from_cache_path(): void
    {
        $this->assertNotSame(config('cache.stores.file.path'), config('cache.stores.file.lock_path'));
        $this->assertStringEndsWith('locks', str_replace('\\', '/', (string) config('cache.stores.file.lock_path')));
    }

    /**
     * 코어 업데이트 잠금은 자기 키·문구를 쓴다.
     *
     * @scenario case=lifecycle_lock_held
     *
     * @effects core_update_refused_while_running
     *
     * @return void 반환값 없음
     */
    public function test_core_lock_uses_core_key_and_message(): void
    {
        $held = Cache::lock(ExtensionOperationLock::CORE_KEY, 60);
        $this->assertTrue($held->get());

        try {
            ExtensionOperationLock::acquireCore();
            $this->fail('코어 업데이트 잠금이 잡혀 있는데 거절되지 않았다');
        } catch (ExtensionOperationInProgressException $e) {
            $this->assertSame('settings.core_update.console.already_running', $e->getMessageKey());
            $this->assertSame(['minutes' => 30], $e->getMessageParams());
        } finally {
            $held->release();
        }
    }
}
