<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Tests\Concerns\RegistersTestPosition;
use Plugins\Sirsoft\HeroSection\Tests\Fixtures\FakeTargetResolver;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 관리자 자리(위치 + 대상) 시험 — 동적 위치의 대상 검증·대상별 순서·목록·미리보기·대상 고르기, 등록되지 않은 위치·없는 대상(D7)
 * (매니페스트 position-registry.yaml).
 *
 * 동적 위치는 시험용 `fake_hero`(가짜 해석기, 대상 1 alpha·2 beta)로 본다 — 제3 확장이 위치를 더하는 길과 같고, 모듈 DB 에 기대지 않는다.
 */
class BannerPlacementTest extends PluginTestCase
{
    use RegistersTestPosition;

    /**
     * 관리자 (전체 권한)
     */
    private User $admin;

    /**
     * 테스트 준비 — 이미지 디스크를 가짜로 바꾸고 시험용 동적 위치를 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageDisks();
        $this->admin = $this->fullAdmin();
        $this->registerTestPosition();
    }

    /**
     * 등록은 위치에 맞는 대상만 받는다 — 동적 위치는 대상 필수·있는 대상만, 정적 위치는 대상을 받지 않고, 등록되지 않은 위치는 거부.
     *
     * @scenario case=store_placement_validation
     *
     * @return void 반환값 없음
     */
    public function test_store_validates_placement(): void
    {
        $this->createBannerViaApi($this->admin, ['position' => 'fake_hero'])->assertUnprocessable()->assertJsonValidationErrors('target_key');
        $this->createBannerViaApi($this->admin, ['position' => 'fake_hero', 'target_key' => '9'])->assertUnprocessable()->assertJsonValidationErrors('target_key');
        $this->createBannerViaApi($this->admin, ['position' => 'home_hero', 'target_key' => '1'])->assertUnprocessable()->assertJsonValidationErrors('target_key');
        $this->createBannerViaApi($this->admin, ['position' => 'nowhere_hero'])->assertUnprocessable()->assertJsonValidationErrors('position');

        $this->createBannerViaApi($this->admin, ['position' => 'fake_hero', 'target_key' => '1'])->assertCreated()->assertJsonPath('data.target_key', '1');
        $this->createBannerViaApi($this->admin, ['position' => 'home_hero'])->assertCreated()->assertJsonPath('data.target_key', null);
        $this->assertSame(1, Banner::query()->where('position', 'fake_hero')->where('target_key', '1')->count());
    }

    /**
     * 순서는 대상마다 따로다 — 등록은 그 대상 맨 뒤, 대상을 바꾸면 새 대상 맨 뒤로 간다.
     *
     * @scenario case=sort_order_per_target
     *
     * @return void 반환값 없음
     */
    public function test_sort_order_is_per_target(): void
    {
        $a1 = $this->createBannerViaApi($this->admin, ['position' => 'fake_hero', 'target_key' => '1'])->assertCreated()->json('data');
        $a2 = $this->createBannerViaApi($this->admin, ['position' => 'fake_hero', 'target_key' => '1'])->assertCreated()->json('data');
        $b1 = $this->createBannerViaApi($this->admin, ['position' => 'fake_hero', 'target_key' => '2'])->assertCreated()->json('data');

        $this->assertSame([0, 1, 0], [$a1['sort_order'], $a2['sort_order'], $b1['sort_order']]);

        $moved = $this->actingAs($this->admin)->putJson($this->api('/admin/banners/'.$a1['id']), $this->bannerPayload(['position' => 'fake_hero', 'target_key' => '2']))
            ->assertOk()->json('data');
        $this->assertSame('2', $moved['target_key']);
        $this->assertSame(1, $moved['sort_order']);
    }

