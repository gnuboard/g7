<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Feature\Admin;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Plugins\Sirsoft\HeroSection\Enums\HeroPosition;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 관리자 배너 API 테스트 (매니페스트 admin-banners.yaml — 목록·등록·수정·삭제·일괄·순서·시간대)
 *
 * 검증 규칙 단위 케이스는 BannerRequestTest, 이미지는 BannerImageControllerTest, 위치 설정은
 * PositionSettingControllerTest, 활동 로그·캐시는 각 리스너 테스트가 맡는다.
 */
class BannerControllerTest extends PluginTestCase
{
    /**
     * 조회·등록수정·삭제 권한을 가진 관리자
     */
    private User $admin;

    /**
     * 테스트 준비 — 이미지 파일은 가짜 디스크에 쓴다.
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
     * 테스트 정리 — 고정 시각을 풉니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 목록은 요청한 위치의 배너만 돌려준다.
     *
     * @scenario surface=list_filter_position
     *
     * @return void 반환값 없음
     */
    public function test_list_filters_by_position(): void
    {
        $home = Banner::factory()->forPosition(HeroPosition::HomeHero)->create();
        $shop = Banner::factory()->forPosition(HeroPosition::ShopHero)->create();

        $ids = $this->actingAs($this->admin)->getJson($this->api('/admin/banners?position=shop_hero'))
            ->assertOk()->json('data.data.*.id');

        $this->assertContains($shop->id, $ids);
        $this->assertNotContains($home->id, $ids);
    }

    /**
     * 목록·순서 창의 썸네일도 업로드 응답과 같은 첨부 모양을 빠짐없이 낸다(기기·원본 파일명·형식·크기).
     *
     * @scenario surface=list_filter_position
     *
     * @return void 반환값 없음
     */
    public function test_list_thumbnail_has_full_image_shape(): void
    {
        $this->createBannerViaApi($this->admin)->assertCreated();

        foreach (['/admin/banners?position=home_hero', '/admin/banners/order?position=home_hero'] as $path) {
            $thumb = $this->actingAs($this->admin)->getJson($this->api($path))->assertOk()->json('data.data.0.thumbnail');

            $this->assertSame('pc', $thumb['device'], $path);
            $this->assertSame('pc.jpg', $thumb['original_filename'], $path);
            $this->assertSame('image/jpeg', $thumb['mime_type'], $path);
            $this->assertGreaterThan(0, $thumb['size'], $path);
            $this->assertNotSame('0 B', $thumb['size_formatted'], $path);
        }
    }

    /**
     * 목록 행은 제작 상태를 함께 낸다 — 모바일 이미지가 없어 PC 이미지에서 잘라 쓰는지, 버튼 문구는 있는데 이동 주소가 없어
     * 사이트에 버튼이 보이지 않는지. 버튼 문구는 어느 언어든 하나라도 있으면 문구가 있는 것으로 본다.
     *
     * @scenario surface=list_row_readiness_flags
     *
     * @return void 반환값 없음
     */
    public function test_list_rows_flag_mobile_fallback_and_button_without_link(): void
    {
        $complete = $this->createBannerViaApi($this->admin, [], mobile: true)->assertCreated()->json('data.id');
        $pcOnly = $this->createBannerViaApi($this->admin)->assertCreated()->json('data.id');
        $noLink = $this->createBannerViaApi($this->admin, ['link_url' => null, 'button_text' => ['en' => 'Shop']])->assertCreated()->json('data.id');
        $wholeLink = $this->createBannerViaApi($this->admin, ['button_text' => []])->assertCreated()->json('data.id');
        $plain = $this->createBannerViaApi($this->admin, ['link_url' => '', 'button_text' => ['ko' => '']])->assertCreated()->json('data.id');

        $rows = collect($this->actingAs($this->admin)->getJson($this->api('/admin/banners?position=home_hero'))
            ->assertOk()->json('data.data'))->keyBy('id');

        $this->assertTrue($rows[$complete]['has_mobile_image']);
        $this->assertFalse($rows[$pcOnly]['has_mobile_image']);
        $this->assertFalse($rows[$complete]['button_without_link']);
        $this->assertTrue($rows[$noLink]['button_without_link']);
        $this->assertFalse($rows[$wholeLink]['button_without_link']);
        $this->assertFalse($rows[$plain]['button_without_link']);
    }

