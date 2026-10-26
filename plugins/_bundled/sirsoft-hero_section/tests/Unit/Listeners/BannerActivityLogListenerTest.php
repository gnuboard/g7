<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Unit\Listeners;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 배너 활동 로그 리스너 테스트 (매니페스트 admin-banners.yaml)
 *
 * 실제 API → 서비스 훅 → 리스너 → activity_logs 기록까지 훅 체인을 그대로 탄다(목 사용 안 함).
 */
class BannerActivityLogListenerTest extends PluginTestCase
{
    /**
     * 관리자
     */
    private User $admin;

    /**
     * 테스트 준비.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageDisks();
        $this->admin = $this->fullAdmin();
    }

    /**
     * 수정은 바뀐 필드를 changes 에 남기고, 설명 이름은 제목 스냅샷으로 해석된다.
     *
     * @scenario surface=update_detects_changes_for_log
     *
     * @effects activity_log_per_item
     *
     * @return void 반환값 없음
     */
    public function test_update_records_changes(): void
    {
        $banner = Banner::factory()->withImages()->create(['link_url' => '/old', 'title' => ['ko' => '옛 제목']]);

        $this->actingAs($this->admin)->putJson($this->api('/admin/banners/'.$banner->id), $this->bannerPayload(['link_url' => '/new']))->assertOk();

        $log = DB::table('activity_logs')->where('action', 'hero_banner.update')->where('loggable_id', $banner->id)->first();
        $this->assertNotNull($log);
        $this->assertSame(Banner::class, $log->loggable_type);
        $this->assertStringContainsString('link_url', (string) $log->changes);
        $this->assertSame('sirsoft-hero_section::activity_log.description.hero_banner_update', $log->description_key);
    }

    /**
     * 일괄 비노출은 배너마다 한 줄씩(Per-Item) 남는다.
     *
     * @scenario surface=bulk_status_updates_each
     *
     * @effects activity_log_per_item
     *
     * @return void 반환값 없음
     */
    public function test_bulk_status_logs_per_item(): void
    {
        [$a, $b] = Banner::factory()->count(2)->create();

        $this->actingAs($this->admin)->patchJson($this->api('/admin/banners/bulk-status'), ['ids' => [$a->id, $b->id], 'is_active' => false])->assertOk();

        $rows = DB::table('activity_logs')->where('action', 'hero_banner.bulk_status')->orderBy('loggable_id')->get();
        $this->assertSame([$a->id, $b->id], $rows->pluck('loggable_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame('sirsoft-hero_section::activity_log.description.hero_banner_bulk_hide', $rows[0]->description_key);
    }

    /**
     * 일괄 삭제는 배너마다 한 줄씩, 지워진 배너 id 로 남는다.
     *
     * @scenario surface=bulk_delete_removes_each_with_files
     *
     * @effects activity_log_per_item
     *
     * @return void 반환값 없음
     */
    public function test_bulk_delete_logs_per_item(): void
    {
        [$a, $b] = Banner::factory()->count(2)->create();

        $this->actingAs($this->admin)->deleteJson($this->api('/admin/banners/bulk'), ['ids' => [$a->id, $b->id]])->assertOk();

        $rows = DB::table('activity_logs')->where('action', 'hero_banner.bulk_delete')->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $rows->pluck('loggable_id')->map(fn ($id) => (int) $id)->all());
        $this->assertTrue($rows->every(fn ($row) => $row->loggable_type === Banner::class));
    }

    /**
     * 순서 변경은 위치 1건으로 남는다(배너마다 남기지 않는다).
     *
     * @scenario surface=reorder_accepts_complete_set
     *
     * @effects activity_log_per_item
     *
     * @return void 반환값 없음
     */
    public function test_reorder_logs_once_per_position(): void
    {
        [$a, $b] = Banner::factory()->count(2)->create();

        $this->actingAs($this->admin)->putJson($this->api('/admin/banners/order'), ['position' => 'home_hero', 'ids' => [$b->id, $a->id]])->assertOk();

        $this->assertSame(1, DB::table('activity_logs')->where('action', 'hero_banner.reorder')->count());
    }
}
