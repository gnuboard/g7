<?php

namespace Tests\Unit\Console\Commands;

use App\Jobs\GenerateSitemapJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

/**
 * SeoGenerateSitemapCommand 유닛 테스트
 *
 * seo:generate-sitemap 커맨드의 큐 디스패치, 동기 실행, 출력 메시지를 검증합니다.
 */
class SeoGenerateSitemapCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * --sync 없이 실행 시 잡이 큐에 디스패치되는지 확인합니다.
     */
    public function test_command_dispatches_job_to_queue(): void
    {
        Bus::fake([GenerateSitemapJob::class]);

        $this->artisan('seo:generate-sitemap')
            ->assertExitCode(Command::SUCCESS);

        Bus::assertDispatched(GenerateSitemapJob::class);
    }

    /**
     * --sync 옵션으로 실행 시 잡이 동기적으로 디스패치되는지 확인합니다.
     */
    public function test_command_dispatches_job_sync(): void
    {
        Bus::fake([GenerateSitemapJob::class]);

        $this->artisan('seo:generate-sitemap', ['--sync' => true])
            ->assertExitCode(Command::SUCCESS);

        Bus::assertDispatchedSync(GenerateSitemapJob::class);
    }

    /**
     * 큐 디스패치 시 올바른 출력 메시지를 확인합니다.
     */
    public function test_command_outputs_queue_dispatch_message(): void
    {
        Bus::fake([GenerateSitemapJob::class]);

        $this->artisan('seo:generate-sitemap')
            ->expectsOutput('Sitemap 생성이 큐에 디스패치되었습니다.')
            ->assertExitCode(Command::SUCCESS);
    }

    /**
     * 동기 실행 시 올바른 출력 메시지를 확인합니다.
     */
    public function test_command_outputs_sync_success_message(): void
    {
        Bus::fake([GenerateSitemapJob::class]);

        $this->artisan('seo:generate-sitemap', ['--sync' => true])
            ->expectsOutput('Sitemap이 생성되었습니다.')
            ->assertExitCode(Command::SUCCESS);
    }
}
