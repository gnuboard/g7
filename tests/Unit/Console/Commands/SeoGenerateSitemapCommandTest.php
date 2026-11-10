<?php

namespace Tests\Unit\Console\Commands;

use App\Enums\SitemapGenerationMode;
use App\Jobs\GenerateSitemapJob;
use Illuminate\Bus\UniqueLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Lock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

/**
 * SeoGenerateSitemapCommand 유닛 테스트
 *
 * seo:generate-sitemap 커맨드의 큐 드라이버 분기, 강제 동기, 모드 옵션(--mode/--rebuild),
 * 출력 메시지를 검증합니다.
 */
class SeoGenerateSitemapCommandTest extends TestCase
{
    /**
     * Mockery 기대값을 닫고 부모 정리를 실행한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 큐 드라이버가 sync 가 아닐 때 비동기 디스패치되는지 확인합니다.
     *
     * @effects sitemap_command_dispatches_async_unless_queue_is_sync
     *
     * @return void 반환값 없음
     */
    public function test_command_dispatches_async_when_queue_driver_is_not_sync(): void
    {
        Config::set('queue.default', 'database');
        Bus::fake([GenerateSitemapJob::class]);

        $this->artisan('seo:generate-sitemap')
            ->expectsOutput(__('seo.console.generate_sitemap.dispatched', ['mode' => 'auto']))
            ->assertExitCode(Command::SUCCESS);

        Bus::assertDispatched(GenerateSitemapJob::class, fn (GenerateSitemapJob $job) => $job->mode === SitemapGenerationMode::Auto);
        Bus::assertNotDispatchedSync(GenerateSitemapJob::class);
    }

    /**
     * 큐 드라이버가 sync 일 때 옵션 없이도 동기 실행되는지 확인합니다.
     *
     * @effects sitemap_command_runs_sync_when_queue_is_sync_or_forced
     *
     * @return void 반환값 없음
     */
    public function test_command_dispatches_sync_when_queue_driver_is_sync(): void
    {
        Config::set('queue.default', 'sync');
        Bus::fake([GenerateSitemapJob::class]);

        $this->artisan('seo:generate-sitemap')
            ->expectsOutput(__('seo.console.generate_sitemap.generated', ['mode' => 'auto']))
            ->assertExitCode(Command::SUCCESS);

        Bus::assertDispatchedSync(GenerateSitemapJob::class);
    }

    /**
     * --sync 옵션은 큐 드라이버와 무관하게 동기 실행을 강제합니다.
     *
     * @effects sitemap_command_runs_sync_when_queue_is_sync_or_forced
     *
     * @return void 반환값 없음
     */
    public function test_command_forces_sync_when_option_provided(): void
    {
        Config::set('queue.default', 'database');
        Bus::fake([GenerateSitemapJob::class]);

        $this->artisan('seo:generate-sitemap', ['--sync' => true])
            ->expectsOutput(__('seo.console.generate_sitemap.generated', ['mode' => 'auto']))
            ->assertExitCode(Command::SUCCESS);

        Bus::assertDispatchedSync(GenerateSitemapJob::class);
    }

    /**
     * --mode 옵션이 잡에 그대로 전달됩니다.
     *
     * @effects sitemap_command_passes_mode_option_to_job
     *
     * @return void 반환값 없음
     */
    public function test_command_passes_mode_option_to_job(): void
    {
        Config::set('queue.default', 'database');
        Bus::fake([GenerateSitemapJob::class]);

        $this->artisan('seo:generate-sitemap', ['--mode' => 'incremental'])
            ->expectsOutput(__('seo.console.generate_sitemap.dispatched', ['mode' => 'incremental']))
            ->assertExitCode(Command::SUCCESS);

        Bus::assertDispatched(GenerateSitemapJob::class, fn (GenerateSitemapJob $job) => $job->mode === SitemapGenerationMode::Incremental);
    }

    /**
     * --rebuild 옵션은 --mode 보다 우선하며 full 로 강제합니다.
     *
     * @effects sitemap_command_rebuild_option_forces_full_mode
     *
     * @return void 반환값 없음
     */
    public function test_rebuild_option_forces_full_mode(): void
    {
        Config::set('queue.default', 'database');
        Bus::fake([GenerateSitemapJob::class]);

        $this->artisan('seo:generate-sitemap', ['--rebuild' => true, '--mode' => 'incremental'])
            ->expectsOutput(__('seo.console.generate_sitemap.dispatched', ['mode' => 'full']))
            ->assertExitCode(Command::SUCCESS);

        Bus::assertDispatched(GenerateSitemapJob::class, fn (GenerateSitemapJob $job) => $job->mode === SitemapGenerationMode::Full);
    }

