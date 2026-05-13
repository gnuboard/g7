<?php

namespace Tests\Unit\Jobs;

use App\Jobs\GenerateSitemapJob;
use App\Seo\SitemapManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * GenerateSitemapJob 유닛 테스트
 *
 * 잡의 큐 속성과 SitemapManager 위임 동작을 검증합니다.
 * 실제 생성/캐시/last_updated_at 로직은 SitemapManager 단위 테스트에서 검증합니다.
 */
class GenerateSitemapJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * ShouldQueue 인터페이스를 구현하는지 확인합니다.
     */
    public function test_job_implements_should_queue(): void
    {
        $job = new GenerateSitemapJob();

        $this->assertInstanceOf(ShouldQueue::class, $job);
    }

    /**
     * tries=3, timeout=300 속성을 확인합니다.
     */
    public function test_job_has_correct_tries_and_timeout(): void
    {
        $job = new GenerateSitemapJob();

        $this->assertSame(3, $job->tries);
        $this->assertSame(300, $job->timeout);
    }

    /**
     * handle() 호출 시 SitemapManager::regenerate 에 위임하는지 확인합니다.
     */
    public function test_handle_delegates_to_sitemap_manager(): void
    {
        $manager = Mockery::mock(SitemapManager::class);
        $manager->shouldReceive('regenerate')
            ->once()
            ->andReturn([
                'success' => true,
                'status' => 'updated',
                'data' => [
                    'last_updated_at' => '2026-04-29T10:00:00+00:00',
                    'size_bytes' => 1234,
                    'ttl' => 86400,
                ],
            ]);

        Log::shouldReceive('info')
            ->once()
            ->with('[SEO] Sitemap generated and cached', Mockery::on(function ($context) {
                return $context['size'] === 1234 && $context['ttl'] === 86400;
            }));

        $job = new GenerateSitemapJob();
        $job->handle($manager);

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    /**
     * disabled 상태이면 생성을 건너뛰고 예외를 던지지 않는지 확인합니다.
     */
    public function test_handle_skips_when_disabled(): void
    {
        $manager = Mockery::mock(SitemapManager::class);
        $manager->shouldReceive('regenerate')
            ->once()
            ->andReturn([
                'success' => false,
                'status' => 'disabled',
                'message' => 'Sitemap 생성이 비활성화되어 있습니다.',
            ]);

        Log::shouldReceive('info')
            ->once()
            ->with('[SEO] Sitemap generation skipped (disabled)');

        $job = new GenerateSitemapJob();
        $job->handle($manager);

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    /**
     * 실패 응답 시 RuntimeException 을 던져 큐가 재시도하도록 합니다.
     */
    public function test_handle_throws_on_failure(): void
    {
        $manager = Mockery::mock(SitemapManager::class);
        $manager->shouldReceive('regenerate')
            ->once()
            ->andReturn([
                'success' => false,
                'status' => 'failed',
                'message' => 'contributor crashed',
            ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('contributor crashed');

        $job = new GenerateSitemapJob();
        $job->handle($manager);
    }

    /**
     * failed() 호출 시 에러 로그를 기록하는지 확인합니다.
     */
    public function test_failed_logs_error(): void
    {
        $exception = new \RuntimeException('Sitemap generation timeout');

        Log::shouldReceive('error')
            ->once()
            ->with('[SEO] Sitemap generation failed', Mockery::on(function ($context) {
                return $context['error'] === 'Sitemap generation timeout';
            }));

        $job = new GenerateSitemapJob();
        $job->failed($exception);

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }
}