    /**
     * 목록·통계·미리보기·순서 창은 고른 대상만 본다. 대상을 고르기 전(대상 없음)은 빈 목록이고 통계는 그대로 온다 — 탭 개수·대상별 개수가 보인다.
     * 정적 위치에 대상을 보내면 422 다.
     *
     * @scenario case=list_preview_order_per_target
     *
     * @return void 반환값 없음
     */
    public function test_list_preview_and_order_follow_target(): void
    {
        $a = Banner::factory()->forPosition('fake_hero', '1')->running()->create(['sort_order' => 0]);
        $b = Banner::factory()->forPosition('fake_hero', '2')->running()->create(['sort_order' => 0]);
        Banner::factory()->forPosition('fake_hero', '2')->inactive()->create(['sort_order' => 1]);
        Banner::factory()->forPosition('home_hero')->create();

        $list = $this->actingAs($this->admin)->getJson($this->api('/admin/banners?position=fake_hero&target_key=2'))->assertOk()->json('data');
        $this->assertCount(2, $list['data']);
        $this->assertSame(['2', '2'], array_column($list['data'], 'target_key'));
        $this->assertSame('베타', $list['data'][0]['target_label']);
        $this->assertTrue($list['data'][0]['target_exists']);
        $this->assertSame(['1' => 1, '2' => 2], $list['statistics']['targets']);
        $this->assertSame(1, $list['statistics']['statuses']['active']);
        $this->assertSame(1, $list['statistics']['statuses']['inactive']);
        $this->assertSame(3, $list['statistics']['positions']['fake_hero']);

        $preview = $this->actingAs($this->admin)->getJson($this->api('/admin/banners/preview?position=fake_hero&target_key=1'))->assertOk()->json('data.data');
        $this->assertSame([$a->id], array_column($preview, 'id'));

        $order = $this->actingAs($this->admin)->getJson($this->api('/admin/banners/order?position=fake_hero&target_key=2'))->assertOk()->json('data.data');
        $this->assertContains($b->id, array_column($order, 'id'));
        $this->assertNotContains($a->id, array_column($order, 'id'));

        $unpicked = $this->actingAs($this->admin)->getJson($this->api('/admin/banners?position=fake_hero'))->assertOk()->json('data');
        $this->assertSame([], $unpicked['data']);
        $this->assertSame(3, $unpicked['statistics']['positions']['fake_hero']);
        $this->assertSame(['1' => 1, '2' => 2], $unpicked['statistics']['targets']);
        $this->assertSame([], $this->actingAs($this->admin)->getJson($this->api('/admin/banners/preview?position=fake_hero'))->assertOk()->json('data.data'));
        $this->actingAs($this->admin)->putJson($this->api('/admin/banners/order'), ['position' => 'fake_hero', 'ids' => [$a->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('target_key');
        $this->actingAs($this->admin)->getJson($this->api('/admin/banners/preview?position=home_hero&target_key=1'))->assertUnprocessable()->assertJsonValidationErrors('target_key');
    }

    /**
     * 미리보기·순서 창도 목록처럼 대상 이름을 대상마다 한 번만 푼다 — 행마다 대상 테이블을 묻지 않는다.
     *
     * @scenario case=target_labels_resolved_once
     *
     * @return void 반환값 없음
     */
    public function test_preview_and_order_list_resolve_target_labels_once(): void
    {
        Banner::factory()->count(4)->forPosition('fake_hero', '1')->running()->create();

        FakeTargetResolver::$labelCalls = 0;
        $order = $this->actingAs($this->admin)->getJson($this->api('/admin/banners/order?position=fake_hero&target_key=1'))->assertOk()->json('data.data');
        $this->assertCount(4, $order);
        $this->assertSame(['알파'], array_values(array_unique(array_column($order, 'target_label'))));
        $this->assertSame(1, FakeTargetResolver::$labelCalls);

        FakeTargetResolver::$labelCalls = 0;
        $preview = $this->actingAs($this->admin)->getJson($this->api('/admin/banners/preview?position=fake_hero&target_key=1'))->assertOk()->json('data.data');
        $this->assertCount(4, $preview);
        $this->assertSame(1, FakeTargetResolver::$labelCalls);
    }

    /**
     * 순서 변경은 그 대상의 배너 전체만 받는다 — 다른 대상의 배너가 섞이면 거부하고, 다른 대상의 순서는 그대로다.
     *
     * @scenario case=reorder_per_target
     *
     * @return void 반환값 없음
     */
    public function test_reorder_is_per_target(): void
    {
        $a = Banner::factory()->forPosition('fake_hero', '1')->create(['sort_order' => 0]);
        $b = Banner::factory()->forPosition('fake_hero', '1')->create(['sort_order' => 1]);
        $other = Banner::factory()->forPosition('fake_hero', '2')->create(['sort_order' => 0]);

        $this->actingAs($this->admin)->putJson($this->api('/admin/banners/order'), ['position' => 'fake_hero', 'target_key' => '1', 'ids' => [$b->id, $a->id, $other->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('ids');
        $this->actingAs($this->admin)->putJson($this->api('/admin/banners/order'), ['position' => 'fake_hero', 'target_key' => '1', 'ids' => [$b->id, $a->id]])
            ->assertOk();

        $this->assertSame([1, 0, 0], [$a->fresh()->sort_order, $b->fresh()->sort_order, $other->fresh()->sort_order]);
    }

    /**
     * 위치를 더한 확장이 꺼지면 그 위치 배너는 남고 관리자 목록에서 대상을 가리지 않고 보인다. 새 등록은 안 되고, 저장된 위치·대상 그대로의
     * 수정과 등록된 위치로 옮기기는 된다(D7). 다시 켜면 그대로 돌아온다.
     *
     * @scenario case=unregistered_position_banners_kept
     *
     * @return void 반환값 없음
     */
    public function test_unregistered_position_banners_stay_editable_in_admin(): void
    {
        $kept = Banner::factory()->withImages()->forPosition('fake_hero', '1')->create();
        $moved = Banner::factory()->withImages()->forPosition('fake_hero', '2')->create();
        $this->unregisterTestPosition();

        $rows = $this->actingAs($this->admin)->getJson($this->api('/admin/banners?position=fake_hero'))->assertOk()->json('data.data');
        $this->assertEqualsCanonicalizing([$kept->id, $moved->id], array_column($rows, 'id'));
        $this->assertFalse($rows[0]['position_registered']);
        $this->assertNull($rows[0]['target_exists']);
        $this->actingAs($this->admin)->getJson($this->api('/admin/banners?position=never_hero'))->assertUnprocessable()->assertJsonValidationErrors('position');

        $this->createBannerViaApi($this->admin, ['position' => 'fake_hero', 'target_key' => '1'])->assertUnprocessable()->assertJsonValidationErrors('position');
        $this->actingAs($this->admin)->putJson($this->api('/admin/banners/'.$kept->id), $this->bannerPayload(['position' => 'fake_hero', 'target_key' => '1', 'title' => ['ko' => '고침']]))
            ->assertOk()->assertJsonPath('data.target_key', '1');
        $this->actingAs($this->admin)->putJson($this->api('/admin/banners/'.$moved->id), $this->bannerPayload(['position' => 'home_hero']))
            ->assertOk()->assertJsonPath('data.position', 'home_hero')->assertJsonPath('data.target_key', null);

        $this->registerTestPosition();
        $this->actingAs($this->admin)->getJson($this->api('/admin/banners?position=fake_hero&target_key=1'))->assertOk()->assertJsonPath('data.data.0.id', $kept->id);
    }

    /**
     * 대상이 지워지면 배너는 남고 「없는 대상」으로 보인다(`target_exists: false`). 그 대상 그대로 고쳐 저장할 수 있지만 새로 고르지는 못한다(D7).
     *
     * @scenario case=missing_target_banners_kept
     *
     * @return void 반환값 없음
     */
    public function test_missing_target_banners_stay_editable(): void
    {
        $banner = Banner::factory()->withImages()->forPosition('fake_hero', '2')->create();
        FakeTargetResolver::forget('2');

        $row = $this->actingAs($this->admin)->getJson($this->api('/admin/banners?position=fake_hero&target_key=2'))->assertOk()->json('data.data.0');
        $this->assertSame($banner->id, $row['id']);
        $this->assertFalse($row['target_exists']);
        $this->assertNull($row['target_label']);
        $this->actingAs($this->admin)->getJson($this->api('/admin/banners/'.$banner->id))->assertOk()->assertJsonPath('data.target_exists', false);

        $this->actingAs($this->admin)->putJson($this->api('/admin/banners/'.$banner->id), $this->bannerPayload(['position' => 'fake_hero', 'target_key' => '2']))->assertOk();
        $this->createBannerViaApi($this->admin, ['position' => 'fake_hero', 'target_key' => '2'])->assertUnprocessable()->assertJsonValidationErrors('target_key');
    }

    /**
     * 대상 고르기 목록은 히어로 조회 권한만으로 받는다(모듈 관리 권한이 없어도, Q6). 고른 대상의 이름·사이트 주소·존재 여부를 함께 준다.
     * 정적 위치는 404, 조회 권한이 없으면 403.
     *
     * @scenario case=target_picker_endpoint
     *
     * @return void 반환값 없음
     */
    public function test_target_picker_lists_targets_with_read_permission(): void
    {
        $reader = $this->adminWith(['sirsoft-hero_section.banners.read']);

        $all = $this->actingAs($reader)->getJson($this->api('/admin/positions/fake_hero/targets'))->assertOk()->json('data');
        $this->assertSame(['1', '2'], array_column($all['items'], 'key'));
        $this->assertSame(['alpha', 'beta'], array_column($all['items'], 'hint'));

        FakeTargetResolver::forget('2');
        $found = $this->actingAs($reader)->getJson($this->api('/admin/positions/fake_hero/targets?keyword=알&key=1'))->assertOk()->json('data');
        $this->assertSame([['key' => '1', 'label' => '알파', 'hint' => 'alpha']], $found['items']);
        $this->assertSame(['key' => '1', 'label' => '알파', 'exists' => true, 'site_url' => '/fake/alpha'], $found['selected']);
        $this->assertSame(['key' => '2', 'label' => null, 'exists' => false, 'site_url' => null],
            $this->actingAs($reader)->getJson($this->api('/admin/positions/fake_hero/targets?key=2'))->assertOk()->json('data.selected'));
        $this->assertNull($all['selected']);

        $this->actingAs($reader)->getJson($this->api('/admin/positions/home_hero/targets'))->assertNotFound();
        $this->actingAs($this->adminWith([]))->getJson($this->api('/admin/positions/fake_hero/targets'))->assertForbidden();
    }

    /**
     * 활동 로그는 동적 위치 배너의 대상 키와 기록 시점 대상 이름을 남긴다 — 대상이 나중에 지워져도 로그로 알아본다.
     *
     * @scenario case=activity_log_records_target
     *
     * @return void 반환값 없음
     */
    public function test_activity_log_records_target(): void
    {
        $id = $this->createBannerViaApi($this->admin, ['position' => 'fake_hero', 'target_key' => '1'])->assertCreated()->json('data.id');

        $log = DB::table('activity_logs')->where('action', 'hero_banner.create')->where('loggable_id', $id)->first();
        $this->assertNotNull($log);
        $this->assertSame('1', json_decode((string) $log->description_params, true)['target_key']);
        $this->assertSame('알파', json_decode((string) $log->properties, true)['target']);

        // 일괄 노출 전환도 건마다 대상 키와 그때의 대상 이름을 남긴다(목록 한 줄에 「(대상: 알파)」로 보이게)
        $this->actingAs($this->admin)->patchJson($this->api('/admin/banners/bulk-status'), ['ids' => [$id], 'is_active' => false])->assertOk();
        $bulk = DB::table('activity_logs')->where('action', 'hero_banner.bulk_status')->where('loggable_id', $id)->first();
        $this->assertNotNull($bulk);
        $this->assertSame('1', json_decode((string) $bulk->description_params, true)['target_key'] ?? null);
        $this->assertSame('알파', json_decode((string) $bulk->properties, true)['target'] ?? null);
    }
}