    /**
     * 잘못된 --mode 값은 INVALID 로 거부하고 잡을 디스패치하지 않습니다.
     *
     * @effects sitemap_command_rejects_invalid_mode_without_dispatch
     *
     * @return void 반환값 없음
     */
    public function test_invalid_mode_is_rejected(): void
    {
        Config::set('queue.default', 'database');
        Bus::fake([GenerateSitemapJob::class]);

        $this->artisan('seo:generate-sitemap', ['--mode' => 'bogus'])
            ->assertExitCode(Command::INVALID);

        Bus::assertNotDispatched(GenerateSitemapJob::class);
    }

    /**
     * 큐 잡의 중복 방지 잠금 이름 — 동기 실행이 같은 이름을 잡아야 큐에서 생성 중인 잡과 겹치지 않는다.
     *
     * @return string 잠금 이름
     */
    private function uniqueLockKey(): string
    {
        return UniqueLock::getKey(new GenerateSitemapJob(SitemapGenerationMode::Auto));
    }

    /**
     * 잡 실행기를 대신할 목을 등록합니다 — 실제 생성은 돌리지 않고, 실행 순간 할 일을 콜백으로 받습니다.
     *
     * @param  callable(GenerateSitemapJob): void  $duringRun  잡이 실행되는 순간 할 일
     * @return void 반환값 없음
     */
    private function fakeDispatcherRunning(callable $duringRun): void
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatchSync')
            ->once()
            ->andReturnUsing(function (GenerateSitemapJob $job) use ($duringRun) {
                $duringRun($job);

                return null;
            });

