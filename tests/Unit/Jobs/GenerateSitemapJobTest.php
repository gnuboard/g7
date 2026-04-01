<?php

namespace Tests\Unit\Jobs;

use App\Jobs\GenerateSitemapJob;
use App\Seo\SitemapGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * GenerateSitemapJob 유닛 테스트
 *
 * Sitemap 생성 잡의 큐 속성, 캐시 저장, 비활성화 분기, 실패 처리를 검증합니다.
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
     * handle() 호출 시 Sitemap을 생성하고 캐시에 저장하는지 확인합니다.
     */
    public function test_handle_generates_and_caches_sitemap(): void
    {
        Config::set('g7_settings.core.seo.sitemap_enabled', true);
        Config::set('g7_settings.core.seo.sitemap_cache_ttl', 86400);

        $xml = '<?xml version="1.0"?><urlset></urlset>';

        $generator = Mockery::mock(SitemapGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->andReturn($xml);

        Cache::shouldReceive('put')
            ->once()
            ->with('seo:sitemap', $xml, 86400);

        Log::shouldReceive('info')
            ->once()
            ->with('[SEO] Sitemap generated and cached', Mockery::on(function ($context) use ($xml) {
                return $context['size'] === strlen($xml) && $context['ttl'] === 86400;
            }));

        $job = new GenerateSitemapJob();
        $job->handle($generator);

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    /**
     * sitemap_enabled=false일 때 생성을 건너뛰는지 확인합니다.
     */
    public function test_handle_skips_when_sitemap_disabled(): void
    {
        Config::set('g7_settings.core.seo.sitemap_enabled', false);

        $generator = Mockery::mock(SitemapGenerator::class);
        $generator->shouldNotReceive('generate');

        Log::shouldReceive('info')
            ->once()
            ->with('[SEO] Sitemap generation skipped (disabled)');

        $job = new GenerateSitemapJob();
        $job->handle($generator);

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    /**
     * 설정된 커스텀 TTL이 Cache::put에 전달되는지 확인합니다.
     */
    public function test_handle_uses_configured_cache_ttl(): void
    {
        Config::set('g7_settings.core.seo.sitemap_enabled', true);
        Config::set('g7_settings.core.seo.sitemap_cache_ttl', 3600);

        $xml = '<urlset/>';

        $generator = Mockery::mock(SitemapGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->andReturn($xml);

        Cache::shouldReceive('put')
            ->once()
            ->with('seo:sitemap', $xml, 3600);

        Log::shouldReceive('info')
            ->once()
            ->with('[SEO] Sitemap generated and cached', Mockery::on(function ($context) {
                return $context['ttl'] === 3600;
            }));

        $job = new GenerateSitemapJob();
        $job->handle($generator);

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
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
