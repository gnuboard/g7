<?php

namespace Tests\Unit\Thumbnails;

use App\Extension\HookManager;
use App\Thumbnails\ThumbnailPurgeBuffer;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 썸네일 퍼지 묶음 테스트
 *
 * 비우는 시점은 셋이다 — 콘솔·테스트는 즉시(단 `batch()` 안은 끝에 한 번), 큐 작업은 작업 하나가 끝날 때,
 * HTTP 는 응답 뒤(`terminating`). 같은 항목은 한 번만 싣는다. 리스너가 실패해도 삭제 흐름을 실패로 만들지 않는다.
 */
class ThumbnailPurgeBufferTest extends TestCase
{
    /** @var list<list<array<string, mixed>>> 발화된 묶음 */
    private array $fired = [];

    /**
     * 퍼지 훅을 기록하도록 겁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fired = [];
        HookManager::clearAction('core.thumbnail.after_purge');
        HookManager::addAction('core.thumbnail.after_purge', function (array $items) {
            $this->fired[] = $items;
        });
    }

    /**
     * 퍼지 훅을 비우고 큐 처리 표시를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        app(ThumbnailPurgeBuffer::class)->setProcessingJob(false);
        HookManager::clearAction('core.thumbnail.after_purge');

        parent::tearDown();
    }

    /**
     * 퍼지 항목 하나
     *
     * @param  string  $prefix  원본 접두
     * @return array{source_disk: string, prefix: string, thumbnail_disk: string, public_url_prefix: ?string, original_public_url: ?string} 항목
     */
    private function item(string $prefix): array
    {
        return [
            'source_disk' => 'attachments',
            'prefix' => $prefix,
            'thumbnail_disk' => 'thumbnails',
            'public_url_prefix' => null,
            'original_public_url' => null,
        ];
    }

    /**
     * 테스트·콘솔에서는 묶음 밖 항목을 즉시 발화한다.
     *
     * @effects purge_flushed_at_right_time
     *
     * @return void 반환값 없음
     */
    public function test_console_and_tests_flush_immediately(): void
    {
        $buffer = app(ThumbnailPurgeBuffer::class);
        $buffer->add($this->item('a.jpg'));
        $buffer->add($this->item('b.jpg'));

        $this->assertCount(2, $this->fired);
        $this->assertSame([], $buffer->pending());
        $this->assertSame(0, $buffer->flush(), '비어 있으면 발화하지 않는다');
        $this->assertCount(2, $this->fired);
    }

    /**
     * 묶음(중첩 포함) 안 항목은 가장 바깥 묶음이 끝날 때 한 번 — 같은 항목은 한 번만.
     *
     * @effects purge_batched_once, purge_flushed_at_right_time
     *
     * @return void 반환값 없음
     */
    public function test_batch_fires_once_at_the_outermost_end_without_duplicates(): void
    {
        $buffer = app(ThumbnailPurgeBuffer::class);

        $result = $buffer->batch(function () use ($buffer) {
            $buffer->add($this->item('a.jpg'));
            $buffer->batch(function () use ($buffer) {
                $buffer->add($this->item('b.jpg'));
                $buffer->add($this->item('a.jpg'));
            });
            $this->assertSame([], $this->fired, '안쪽 묶음 끝에서 발화했다');
            $this->assertCount(2, $buffer->pending());

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertCount(1, $this->fired);
        $this->assertSame(['a.jpg', 'b.jpg'], array_column($this->fired[0], 'prefix'));
    }

    /**
     * 콜백이 예외를 던져도 모은 항목은 발화하고 예외는 그대로 올라간다.
     *
     * @effects purge_flushed_at_right_time
     *
     * @return void 반환값 없음
     */
    public function test_batch_flushes_even_when_the_callback_throws(): void
    {
        $buffer = app(ThumbnailPurgeBuffer::class);

        try {
            $buffer->batch(function () use ($buffer) {
                $buffer->add($this->item('a.jpg'));

                throw new \RuntimeException('boom');
            });
            $this->fail('예외가 삼켜졌다');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertCount(1, $this->fired);
    }

    /**
     * 큐 작업 처리 중에는 모아 두고, 작업 끝(JobProcessed·JobFailed 이벤트)에 비운다.
     *
     * @effects purge_flushed_at_right_time
     *
     * @return void 반환값 없음
     */
    public function test_queue_job_holds_until_the_job_finishes(): void
    {
        $buffer = app(ThumbnailPurgeBuffer::class);
        $job = $this->createMock(Job::class);

        foreach ([JobProcessed::class, JobFailed::class] as $endEvent) {
            $this->fired = [];

            Event::dispatch(new JobProcessing('sync', $job));
            $buffer->add($this->item('queued.jpg'));
            $buffer->add($this->item('queued-2.jpg'));
            $this->assertSame([], $this->fired, '작업 중에 발화했다');
            $this->assertCount(2, $buffer->pending());

            Event::dispatch($endEvent === JobFailed::class
                ? new JobFailed('sync', $job, new \RuntimeException('x'))
                : new JobProcessed('sync', $job));

            $this->assertCount(1, $this->fired, "{$endEvent} 뒤 한 번");
            $this->assertCount(2, $this->fired[0]);
            $this->assertSame([], $buffer->pending());
        }
    }

    /**
     * HTTP 요청 끝(`terminating`)에 남은 항목을 비운다.
     *
     * @effects purge_flushed_at_right_time
     *
     * @return void 반환값 없음
     */
    public function test_http_terminating_flushes_pending_items(): void
    {
        $buffer = app(ThumbnailPurgeBuffer::class);
        // 테스트 실행은 즉시 비우므로, 요청 중 보류 상태를 큐 처리 표시로 흉내 낸다
        $buffer->setProcessingJob(true);
        $buffer->add($this->item('http.jpg'));
        $this->assertSame([], $this->fired);

        $this->app->terminate();

        $this->assertCount(1, $this->fired);
        $this->assertSame('http.jpg', $this->fired[0][0]['prefix']);
    }

    /**
     * 리스너가 실패해도 flush 는 예외를 던지지 않고 경고만 남긴다.
     *
     * @effects purge_listener_failure_is_isolated
     *
     * @return void 반환값 없음
     */
    public function test_listener_failure_is_logged_not_thrown(): void
    {
        HookManager::addAction('core.thumbnail.after_purge', function () {
            throw new \RuntimeException('cdn down');
        }, 20);
        Log::spy();

        app(ThumbnailPurgeBuffer::class)->add($this->item('a.jpg'));

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, '퍼지'))->once();
    }
}
