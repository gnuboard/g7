<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Feature\Admin;

use App\Extension\HookManager;
use Mockery;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Repositories\BannerRepository;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Services\BannerService;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/** #810 편집 표시 설정·시간대·동시 수정의 API 계약 */
class BannerEditorImprovementTest extends PluginTestCase
{
    /**
     * 오래된 요청 모델 대신 잠근 최신 위치를 삭제 알림에 전달합니다.
     *
     * @scenario case=locked_mutation_snapshot
     *
     * @effects editor_state_protected
     *
     * @return void 반환값 없음
     */
    public function test_delete_uses_locked_snapshot(): void
    {
        $this->fakeImageDisks();
        $banner = Banner::factory()->withImages()->create(['position' => 'home_hero']);
        Banner::query()->whereKey($banner->id)->update(['position' => 'shop_hero']);
        $snapshot = [];
        HookManager::addAction('sirsoft-hero_section.banner.after_delete', function ($id, $value) use (&$snapshot) {
            $snapshot = $value;
        }, 99);
        app(BannerService::class)->delete($banner);
        $this->assertSame('shop_hero', $snapshot['position']);
    }

    /**
     * 일괄 조회 뒤 위치가 바뀌어도 최신 부모 행의 위치로 변경을 통지합니다.
     *
     * @scenario case=locked_mutation_snapshot
     *
     * @effects editor_state_protected
     *
     * @return void 반환값 없음
     */
    public function test_bulk_mutations_use_locked_snapshots(): void
    {
        $this->fakeImageDisks();
        foreach (['bulkDelete', 'bulkSetActive'] as $method) {
            $banner = Banner::factory()->withImages()->create(['position' => 'home_hero']);
            $repository = Mockery::mock(BannerRepository::class)->makePartial();
            $repository->shouldReceive('findMany')->atLeast()->once()->andReturnUsing(function ($ids) use ($banner) {
                $rows = Banner::query()->whereIn('id', $ids)->get();
                Banner::query()->whereKey($banner->id)->update(['position' => 'shop_hero']);

                return $rows;
            });
            $this->app->instance(BannerRepositoryInterface::class, $repository);
            $snapshots = [];
            $hook = $method === 'bulkDelete' ? 'after_bulk_delete' : 'after_bulk_status';
            HookManager::addAction('sirsoft-hero_section.banner.'.$hook, function (...$args) use (&$snapshots) {
                $snapshots = $args[count($args) - 1];
            }, 99);
            $service = app(BannerService::class);
            $method === 'bulkDelete' ? $service->bulkDelete([$banner->id]) : $service->bulkSetActive([$banner->id], false, null);
            $this->assertSame('shop_hero', $snapshots[$banner->id]['position'], $method);
        }
    }

    /**
     * 오래된 수정 세대는 최신 배너를 덮지 않고 기존 API는 호환됩니다.
     *
     * @scenario case=revision_conflict
     *
     * @effects editor_state_protected
     *
     * @return void 반환값 없음
     */
    public function test_stale_revision_does_not_overwrite_a_saved_banner(): void
    {
        $this->fakeImageDisks();
        $admin = $this->fullAdmin();
        $banner = $this->createBannerViaApi($admin)->assertCreated()->json('data');
        $url = $this->api('/admin/banners/'.$banner['id']);
        $this->actingAs($admin)->putJson($url, $this->bannerPayload(['revision' => $banner['revision'], 'title' => ['ko' => '먼저 저장']]))
            ->assertOk()->assertJsonPath('data.revision', 1);
        $this->putJson($url, $this->bannerPayload(['revision' => $banner['revision'], 'title' => ['ko' => '오래된 입력']]))->assertConflict();
        $this->getJson($url)->assertOk()->assertJsonPath('data.title.ko', '먼저 저장');
        // 기존 API는 revision 생략 가능. 부모 행 잠금은 같은 경로를 탄다.
        $this->putJson($url, $this->bannerPayload())->assertOk()->assertJsonPath('data.revision', 2);
    }

    /**
     * 표시 설정의 저장·공개 응답과 좌표 검증을 확인합니다.
     *
     * @scenario case=presentation_roundtrip
     *
     * @effects editor_state_protected
     *
     * @return void 반환값 없음
     */
    public function test_presentation_is_saved_and_published_with_numeric_coordinates(): void
    {
        $this->fakeImageDisks();
        $admin = $this->fullAdmin();
        $presentation = ['pc_x' => 15, 'pc_y' => 75, 'mobile_x' => 90, 'mobile_y' => 20, 'overlay' => 'dark'];
        $created = $this->createBannerViaApi($admin, ['presentation' => $presentation])->assertCreated();
        // 보내지 않은 문구 자리·효과 키는 기본값(종전 화면)으로 채워 응답한다
        $expected = array_replace(Banner::DEFAULT_PRESENTATION, $presentation);
        $created->assertJsonPath('data.presentation', $expected);
        $this->getJson($this->api('/hero?position=home_hero'))->assertOk()->assertJsonPath('data.banners.0.presentation', $expected);
        $id = $created->json('data.id');
        foreach ([['mobile_x' => -1], ['pc_y' => 101], ['overlay' => 'unsafe'], ['unexpected' => 1]] as $bad) {
            $this->putJson($this->api('/admin/banners/'.$id), $this->bannerPayload(['presentation' => $bad]))->assertUnprocessable();
        }
    }

    /**
     * 일괄 상태 변경 후 열린 편집 화면의 충돌을 확인합니다.
     *
     * @scenario case=bulk_revision_conflict
     *
     * @effects editor_state_protected
     *
     * @return void 반환값 없음
     */
    public function test_bulk_status_change_invalidates_open_editor_revision(): void
    {
        $this->fakeImageDisks();
        $admin = $this->fullAdmin();
        $banner = $this->createBannerViaApi($admin)->assertCreated()->json('data');
        $this->actingAs($admin)->patchJson($this->api('/admin/banners/bulk-status'), ['ids' => [$banner['id']], 'is_active' => false])->assertOk();
        $this->putJson($this->api('/admin/banners/'.$banner['id']), $this->bannerPayload(['revision' => $banner['revision']]))->assertConflict();
    }

    /**
     * 서버가 사이트 시간대·기본 언어 메타를 제공합니다.
     *
     * @scenario case=site_clock_metadata
     *
     * @effects editor_state_protected
     *
     * @return void 반환값 없음
     */
    public function test_editor_metadata_exposes_site_clock_and_default_locale(): void
    {
        config(['app.default_user_timezone' => 'Asia/Tokyo', 'app.site_locale' => 'en']);
        $this->actingAs($this->fullAdmin())->getJson($this->api('/admin/position-settings'))->assertOk()
            ->assertJsonPath('data.editor.timezone', 'Asia/Tokyo')->assertJsonPath('data.editor.default_locale', 'en')
            ->assertJsonStructure(['data' => ['editor' => ['server_now', 'locales']]]);
    }
}
