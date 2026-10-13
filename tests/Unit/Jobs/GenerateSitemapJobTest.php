<?php

namespace Tests\Unit\Jobs;

use App\Jobs\GenerateSitemapJob;
use App\Seo\SitemapManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
    /**
     * 테스트 뒤 정리를 합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * ShouldQueue 인터페이스를 구현하는지 확인합니다.
     *
     * @scenario regen_mode=full, trigger=admin
     *
     * @effects async_dispatch_from_admin
     *
     * @return void 반환값 없음
     */
    public function test_job_implements_should_queue(): void
    {
        $job = new GenerateSitemapJob;

        $this->assertInstanceOf(ShouldQueue::class, $job);
    }

    /**
     * 대용량 생성을 견디는 큐 속성을 확인합니다.
     *
     * @scenario regen_mode=full, trigger=admin
     *
     * @effects retry_window_covers_timeouts
     *
     * @return void 반환값 없음
     */
    public function test_job_has_correct_tries_and_timeout(): void
    {
        $job = new GenerateSitemapJob;

        $this->assertSame(3, $job->tries);
        $this->assertSame(1800, $job->timeout);
        $this->assertSame(120, $job->backoff);
    }

    /**
     * 전용 'sitemap' 큐로 라우팅되는지 확인합니다.
     *
     * default 큐와 분리해, 긴 생성 작업이 방송/알림을 막지 않고(실시간 진행상황),
     * 이 큐 워커 1개 제약으로 동시 중복 생성을 차단합니다.
     *
     * @scenario regen_mode=full, trigger=admin
     *
     * @effects async_dispatch_from_admin
     *
     * @return void 반환값 없음
     */
    public function test_job_routes_to_dedicated_sitemap_queue(): void
    {
        $job = new GenerateSitemapJob;

        $this->assertSame('sitemap', GenerateSitemapJob::QUEUE);
        $this->assertSame('sitemap', $job->queue);
    }

    /**
     * ShouldBeUnique 를 구현하여 동시 실행을 막는지 확인합니다.
     *
     * 봇 요청 캐시 미스마다 dispatch 되어도 큐에 한 건만 남아야 합니다.
     *
     * @scenario regen_mode=full, trigger=admin
     *
     * @effects job_unique_lock
     *
     * @return void 반환값 없음
     */
    public function test_job_implements_should_be_unique(): void
    {
        $job = new GenerateSitemapJob;

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
    }

    /**
     * 유니크 락 키가 인스턴스와 무관하게 고정값인지 확인합니다.
     *
     * 인스턴스마다 키가 다르면 중복 디스패치가 그대로 통과합니다.
     *
     * @scenario regen_mode=full, trigger=admin
     *
     * @effects job_unique_lock
     *
     * @return void 반환값 없음
     */
    public function test_unique_id_is_stable_across_instances(): void
    {
        $this->assertSame('seo-sitemap', (new GenerateSitemapJob)->uniqueId());
        $this->assertSame('seo-sitemap', (new GenerateSitemapJob)->uniqueId());
    }

    /**
     * 중복 방지 잠금이 재시도 전체 기간을 덮는지 확인합니다 (#139).
     *
     * timeout × tries + backoff × (tries − 1) + 큐 대기 여유 = 1800×3 + 120×2 + 1800 = 7440초.
     * 짧으면 첫 시도가 도는 중에 잠금이 풀려 같은 생성이 또 큐에 들어간다(종전 900초 < timeout 1800초).
     *
     * @scenario regen_mode=full, trigger=admin
     *
     * @effects job_unique_lock, retry_window_covers_timeouts
     *
     * @return void 반환값 없음
     */
    public function test_unique_lock_outlives_whole_retry_window(): void
    {
        $job = new GenerateSitemapJob;

        $this->assertSame(7440, $job->uniqueFor);
        $this->assertSame(
            GenerateSitemapJob::TIMEOUT_SECONDS * GenerateSitemapJob::MAX_TRIES
                + GenerateSitemapJob::BACKOFF_SECONDS * (GenerateSitemapJob::MAX_TRIES - 1)
                + GenerateSitemapJob::QUEUE_WAIT_ALLOWANCE_SECONDS,
            $job->uniqueFor,
        );
        $this->assertGreaterThan($job->timeout * $job->tries, $job->uniqueFor);
    }

    /**
     * retryUntil 이 없어 tries 가 살아 있고, 큐 대기 시간 때문에 실행 없이 실패하지 않는지 확인합니다 (#139).
     *
     * 라라벨은 retryUntil 이 있으면 tries 를 무시한다. 종전 마감 30분은 timeout 30분과 같아 시간 초과 뒤 재시도할
     * 수 없었고, 큐에서 30분 넘게 기다리면 한 번도 돌지 못하고 실패로 끝났다.
     *
     * @scenario regen_mode=full, trigger=admin
     *
     * @effects retry_window_covers_timeouts
     *
     * @return void 반환값 없음
     */
    public function test_job_has_no_retry_until_so_queue_wait_does_not_fail_it(): void
    {
        $job = new GenerateSitemapJob;

        $this->assertFalse(method_exists($job, 'retryUntil'));
        $this->assertFalse(isset($job->retryUntil));

        // 라라벨이 큐에 넣을 payload 를 실제로 만들어 본다 — retryUntil·maxTries 는 이때 잡 객체에서 굳는다
        $queue = app('queue')->connection('database');
        $payload = json_decode((new \ReflectionMethod($queue, 'createPayload'))->invoke($queue, $job, GenerateSitemapJob::QUEUE), true);
        $this->assertNull($payload['retryUntil'] ?? null);
        $this->assertSame(3, $payload['maxTries']);
    }

    /**
     * handle() 호출 시 SitemapManager::regenerate 에 위임하는지 확인합니다.
     *
     * @scenario regen_mode=full, trigger=admin
     *
     * @effects async_dispatch_from_admin
     *
     * @return void 반환값 없음
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

        $job = new GenerateSitemapJob;
        $job->handle($manager);

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    /**
     * disabled 상태이면 생성을 건너뛰고 예외를 던지지 않는지 확인합니다.
     *
     * @scenario regen_mode=full, trigger=admin
     *
     * @effects async_dispatch_from_admin
     *
     * @return void 반환값 없음
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

        $job = new GenerateSitemapJob;
        $job->handle($manager);

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    /**
     * 실패 응답 시 RuntimeException 을 던져 큐가 재시도하도록 합니다.
     *
     * @scenario regen_mode=full, trigger=admin
     *
     * @effects retry_window_covers_timeouts
     *
     * @return void 반환값 없음
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

        $job = new GenerateSitemapJob;
        $job->handle($manager);
    }

    /**
     * failed() 호출 시 에러 로그를 기록하는지 확인합니다.
     *
     * @scenario regen_mode=full, trigger=admin
     *
     * @effects job_failure_marks_progress_failed
     *
     * @return void 반환값 없음
     */
    public function test_failed_logs_error(): void
    {
        $exception = new \RuntimeException('Sitemap generation timeout');

        Log::shouldReceive('error')
            ->once()
            ->with('[SEO] Sitemap generation failed', Mockery::on(function ($context) {
                return $context['error'] === 'Sitemap generation timeout';
            }));

        $job = new GenerateSitemapJob;
        $job->failed($exception);

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }
}
