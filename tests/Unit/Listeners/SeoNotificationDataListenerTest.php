<?php

namespace Tests\Unit\Listeners;

use App\Listeners\SeoNotificationDataListener;
use Tests\TestCase;

/**
 * SeoNotificationDataListener 단위 테스트.
 *
 * 사이트맵 재생성 알림의 핵심 정책 — 관리자 수동 실행(triggered_by 존재)만 발송하고,
 * 스케줄러/증분/봇(triggered_by null)은 context.skip 으로 제외 — 을 검증합니다.
 */
class SeoNotificationDataListenerTest extends TestCase
{
    private SeoNotificationDataListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = new SeoNotificationDataListener;
    }

    private function default(): array
    {
        return ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];
    }

    public function test_manual_completion_extracts_data_and_trigger_user(): void
    {
        $result = ['status' => 'updated', 'success' => true, 'data' => ['url_count' => 720, 'child_count' => 3]];

        $out = $this->listener->extractData($this->default(), 'sitemap_regenerated', [$result, 42]);

        $this->assertSame(42, $out['context']['trigger_user_id']);
        $this->assertArrayNotHasKey('skip', $out['context']);
        $this->assertSame('720', $out['data']['url_count']);
        $this->assertSame('3', $out['data']['child_count']);
        $this->assertArrayHasKey('action_url', $out['data']);
    }

    public function test_non_manual_completion_is_skipped(): void
    {
        $result = ['status' => 'updated', 'success' => true, 'data' => ['url_count' => 720]];

        // triggered_by 없음(스케줄러/증분/봇) → skip
        $out = $this->listener->extractData($this->default(), 'sitemap_regenerated', [$result, null]);

        $this->assertTrue($out['context']['skip']);
        $this->assertSame([], $out['data']);
    }

    public function test_manual_failure_extracts_error_and_trigger_user(): void
    {
        $result = ['status' => 'failed', 'success' => false, 'message' => 'disk full'];

        $out = $this->listener->extractData($this->default(), 'sitemap_regenerate_failed', [$result, 7]);

        $this->assertSame(7, $out['context']['trigger_user_id']);
        $this->assertSame('disk full', $out['data']['error']);
    }

    public function test_non_manual_failure_is_skipped(): void
    {
        $out = $this->listener->extractData($this->default(), 'sitemap_regenerate_failed', [['message' => 'x'], null]);

        $this->assertTrue($out['context']['skip']);
    }

    public function test_unknown_type_returns_default_untouched(): void
    {
        $out = $this->listener->extractData($this->default(), 'some_other_type', [[], 1]);

        $this->assertSame($this->default(), $out);
    }

    public function test_subscribes_to_seo_extract_data_filter(): void
    {
        $hooks = SeoNotificationDataListener::getSubscribedHooks();

        $this->assertArrayHasKey('core.seo.notification.extract_data', $hooks);
        $this->assertSame('filter', $hooks['core.seo.notification.extract_data']['type']);
    }
}
