<?php

namespace Tests\Unit\Seo;

use App\Events\GenericBroadcastEvent;
use App\Seo\SitemapProgress;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * SitemapProgress 진행상황 스토어 테스트
 *
 * 상태 전이(queued→running→writing→completed|failed), 캐시 기록, 방송(Reverb ON),
 * 방송 스로틀(N URL 간격), Reverb OFF 시 캐시 폴백을 검증합니다.
 */
class SitemapProgressTest extends TestCase
{
    private SitemapProgress $progress;

    protected function setUp(): void
    {
        parent::setUp();
        $this->progress = app(SitemapProgress::class);
    }

    /**
     * Reverb 방송이 실제로 발생하도록 드라이버/호스트를 설정합니다.
     */
    private function enableReverb(): void
    {
        config(['broadcasting.default' => 'reverb']);
        config(['broadcasting.connections.reverb.options.host' => 'localhost']);
    }

    /**
     * 실행 이력이 없으면 get() 은 null 을 반환한다.
     */
    public function test_get_returns_null_when_no_run(): void
    {
        $this->assertNull($this->progress->get());
    }

    /**
     * start() 는 status=queued 와 mode/started_at 을 기록한다.
     */
    public function test_start_records_queued_state(): void
    {
        $this->progress->start('full');

        $state = $this->progress->get();
        $this->assertSame('queued', $state['status']);
        $this->assertSame('full', $state['mode']);
        $this->assertNotNull($state['started_at']);
        $this->assertSame(0, $state['urls']);
    }

    /**
     * phase() 는 status=running 과 현재 단계를 기록한다.
     */
    public function test_phase_records_running_state(): void
    {
        $this->progress->start('full');
        $this->progress->phase('sirsoft-board', 1200);

        $state = $this->progress->get();
        $this->assertSame('running', $state['status']);
        $this->assertSame('sirsoft-board', $state['phase']);
        $this->assertSame(1200, $state['urls']);
    }

    /**
     * writing() 은 status=writing 을 기록한다.
     */
    public function test_writing_records_writing_state(): void
    {
        $this->progress->start('full');
        $this->progress->writing(3400);

        $state = $this->progress->get();
        $this->assertSame('writing', $state['status']);
        $this->assertSame(3400, $state['urls']);
    }

    /**
     * complete() 는 status=completed 와 url_count/child_count 를 기록한다.
     */
    public function test_complete_records_completed_state(): void
    {
        $this->progress->start('full');
        $this->progress->complete(['url_count' => 5000, 'child_count' => 2]);

        $state = $this->progress->get();
        $this->assertSame('completed', $state['status']);
        $this->assertSame(5000, $state['url_count']);
        $this->assertSame(2, $state['child_count']);
        $this->assertNotNull($state['finished_at']);
    }

    /**
     * fail() 은 status=failed 와 메시지를 기록한다.
     */
    public function test_fail_records_failed_state(): void
    {
        $this->progress->start('full');
        $this->progress->fail('boom');

        $state = $this->progress->get();
        $this->assertSame('failed', $state['status']);
        $this->assertSame('boom', $state['message']);
        $this->assertNotNull($state['finished_at']);
    }

    /**
     * Reverb ON 이면 방송 payload 가 상태 API data 형태(progress + realtime_enabled)와 동형이다.
     */
    public function test_broadcast_payload_mirrors_status_shape(): void
    {
        $this->enableReverb();
        Event::fake([GenericBroadcastEvent::class]);

        $this->progress->start('full');

        Event::assertDispatched(GenericBroadcastEvent::class, function ($event) {
            // payload 는 상태 API 봉투와 동형: 최상위 data 안에 progress/realtime_enabled
            // (target_source 가 대상 소스를 통째 교체해도 `sitemap_status.data.*` 바인딩 유지)
            return $event->channel === 'core.admin.seo.sitemap'
                && $event->eventName === 'sitemap.progress.updated'
                && ($event->payload['data']['realtime_enabled'] ?? null) === true
                && ($event->payload['data']['progress']['status'] ?? null) === 'queued'
                && array_key_exists('last_updated_at', $event->payload['data']);
        });
    }

    /**
     * 완료 방송은 방금 커밋된 생성 시각을 data.last_updated_at 으로 실어 "마지막 생성"을 실시간 갱신한다.
     */
    public function test_complete_broadcast_carries_new_last_updated_at(): void
    {
        $this->enableReverb();
        Event::fake([GenericBroadcastEvent::class]);

        $this->progress->complete([
            'url_count' => 720,
            'child_count' => 1,
            'generated_at' => '2026-07-19T00:00:00+00:00',
        ]);

        Event::assertDispatched(GenericBroadcastEvent::class, function ($event) {
            return ($event->payload['data']['last_updated_at'] ?? null) === '2026-07-19T00:00:00+00:00'
                && ($event->payload['data']['progress']['status'] ?? null) === 'completed';
        });
    }

    /**
     * Reverb OFF 여도 캐시는 기록된다(폴링 폴백 성립).
     */
    public function test_cache_is_written_even_when_reverb_off(): void
    {
        config(['broadcasting.default' => 'null']);
        Event::fake([GenericBroadcastEvent::class]);

        $this->progress->start('full');

        Event::assertNotDispatched(GenericBroadcastEvent::class);
        $this->assertSame('queued', $this->progress->get()['status']);
    }

    /**
     * 같은 단계에서 urls 만 증가하면 방송은 간격 스로틀되지만 캐시는 항상 기록된다.
     *
     * @scale n=1500000 asserts=progress_broadcast_throttled
     */
    public function test_phase_broadcast_is_throttled_but_cache_always_written(): void
    {
        $this->enableReverb();
        Event::fake([GenericBroadcastEvent::class]);

        // 단계 진입 — 방송 1회
        $this->progress->phase('sirsoft-board', 100);
        // 같은 단계, 소폭 증가(<5000) — 방송 스킵, 캐시는 갱신
        $this->progress->phase('sirsoft-board', 200);
        // 같은 단계, 간격 초과(>=5000) — 방송 재발
        $this->progress->phase('sirsoft-board', 5300);

        // 방송은 진입 + 간격초과 = 2회
        Event::assertDispatchedTimes(GenericBroadcastEvent::class, 2);
        // 캐시는 마지막 값으로 항상 갱신
        $this->assertSame(5300, $this->progress->get()['urls']);
    }
}
