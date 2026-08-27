<?php

namespace Tests\Feature\Dashboard;

use App\Extension\HookManager;
use App\Listeners\StaticPublishFailureAlertListener;
use App\Services\ExtensionStaticCacheService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 정적 게시 실패의 운영자 도달 통로 테스트 (#122 O3).
 *
 * 게시 실패는 사이트를 멈추지 않는다 — API 폴백으로 넘어가 화면은 정상이고 서버 로그에만
 * warning 이 쌓인다. 그래서 쓰기 불가 환경에서 정적 fast path 가 영구히 꺼진 채 운영되는
 * 상태를 아무도 눈치채지 못했다(제보 본건). 이 테스트는 그 사실이 관리자 대시보드에
 * 도달하는지, 그리고 **소음이 되지 않는지**(1회 실패는 알리지 않는다) 를 함께 잠근다.
 */
class StaticPublishAlertTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('g7:core:ext.static.publish_failure');
    }

    protected function tearDown(): void
    {
        Cache::forget('g7:core:ext.static.publish_failure');

        parent::tearDown();
    }

    private function listener(): StaticPublishFailureAlertListener
    {
        return new StaticPublishFailureAlertListener;
    }

    /**
     * 실패 마커를 심습니다.
     *
     * @param  int  $count  연속 실패 횟수
     * @param  string  $reason  사유 코드
     */
    private function seedMarker(int $count, string $reason = 'parent_not_writable'): void
    {
        Cache::put('g7:core:ext.static.publish_failure', [
            'version' => 123,
            'at' => now()->toIso8601String(),
            'reason' => $reason,
            'count' => $count,
            'message' => 'denied',
        ], 300);
    }

    /**
     * 마커가 없으면 알림도 없다.
     *
     * @effects dashboard_has_no_alert_when_publishing_is_healthy
     */
    public function test_no_alert_when_no_failure_marker(): void
    {
        $this->assertSame([], $this->listener()->addStaticPublishAlert([]));
    }

    /**
     * 1회 실패는 알리지 않는다 — 배포 중 일시적 경합일 수 있고 자가 치유가 해소한다.
     *
     * @effects dashboard_alert_suppressed_below_threshold
     */
    public function test_single_failure_does_not_alert(): void
    {
        $this->seedMarker(1);

        $this->assertSame([], $this->listener()->addStaticPublishAlert([]));
    }

    /**
     * 2회 연속 실패부터 대시보드 알림이 노출된다.
     *
     * @effects publish_failure_reaches_dashboard
     */
    public function test_repeated_failure_surfaces_alert(): void
    {
        $this->seedMarker(2);

        $alerts = $this->listener()->addStaticPublishAlert([]);

        $this->assertCount(1, $alerts);
        $this->assertSame('static_publish_failure', $alerts[0]['id']);
        $this->assertSame('warning', $alerts[0]['type']);
        $this->assertSame('static_publish_parent_not_writable', $alerts[0]['subtype']);

        // 문구가 키 그대로 새어 나가지 않는지 — 번역 누락은 화면에 키 문자열을 노출한다.
        $this->assertStringNotContainsString('extensions.alerts.', $alerts[0]['title']);
        $this->assertStringNotContainsString('extensions.alerts.', $alerts[0]['message']);
        $this->assertStringNotContainsString(':count', $alerts[0]['message']);
    }

    /**
     * 사유별로 subtype 과 문구가 갈린다 — 조치할 곳이 다르기 때문이다.
     *
     * @effects publish_failure_alert_distinguishes_reason
     */
    public function test_alert_distinguishes_failure_reason(): void
    {
        $messages = [];

        foreach (['parent_not_writable', 'write_failed', 'lock_unavailable'] as $reason) {
            $this->seedMarker(3, $reason);

            $alerts = $this->listener()->addStaticPublishAlert([]);

            $this->assertSame('static_publish_'.$reason, $alerts[0]['subtype']);
            $this->assertStringNotContainsString('extensions.alerts.', $alerts[0]['message']);

            $messages[$reason] = $alerts[0]['message'];
        }

        $this->assertCount(3, array_unique($messages), '사유별 문구가 구분되지 않는다');
    }

    /**
     * 알 수 없는 사유도 일반 문구로 떨어진다 — 키 문자열이 화면에 노출되지 않는다.
     *
     * @effects publish_failure_alert_falls_back_for_unknown_reason
     */
    public function test_unknown_reason_falls_back_to_generic_message(): void
    {
        $this->seedMarker(2, 'something_new');

        $alerts = $this->listener()->addStaticPublishAlert([]);

        $this->assertCount(1, $alerts);
        $this->assertStringNotContainsString('extensions.alerts.', $alerts[0]['message']);
    }

    /**
     * 기존 알림을 지우지 않고 덧붙인다 (필터 훅 계약).
     *
     * @effects publish_failure_alert_appends_to_existing
     */
    public function test_alert_appends_without_dropping_existing(): void
    {
        $this->seedMarker(2);

        $alerts = $this->listener()->addStaticPublishAlert([['id' => 'other']]);

        $this->assertCount(2, $alerts);
        $this->assertSame('other', $alerts[0]['id']);
    }

    /**
     * 리스너가 `core.dashboard.alerts` 에 **실제로 등록**된다.
     *
     * 등록 실패는 예외도 로그도 남기지 않는다 — 알림이 그냥 안 뜰 뿐이라, 리스너 단위
     * 테스트만으로는 배선 누락이 드러나지 않는다.
     *
     * @effects publish_failure_listener_is_registered_on_hook
     */
    public function test_listener_is_wired_into_dashboard_alerts_hook(): void
    {
        $this->seedMarker(2);

        $alerts = HookManager::applyFilters('core.dashboard.alerts', []);

        $ids = array_column($alerts, 'id');

        $this->assertContains(
            'static_publish_failure',
            $ids,
            '리스너가 훅에 등록되지 않았다 — 알림이 조용히 뜨지 않는다'
        );
    }

    /**
     * 마커 접근자가 서비스의 기록과 같은 키를 본다 (키 오타 회귀 가드).
     *
     * @effects failure_marker_key_is_shared_between_writer_and_reader
     */
    public function test_marker_accessor_reads_what_service_writes(): void
    {
        $this->seedMarker(4, 'write_failed');

        $marker = ExtensionStaticCacheService::failureMarker();

        $this->assertIsArray($marker);
        $this->assertSame(4, $marker['count']);
        $this->assertSame('write_failed', $marker['reason']);
    }
}
