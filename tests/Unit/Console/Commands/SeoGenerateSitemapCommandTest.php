<?php

namespace Tests\Unit\Console\Commands;

use App\Enums\SitemapGenerationMode;
use App\Jobs\GenerateSitemapJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
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
}