        $this->app->instance(Dispatcher::class, $dispatcher);
    }

    /**
     * 동기 실행은 큐 잡과 같은 중복 방지 잠금을 잡은 채 잡을 돌리고, 끝나면 그 잠금을 푼다(#161 F2) — 잠금 없이 돌면 큐에서
     * 생성 중인 잡과 겹쳐 돌았다.
     *
     * @effects sitemap_sync_run_holds_job_unique_lock
     *
     * @return void 반환값 없음
     */
    public function test_sync_run_takes_and_releases_unique_lock(): void
    {
        Config::set('queue.default', 'database');
        $key = $this->uniqueLockKey();
        $heldDuringRun = null;

        $this->fakeDispatcherRunning(function (GenerateSitemapJob $job) use ($key, &$heldDuringRun) {
            $this->assertSame(SitemapGenerationMode::Auto, $job->mode);
            // 실행 중에는 다른 쪽이 같은 잠금을 잡을 수 없다
            $heldDuringRun = ! Cache::lock($key, 10)->get();
        });

        $this->artisan('seo:generate-sitemap', ['--sync' => true])
            ->expectsOutput(__('seo.console.generate_sitemap.generated', ['mode' => 'auto']))
            ->assertExitCode(Command::SUCCESS);

        $this->assertTrue($heldDuringRun, '잡이 도는 동안 중복 방지 잠금이 잡혀 있어야 한다');
        $this->assertTrue(Cache::lock($key, 10)->get(), '끝나면 잠금을 푼다');
    }

    /**
     * 큐 잡이 중복 방지 잠금을 쥐고 있으면 동기 실행은 「이미 생성 중」으로 건너뛰고 성공으로 끝난다 — 예약 실행이 실패로
     * 보이지 않게 하고, 남의 잠금은 풀지 않는다.
     *
     * @effects sitemap_sync_run_skips_while_job_holds_lock
     *
     * @return void 반환값 없음
     */
    public function test_sync_run_skips_when_queued_job_holds_unique_lock(): void
    {
        Config::set('queue.default', 'sync');
        Bus::fake([GenerateSitemapJob::class]);
        $key = $this->uniqueLockKey();
        $this->assertTrue(Cache::lock($key, 7440, 'queued-job-owner')->get());

        $this->artisan('seo:generate-sitemap')
            ->expectsOutput(__('seo.console.generate_sitemap.already_running'))
            ->assertExitCode(Command::SUCCESS);

        Bus::assertNotDispatchedSync(GenerateSitemapJob::class);
        Bus::assertNotDispatched(GenerateSitemapJob::class);
        $this->assertTrue(Cache::restoreLock($key, 'queued-job-owner')->isOwnedByCurrentProcess(), '큐 잡의 잠금은 그대로 남는다');
    }

    /**
     * 실행 도중 잠금 주인이 바뀌었으면(큐 처리기가 잠금을 풀고 다른 잡이 잡음) 끝난 뒤 그 잠금을 풀지 않는다 — 종전에는 실행
     * 끝에 잠금을 강제로 풀어 남의 잠금까지 지웠고, 세 번째 실행이 들어왔다.
     *
     * @effects sitemap_sync_run_releases_only_its_own_lock
     *
     * @return void 반환값 없음
     */
    public function test_sync_run_does_not_release_lock_owned_by_other(): void
    {
        Config::set('queue.default', 'database');
        $key = $this->uniqueLockKey();

        $this->fakeDispatcherRunning(function () use ($key) {
            // 큐 처리기가 잡 끝에 잠금을 강제로 풀고, 그 사이 큐의 다른 잡이 잠금을 잡았다
            Cache::lock($key)->forceRelease();
            $this->assertTrue(Cache::lock($key, 7440, 'next-job-owner')->get());
        });

        $this->artisan('seo:generate-sitemap', ['--sync' => true])
            ->assertExitCode(Command::SUCCESS);

        $this->assertTrue(Cache::restoreLock($key, 'next-job-owner')->isOwnedByCurrentProcess(), '다른 주인의 잠금은 풀지 않는다');
        $this->assertFalse(Cache::lock($key, 10)->get());
    }

    /**
     * 잠금 저장소가 오류를 내면 잡을 돌리지 않고 실패로 끝난다 — 잠금 없이 돌면 중복 생성을 막을 수 없다. 원본 오류 문구는
     * 화면에 내보내지 않는다.
     *
     * @effects sitemap_sync_run_fails_when_lock_store_errors
     *
     * @return void 반환값 없음
     */
    public function test_sync_run_fails_without_running_when_lock_store_errors(): void
    {
        Config::set('queue.default', 'database');
        Bus::fake([GenerateSitemapJob::class]);

        config(['cache.stores.i161_broken_lock' => ['driver' => 'i161_broken_lock']]);
        Cache::extend('i161_broken_lock', fn () => Cache::repository(new class extends ArrayStore
        {
            /**
             * 얻으려 하면 저장소 오류가 나는 잠금을 돌려준다.
             *
             * @param  string  $name  잠금 이름
             * @param  int  $seconds  잠금 유지 시간(초)
             * @param  string|null  $owner  소유자
             * @return \Illuminate\Contracts\Cache\Lock 오류 잠금
             */
            public function lock($name, $seconds = 0, $owner = null)
            {
                return new class($name, $seconds, $owner) extends Lock
                {
                    /**
                     * 잠금 저장소에 닿지 못한다.
                     *
                     * @return bool 반환하지 않음
                     *
                     * @throws \RuntimeException 언제나
                     */
                    public function acquire()
                    {
                        throw new \RuntimeException('i161 lock store down C:\\fake\\g7');
                    }

                    /**
                     * 잠금을 푼다.
                     *
                     * @return bool 언제나 true
                     */
                    public function release()
                    {
                        return true;
                    }

                    /**
                     * 소유자와 무관하게 잠금을 푼다.
                     *
                     * @return void 반환값 없음
                     */
                    public function forceRelease() {}

                    /**
                     * 현재 소유자를 돌려준다.
                     *
                     * @return string|null 소유자 없음
                     */
                    protected function getCurrentOwner()
                    {
                        return null;
                    }
                };
            }
        }));
        // 명령은 기본 캐시 저장소(Repository 계약)에서 잠금을 잡는다
        $this->app->instance('cache.store', Cache::store('i161_broken_lock'));

        try {
            $this->artisan('seo:generate-sitemap', ['--sync' => true])
                ->expectsOutput(__('seo.console.generate_sitemap.lock_unavailable'))
                ->doesntExpectOutputToContain('i161 lock store down')
                ->assertExitCode(Command::FAILURE);
        } finally {
            $this->app->forgetInstance('cache.store');
            Cache::forgetDriver('i161_broken_lock');
        }

        Bus::assertNotDispatchedSync(GenerateSitemapJob::class);
        Bus::assertNotDispatched(GenerateSitemapJob::class);
    }
}