    /**
     * 상태 필터는 SQL 조건으로 그 상태만 돌려준다.
     *
     * @scenario surface=list_filter_status
     *
     * @return void 반환값 없음
     */
    public function test_list_filters_by_status(): void
    {
        $active = Banner::factory()->create();
        $scheduled = Banner::factory()->scheduled()->create();
        $ended = Banner::factory()->ended()->create();
        $inactive = Banner::factory()->inactive()->create();

        foreach (['active' => $active, 'scheduled' => $scheduled, 'ended' => $ended, 'inactive' => $inactive] as $status => $banner) {
            $rows = $this->actingAs($this->admin)->getJson($this->api('/admin/banners?position=home_hero&status='.$status))
                ->assertOk()->json('data.data');

            $this->assertSame([$banner->id], array_column($rows, 'id'), $status);
            $this->assertSame($status, $rows[0]['status']);
        }
    }

    /**
     * 상태 필터와 쪽 넘김의 총건수·마지막 쪽이 SQL 결과와 같다(PHP 에서 거르지 않는다).
     *
     * @scenario surface=list_status_filter_paginates_in_sql
     *
     * @return void 반환값 없음
     */
    public function test_status_filter_paginates_in_sql(): void
    {
        Banner::factory()->count(5)->create();
        Banner::factory()->count(3)->scheduled()->create();

        $response = $this->actingAs($this->admin)->getJson($this->api('/admin/banners?position=home_hero&status=active&per_page=2'))->assertOk();

        $this->assertSame(5, $response->json('data.pagination.total'));
        $this->assertSame(3, $response->json('data.pagination.last_page'));
        $this->assertCount(2, $response->json('data.data'));
    }

    /**
     * 상태 정렬은 노출중 → 예약 → 종료 → 비활성 순이다.
     *
     * @scenario surface=list_sort_by_status
     *
     * @return void 반환값 없음
     */
    public function test_list_sorts_by_status(): void
    {
        $inactive = Banner::factory()->inactive()->create(['sort_order' => 0]);
        $ended = Banner::factory()->ended()->create(['sort_order' => 1]);
        $scheduled = Banner::factory()->scheduled()->create(['sort_order' => 2]);
        $active = Banner::factory()->create(['sort_order' => 3]);

        $ids = $this->actingAs($this->admin)->getJson($this->api('/admin/banners?position=home_hero&sort_by=status&sort_order=asc'))
            ->assertOk()->json('data.data.*.id');

        $this->assertSame([$active->id, $scheduled->id, $ended->id, $inactive->id], $ids);
    }

    /**
     * 제목 검색은 모든 번역 언어에서 찾는다(한국어 관리자가 영어 제목으로 찾기).
     *
     * @scenario surface=list_search_title_all_locales
     *
     * @return void 반환값 없음
     */
    public function test_list_searches_title_in_all_locales(): void
    {
        $match = Banner::factory()->create(['title' => ['ko' => '가을 세일', 'en' => 'Autumn Sale']]);
        Banner::factory()->create(['title' => ['ko' => '겨울', 'en' => 'Winter']]);

        $ids = $this->actingAs($this->admin)->getJson($this->api('/admin/banners?position=home_hero&search=Autumn'))
            ->assertOk()->json('data.data.*.id');

        $this->assertSame([$match->id], $ids);
    }

    /**
     * 통계는 위치별 건수와 요청 위치의 상태별 건수를 준다.
     *
     * @scenario surface=list_statistics_by_position_and_status
     *
     * @return void 반환값 없음
     */
    public function test_list_returns_statistics(): void
    {
        Banner::factory()->count(2)->create();
        Banner::factory()->scheduled()->create();
        Banner::factory()->forPosition(HeroPosition::ShopHero)->create();

        $stats = $this->actingAs($this->admin)->getJson($this->api('/admin/banners?position=home_hero'))->assertOk()->json('data.statistics');

        $this->assertSame(3, $stats['positions']['home_hero']);
        $this->assertSame(1, $stats['positions']['shop_hero']);
        $this->assertSame(2, $stats['statuses']['active']);
        $this->assertSame(1, $stats['statuses']['scheduled']);
        $this->assertSame(0, $stats['statuses']['ended']);
    }

