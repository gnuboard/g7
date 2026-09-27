<?php

namespace Tests\Feature\Analytics;

use App\Extension\HookManager;
use App\Listeners\Analytics\RecordSiteSearchListener;
use App\Listeners\Analytics\RecordTrackingRequestListener;
use App\Models\Analytics\AnalyticsVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 수집 리스너의 디스패치 계약
 *
 * 방문 수집은 훅을 거치되 **큐를 타지 않는다**. 코어 훅 정책상 액션 리스너는 기본으로
 * 큐를 타는데, 그 기본을 수집에 그대로 두면 방문 1건마다 잡이 1개씩 쌓인다 — 10만 방문
 * 이면 잡 10만 개이고, `jobs` 테이블의 INSERT·SELECT·DELETE 가 원장 INSERT 한 번보다
 * 비싸므로 얻는 것 없이 비용만 는다. 워커가 없는 설치에서는 방문이 통째로 유실된다.
 *
 * 선언만 보면 「고쳤다」를 흉내 내기 쉬워 **행 수로** 잰다 — 큐 드라이버를 데이터베이스로
 * 두고 비콘을 쏜 뒤 `jobs` 가 비어 있고 원장에 행이 생겼는지 본다(#682 S3 라운드 6,
 * PO 확정 「큐는 꼭 필요한 부분에만」).
 */
class CollectionDispatchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 수집·검색 리스너는 동기 실행을 선언한다.
     *
     * @scenario dispatch=sync, source=beacon
     *
     * @effects collection_listeners_never_go_through_the_queue
     *
     * @return void 없음
     */
    public function test_collection_listeners_declare_sync(): void
    {
        $this->assertTrue(
            RecordTrackingRequestListener::getSubscribedHooks()['core.analytics.tracking_request_received']['sync'] ?? false,
            '수집 리스너가 큐를 타면 방문 1건마다 잡이 1개씩 쌓인다',
        );

        $this->assertTrue(
            RecordSiteSearchListener::getSubscribedHooks()['core.search.after_search']['sync'] ?? false,
            '검색 리스너가 큐를 타면 검색 1회마다 잡이 1개씩 쌓인다',
        );
    }

    /**
     * 큐 드라이버가 데이터베이스여도 비콘은 잡을 만들지 않고 그 자리에서 적재된다.
     *
     * @scenario dispatch=sync, source=beacon, visit_state=new
     *
     * @effects collection_listeners_never_go_through_the_queue
     *
     * @return void 없음
     */
    public function test_beacon_records_without_queueing_a_job(): void
    {
        config(['queue.default' => 'database']);

        DB::table('jobs')->delete();

        $this->postJson('/api/analytics/collect', [
            't' => 'pageview',
            'url' => 'https://example.test/board/free',
        ])->assertNoContent();

        $this->assertSame(0, DB::table('jobs')->count(), '방문 1건이 잡 1개가 되어서는 안 된다');
        $this->assertSame(1, AnalyticsVisit::count(), '큐를 타지 않으므로 워커 없이도 원장에 남아야 한다');
    }

    /**
     * 사이트 내부 검색 훅도 잡을 만들지 않는다.
     *
     * @scenario dispatch=sync, source=search
     *
     * @effects collection_listeners_never_go_through_the_queue
     *
     * @return void 없음
     */
    public function test_site_search_hook_does_not_queue_a_job(): void
    {
        config(['queue.default' => 'database']);

        DB::table('jobs')->delete();

        HookManager::doAction('core.search.after_search', [
            'keyword' => '공지',
            'result_count' => 3,
        ]);

        $this->assertSame(0, DB::table('jobs')->count(), '검색 1회가 잡 1개가 되어서는 안 된다');
    }
}
