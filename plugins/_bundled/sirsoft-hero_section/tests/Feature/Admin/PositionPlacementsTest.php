<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Feature\Admin;

use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Services\PositionSettingService;
use Plugins\Sirsoft\HeroSection\Tests\Concerns\RegistersTestPosition;
use Plugins\Sirsoft\HeroSection\Tests\Fixtures\FakeTargetResolver;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 동적 위치의 대상 목록 API — 배너가 있는 대상만, 이름·사이트 주소·전체·노출 중 건수와 함께(매니페스트 position-registry.yaml).
 *
 * 동적 위치는 시험용 위치 `fake_hero`(가짜 해석기 — 제3 확장이 더한 위치와 같은 길)로 본다. 게시판·상품·카테고리로 나누는 분기 없이
 * 위치 레지스트리와 대상 해석기만 쓰는지 같이 본다.
 */
class PositionPlacementsTest extends PluginTestCase
{
    use RegistersTestPosition;

    /**
     * 시험용 동적 위치를 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerTestPosition();
    }

    /**
     * 배너가 있는 대상만 최근 수정 순으로 — 전체·노출 중(공개 노출과 같은 판정: 비활성·예약·종료는 노출 중이 아니다)·이름·사이트 주소.
     * 지워진 대상은 「없는 대상」으로 남고 노출 중은 0이다(사이트에 나가지 않는다). 위치 전체 배너 수와 대상 수를 따로 준다.
     *
     * @scenario case=admin_placement_list
     *
     * @return void 반환값 없음
     */
    public function test_lists_targets_with_banner_counts(): void
    {
        Banner::factory()->forPosition('fake_hero', '1')->count(2)->create(['updated_at' => now()->subDays(3)]);
        Banner::factory()->forPosition('fake_hero', '1')->inactive()->create(['updated_at' => now()->subDays(3)]);
        Banner::factory()->forPosition('fake_hero', '2')->scheduled()->create(['updated_at' => now()->subDay()]);
        Banner::factory()->forPosition('fake_hero', '2')->ended()->create(['updated_at' => now()->subDay()]);
        Banner::factory()->forPosition('fake_hero', '9')->create(['updated_at' => now()->subDays(5)]);
        Banner::factory()->forPosition('home_hero')->create();

        $data = $this->actingAs($this->fullAdmin())->getJson($this->api('/admin/positions/fake_hero/placements'))->assertOk()->json('data');

        $this->assertSame('fake_hero', $data['position']);
        $this->assertSame([
            ['key' => '2', 'label' => '베타', 'exists' => true, 'site_url' => '/fake/beta', 'total' => 2, 'active' => 0],
            ['key' => '1', 'label' => '알파', 'exists' => true, 'site_url' => '/fake/alpha', 'total' => 3, 'active' => 2],
            // 지워진 대상의 배너는 상태로는 노출 중이어도 사이트에 나가지 않는다(공개 API 0건) — 「지금 노출」은 0이다
            ['key' => '9', 'label' => null, 'exists' => false, 'site_url' => null, 'total' => 1, 'active' => 0],
        ], $data['data']);
        $this->assertSame(['banners' => 6, 'targets' => 3], $data['summary']);
        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'total' => 3, 'per_page' => 20], $data['pagination']);
        // 이름은 대상마다 묻지 않고 한 번에 푼다
        $this->assertSame(1, FakeTargetResolver::$labelCalls);
    }

    /**
     * 대상이 많아도 묶음 조회 수가 대상 수만큼 늘지 않고, 쪽으로 나뉜다. 쪽 번호가 넘치면 마지막 쪽을 준다.
     *
     * @scenario case=admin_placement_list
     *
     * @return void 반환값 없음
     */
    public function test_counts_without_per_target_queries_and_paginates(): void
    {
        FakeTargetResolver::$targets = [];
        foreach (range(1, 25) as $n) {
            FakeTargetResolver::$targets[(string) $n] = ['t'.$n, '대상 '.$n];
            Banner::factory()->forPosition('fake_hero', (string) $n)->create(['updated_at' => now()->subMinutes($n)]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $first = app(PositionSettingService::class)->placements('fake_hero', 1);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(20, $first['data']);
        $this->assertSame('1', $first['data'][0]['key']);
        $this->assertLessThanOrEqual(3, $queries, '대상별 건수를 대상마다 묻지 않는다');
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'total' => 25, 'per_page' => 20], $first['pagination']);
        $this->assertSame(['21', '22', '23', '24', '25'], array_column(app(PositionSettingService::class)->placements('fake_hero', 9)['data'], 'key'));
    }

    /**
     * 정적 위치·등록되지 않은 위치는 빈 값(null), 조회 권한이 없으면 403. 배너가 없는 동적 위치는 빈 목록이다.
     *
     * @scenario case=admin_placement_list
     *
     * @return void 반환값 없음
     */
    public function test_static_unknown_and_permission(): void
    {
        $admin = $this->fullAdmin();
        // 정적·등록되지 않은 위치는 대상 목록이 없다 — 관리 화면이 위치마다 부르므로 오류가 아니라 빈 값(null)
        $this->assertNull($this->actingAs($admin)->getJson($this->api('/admin/positions/home_hero/placements'))->assertOk()->json('data'));
        $this->assertNull($this->actingAs($admin)->getJson($this->api('/admin/positions/nowhere_hero/placements'))->assertOk()->json('data'));
        $this->actingAs($admin)->getJson($this->api('/admin/positions/fake_hero/placements?page=0'))->assertUnprocessable();
        $this->assertSame([], $this->actingAs($admin)->getJson($this->api('/admin/positions/fake_hero/placements'))->assertOk()->json('data.data'));

        $this->actingAs($this->adminWith([]))->getJson($this->api('/admin/positions/fake_hero/placements'))->assertForbidden();
    }
}