    /**
     * 목록 abilities 는 권한대로 단추 표시 여부를 준다(조회만 가진 관리자는 모두 false).
     *
     * @scenario surface=list_collection_abilities
     *
     * @return void 반환값 없음
     */
    public function test_list_abilities_follow_permissions(): void
    {
        Banner::factory()->create();

        $full = $this->actingAs($this->admin)->getJson($this->api('/admin/banners?position=home_hero'))->json('data');
        $this->assertSame(['can_create' => true, 'can_update' => true, 'can_delete' => true], $full['abilities']);
        $this->assertTrue($full['data'][0]['abilities']['can_delete']);

        $reader = $this->adminWith(['sirsoft-hero_section.banners.read']);
        $read = $this->actingAs($reader)->getJson($this->api('/admin/banners?position=home_hero'))->json('data');
        $this->assertSame(['can_create' => false, 'can_update' => false, 'can_delete' => false], $read['abilities']);
        $this->assertFalse($read['data'][0]['abilities']['can_update']);
    }

    /**
     * 등록하면 위치 맨 뒤 순서가 되고 before/after 훅이 발행된다.
     *
     * @scenario surface=store_appends_to_position_end
     *
     * @effects hooks_before_after_fired, sort_order_reassigned
     *
     * @return void 반환값 없음
     */
    public function test_store_appends_to_position_end_and_fires_hooks(): void
    {
        Banner::factory()->create(['sort_order' => 4]);
        $fired = [];
        HookManager::addAction('sirsoft-hero_section.banner.before_create', function () use (&$fired) {
            $fired[] = 'before';
        });
        HookManager::addAction('sirsoft-hero_section.banner.after_create', function () use (&$fired) {
            $fired[] = 'after';
        });

        $data = $this->createBannerViaApi($this->admin)->assertCreated()->json('data');

        $this->assertSame(5, $data['sort_order']);
        $this->assertSame(['before', 'after'], $fired);
        $this->assertSame($this->admin->id, Banner::query()->find($data['id'])->created_by);
    }

    /**
     * 등록하면 임시 묶음의 PC·모바일 이미지가 배너에 연결되고 temp_key 가 비워진다.
     *
     * @scenario surface=store_links_temp_images
     *
     * @return void 반환값 없음
     */
    public function test_store_links_temp_images(): void
    {
        $data = $this->createBannerViaApi($this->admin, [], mobile: true)->assertCreated()->json('data');

        $this->assertNotNull($data['images']['pc']);
        $this->assertNotNull($data['images']['mobile']);
        $this->assertSame(2, BannerImage::query()->where('banner_id', $data['id'])->whereNull('temp_key')->count());
    }

    /**
     * PC 이미지 없이 등록하면 422 다.
     *
     * @scenario surface=store_requires_pc_image
     *
     * @return void 반환값 없음
     */
    public function test_store_requires_pc_image(): void
    {
        $tempKey = (string) Str::uuid();
        $this->uploadImage($this->admin, 'mobile', ['temp_key' => $tempKey])->assertCreated();

        $this->actingAs($this->admin)->postJson($this->api('/admin/banners'), $this->bannerPayload() + ['temp_key' => $tempKey])
            ->assertStatus(422)->assertJsonValidationErrors(['temp_key']);
        $this->assertSame(0, Banner::query()->count());
    }

    /**
     * 사이트 시간대로 받은 기간은 UTC 로 저장된다.
     *
     * @scenario surface=period_site_timezone_stored_utc
     *
     * @return void 반환값 없음
     */
    public function test_period_is_stored_in_utc(): void
    {
        config(['app.default_user_timezone' => 'Asia/Seoul']);

        $data = $this->createBannerViaApi($this->admin, [
            'display_period' => 'range',
            'starts_at' => '2026-12-01T09:00',
            'ends_at' => '2026-12-31T23:59',
        ])->assertCreated()->json('data');

        $banner = Banner::query()->find($data['id']);
        $this->assertSame('2026-12-01 00:00:00', $banner->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-31 14:59:00', $banner->ends_at->utc()->format('Y-m-d H:i:s'));
    }

    /**
     * 관리자 응답의 기간은 사이트 시간대로 보이고, 노출 판정은 UTC 로 같은 시각을 가리킨다.
     *
     * @scenario surface=admin_period_display_in_site_timezone
     *
     * @return void 반환값 없음
     */
    public function test_period_is_displayed_in_site_timezone(): void
    {
        config(['app.default_user_timezone' => 'Asia/Seoul']);
        $banner = Banner::factory()->create([
            'display_period' => 'range',
            'starts_at' => Carbon::parse('2026-12-01 00:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-12-31 14:59:00', 'UTC'),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-11-30 23:59:59', 'UTC'));

        $data = $this->actingAs($this->admin)->getJson($this->api('/admin/banners/'.$banner->id))->assertOk()->json('data');

        $this->assertSame('2026-12-01T09:00', $data['starts_at_local']);
        $this->assertSame('2026-12-01 09:00:00', $data['starts_at_display']);
        $this->assertSame('2026-12-31T23:59', $data['ends_at_local']);
        // 사이트 시간대로 12-01 08:59:59 — 아직 시작 전
        $this->assertSame('scheduled', $data['status']);
    }

    /**
     * 위치를 바꾸면 새 위치 맨 뒤로 간다.
     *
     * @scenario surface=update_position_change_moves_to_end
     *
     * @effects sort_order_reassigned
     *
     * @return void 반환값 없음
     */
    public function test_update_moves_to_end_of_new_position(): void
    {
        Banner::factory()->forPosition(HeroPosition::ShopHero)->create(['sort_order' => 2]);
        $banner = Banner::factory()->withImages()->create(['sort_order' => 0]);

        $data = $this->actingAs($this->admin)->putJson($this->api('/admin/banners/'.$banner->id), $this->bannerPayload(['position' => 'shop_hero']))
            ->assertOk()->json('data');

        $this->assertSame('shop_hero', $data['position']);
        $this->assertSame(3, $data['sort_order']);
    }

    /**
     * 상세는 다국어 원본·이미지 2종·abilities 를 준다.
     *
     * @scenario surface=show_returns_raw_translations_and_images
     *
     * @return void 반환값 없음
     */
    public function test_show_returns_raw_translations_and_images(): void
    {
        $banner = Banner::factory()->withImages(mobile: true)->create(['title' => ['ko' => '한', 'en' => 'En']]);

        $data = $this->actingAs($this->admin)->getJson($this->api('/admin/banners/'.$banner->id))->assertOk()->json('data');

        $this->assertSame(['ko' => '한', 'en' => 'En'], $data['title']);
        $this->assertNotNull($data['images']['pc']['url']);
        $this->assertNotNull($data['images']['mobile']['url']);
        $this->assertArrayHasKey('can_update', $data['abilities']);
        $this->assertArrayNotHasKey('created_by', $data);
    }

    /**
     * 영구 삭제는 PC·모바일 이미지 파일과 행을 모두 지운다.
     *
     * @scenario surface=destroy_removes_pc_and_mobile_files
     *
     * @effects image_files_deleted
     *
     * @return void 반환값 없음
     */
    public function test_destroy_removes_pc_and_mobile_files(): void
    {
        $id = $this->createBannerViaApi($this->admin, [], mobile: true)->assertCreated()->json('data.id');
        $paths = BannerImage::query()->where('banner_id', $id)->pluck('path')->all();
        $this->assertCount(2, $paths);
        foreach ($paths as $path) {
            $this->imageStorage()->assertExists('sirsoft-hero_section/images/'.$path);
        }

        $this->actingAs($this->admin)->deleteJson($this->api('/admin/banners/'.$id))->assertOk();

        $this->assertNull(Banner::query()->find($id));
        $this->assertSame(0, BannerImage::query()->where('banner_id', $id)->count());
        foreach ($paths as $path) {
            $this->imageStorage()->assertMissing('sirsoft-hero_section/images/'.$path);
        }
    }

    /**
     * 모바일 이미지가 없는 배너도 오류 없이 지워진다.
     *
     * @scenario surface=destroy_without_mobile_image_succeeds
     *
     * @effects image_files_deleted
     *
     * @return void 반환값 없음
     */
    public function test_destroy_without_mobile_image(): void
    {
        $id = $this->createBannerViaApi($this->admin)->assertCreated()->json('data.id');
        $pc = BannerImage::query()->where('banner_id', $id)->value('path');

        $this->actingAs($this->admin)->deleteJson($this->api('/admin/banners/'.$id))->assertOk();

        $this->assertNull(Banner::query()->find($id));
        $this->imageStorage()->assertMissing('sirsoft-hero_section/images/'.$pc);
    }

    /**
     * 일괄 비노출은 고른 배너만 바꾼다.
     *
     * @scenario surface=bulk_status_updates_each
     *
     * @return void 반환값 없음
     */
    public function test_bulk_status_updates_each(): void
    {
        [$a, $b, $c] = Banner::factory()->count(3)->create();

        $this->actingAs($this->admin)->patchJson($this->api('/admin/banners/bulk-status'), ['ids' => [$a->id, $b->id], 'is_active' => false])
            ->assertOk()->assertJsonPath('data.count', 2);

        $this->assertFalse($a->fresh()->is_active);
        $this->assertFalse($b->fresh()->is_active);
        $this->assertTrue($c->fresh()->is_active);
    }

    /**
     * 일괄 삭제는 배너마다 이미지 파일까지 지운다.
     *
     * @scenario surface=bulk_delete_removes_each_with_files
     *
     * @effects image_files_deleted
     *
     * @return void 반환값 없음
     */
    public function test_bulk_delete_removes_each_with_files(): void
    {
        $first = $this->createBannerViaApi($this->admin, [], mobile: true)->json('data.id');
        $second = $this->createBannerViaApi($this->admin)->json('data.id');
        $keep = Banner::factory()->create();
        $paths = BannerImage::query()->whereIn('banner_id', [$first, $second])->pluck('path')->all();
        $this->assertCount(3, $paths);

        $this->actingAs($this->admin)->deleteJson($this->api('/admin/banners/bulk'), ['ids' => [$first, $second]])
            ->assertOk()->assertJsonPath('data.count', 2);

        $this->assertSame([$keep->id], Banner::query()->pluck('id')->all());
        foreach ($paths as $path) {
            $this->imageStorage()->assertMissing('sirsoft-hero_section/images/'.$path);
        }
    }

    /**
     * 일괄 요청의 ids 는 1~100개·정수·중복 없음·존재해야 한다.
     *
     * @scenario surface=bulk_ids_validation
     *
     * @return void 반환값 없음
     */
    public function test_bulk_ids_are_validated(): void
    {
        $banner = Banner::factory()->create();
        $cases = [[], [$banner->id, $banner->id], [999999], ['x']];

        foreach ($cases as $ids) {
            $this->actingAs($this->admin)->patchJson($this->api('/admin/banners/bulk-status'), ['ids' => $ids, 'is_active' => false])
                ->assertStatus(422);
            $this->actingAs($this->admin)->deleteJson($this->api('/admin/banners/bulk'), ['ids' => $ids])
                ->assertStatus(422);
        }
        $this->assertTrue($banner->fresh()->is_active);
    }

    /**
     * 순서 변경은 그 위치 전체를 받아 0 부터 다시 매긴다.
     *
     * @scenario surface=reorder_accepts_complete_set
     *
     * @effects sort_order_reassigned
     *
     * @return void 반환값 없음
     */
    public function test_reorder_accepts_complete_set(): void
    {
        [$a, $b, $c] = Banner::factory()->count(3)->sequence(['sort_order' => 0], ['sort_order' => 1], ['sort_order' => 2])->create();

        $this->actingAs($this->admin)->putJson($this->api('/admin/banners/order'), ['position' => 'home_hero', 'ids' => [$c->id, $a->id, $b->id]])
            ->assertOk();

        $this->assertSame([0, 1, 2], [$c->fresh()->sort_order, $a->fresh()->sort_order, $b->fresh()->sort_order]);
        $ordered = $this->actingAs($this->admin)->getJson($this->api('/admin/banners/order?position=home_hero'))->json('data.data.*.id');
        $this->assertSame([$c->id, $a->id, $b->id], $ordered);
    }

    /**
     * 그 위치 배너 일부만 보내면 422 다.
     *
     * @scenario surface=reorder_rejects_partial_set
     *
     * @return void 반환값 없음
     */
    public function test_reorder_rejects_partial_set(): void
    {
        [$a, $b] = Banner::factory()->count(2)->create();

        $this->actingAs($this->admin)->putJson($this->api('/admin/banners/order'), ['position' => 'home_hero', 'ids' => [$b->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['ids']);
    }

    /**
     * 다른 위치 배너가 섞이면 422 다.
     *
     * @scenario surface=reorder_rejects_other_position_id
     *
     * @return void 반환값 없음
     */
    public function test_reorder_rejects_other_position_id(): void
    {
        $home = Banner::factory()->create();
        $shop = Banner::factory()->forPosition(HeroPosition::ShopHero)->create();

        $this->actingAs($this->admin)->putJson($this->api('/admin/banners/order'), ['position' => 'home_hero', 'ids' => [$home->id, $shop->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['ids']);
    }
}
