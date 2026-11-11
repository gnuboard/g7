<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Unit\Listeners;

use App\Extension\HookManager;
use App\Models\User;
use App\Services\PluginSettingsService;
use Mockery;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Models\PositionSetting;
use Plugins\Sirsoft\HeroSection\Services\PositionRegistry;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 위치별 표시 폭 힌트 — 사용자 화면이 첫 로드 때 받는 플러그인 설정(`slot_widths`)이 위치 설정 테이블과 같게 유지되는지.
 *
 * 받는 동안의 스켈레톤이 이 힌트로 실제 폭(본문 폭·화면 전체)을 고른다. 원본은 위치 설정 하나이고, 플러그인 설정 저장 직전 필터가
 * 매번 다시 채우므로 환경설정 화면이 옛 값을 보내도 덮이지 않는다. 표시 폭이 바뀐 저장일 때만 다시 저장한다.
 */
class SlotWidthHintListenerTest extends PluginTestCase
{
    /**
     * 조회·등록/수정 권한 관리자
     */
    private User $admin;

    /**
     * 시험을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->fullAdmin();
    }

    /**
     * 테스트 뒤 목 객체를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * 이 플러그인 설정 저장 직전 필터가 힌트를 위치 설정 값으로 채운다 — 보낸 옛 값은 무시하고, 다른 플러그인은 건드리지 않는다.
     *
     * @scenario surface=slot_width_hint_from_position_settings
     *
     * @return void 반환값 없음
     */
    public function test_filter_fills_hint_from_position_settings(): void
    {
        PositionSetting::query()->create(['position' => 'home_hero', 'autoplay' => true, 'interval_seconds' => 5, 'effect' => 'slide', 'width' => 'full']);

        $filled = HookManager::applyFilters('core.plugin_settings.filter_save_data', ['public_cache_minutes' => 3, 'slot_widths' => ['home_hero' => 'content']], 'sirsoft-hero_section');
        $this->assertSame(['home_hero' => 'full', 'shop_hero' => 'content'], $filled['slot_widths']);
        $this->assertSame(3, $filled['public_cache_minutes']);

        $other = HookManager::applyFilters('core.plugin_settings.filter_save_data', ['x' => 1], 'sirsoft-other');
        $this->assertSame(['x' => 1], $other);
    }

    /**
     * 표시 폭이 바뀐 위치 설정 저장이면 플러그인 설정을 다시 저장하고(힌트는 필터가 채움), 다른 값만 바뀌면 건드리지 않는다.
     *
     * @scenario surface=slot_width_hint_from_position_settings
     *
     * @return void 반환값 없음
     */
    public function test_resaves_hint_only_when_width_changes(): void
    {
        $calls = $this->recordSaves();

        $url = $this->api('/admin/position-settings/home_hero');
        // 처음 저장 — 기본 content 그대로면 다시 저장하지 않는다
        $this->actingAs($this->admin)->putJson($url, ['autoplay' => true, 'interval_seconds' => 5, 'effect' => 'fade', 'width' => 'content'])->assertOk();
        // 효과만 바뀜 — 저장하지 않는다
        $this->actingAs($this->admin)->putJson($url, ['autoplay' => true, 'interval_seconds' => 7, 'effect' => 'cube'])->assertOk();
        // 표시 폭이 바뀜 — 한 번 저장한다
        $this->actingAs($this->admin)->putJson($url, ['autoplay' => true, 'interval_seconds' => 7, 'effect' => 'cube', 'width' => 'full'])->assertOk();

        $this->assertSame([['sirsoft-hero_section', []]], $calls->getArrayCopy());
    }

    /**
     * 최대 높이 힌트(`slot_max_heights`)도 위치 설정 값으로 채운다 — 비운 기기는 null, 행이 없는 위치는 둘 다 null.
     *
     * @scenario surface=slot_max_height_hint_from_position_settings
     *
     * @return void 반환값 없음
     */
    public function test_filter_fills_max_height_hint_from_position_settings(): void
    {
        PositionSetting::query()->create(['position' => 'home_hero', 'autoplay' => true, 'interval_seconds' => 5, 'effect' => 'slide', 'max_height_pc' => 480]);

        $filled = HookManager::applyFilters('core.plugin_settings.filter_save_data', ['slot_max_heights' => ['home_hero' => ['pc' => 999, 'mobile' => 999]]], 'sirsoft-hero_section');
        $this->assertSame(['home_hero' => ['pc' => 480, 'mobile' => null], 'shop_hero' => ['pc' => null, 'mobile' => null]], $filled['slot_max_heights']);
    }

    /**
     * 최대 높이가 바뀐 위치 설정 저장이면 힌트를 다시 맞추고, 같은 값으로 다시 저장하면 건드리지 않는다.
     *
     * @scenario surface=slot_max_height_hint_from_position_settings
     *
     * @return void 반환값 없음
     */
    public function test_resaves_hint_when_max_height_changes(): void
    {
        $calls = $this->recordSaves();

        $url = $this->api('/admin/position-settings/home_hero');
        $base = ['autoplay' => true, 'interval_seconds' => 5, 'effect' => 'slide'];
        // 처음 저장 — 비운 채면 다시 저장하지 않는다
        $this->actingAs($this->admin)->putJson($url, $base + ['max_height_pc' => null])->assertOk();
        // 휴대폰 최대 높이를 정함 — 한 번
        $this->actingAs($this->admin)->putJson($url, $base + ['max_height_mobile' => 520])->assertOk();
        // 같은 값 — 저장하지 않는다
        $this->actingAs($this->admin)->putJson($url, $base + ['max_height_mobile' => 520])->assertOk();
        // PC 를 정함 — 한 번, 휴대폰을 비움 — 한 번
        $this->actingAs($this->admin)->putJson($url, $base + ['max_height_pc' => 400])->assertOk();
        $this->actingAs($this->admin)->putJson($url, $base + ['max_height_mobile' => null])->assertOk();

        $this->assertCount(3, $calls);
    }

    /**
     * 이 플러그인의 설정 초기화·활성화·업데이트 뒤 힌트를 다시 맞춘다 — 다른 플러그인 사건은 위치 목록이 그대로면 반응하지 않는다.
     *
     * @scenario surface=slot_width_hint_from_position_settings
     *
     * @return void 반환값 없음
     */
    public function test_resyncs_after_reset_activate_update_of_this_plugin(): void
    {
        $calls = $this->recordSaves(null, ['home_hero' => 'content', 'shop_hero' => 'content']);

        HookManager::doAction('core.plugin_settings.after_reset', 'sirsoft-hero_section');
        HookManager::doAction('core.plugins.after_activate', 'sirsoft-hero_section', []);
        HookManager::doAction('core.plugins.after_update', 'sirsoft-hero_section', [], []);
        HookManager::doAction('core.plugins.after_update', 'sirsoft-other', [], []);

        $this->assertCount(3, $calls);
        $this->assertSame(['sirsoft-hero_section'], array_values(array_unique(array_column($calls->getArrayCopy(), 0))));
    }

    /**
     * 다른 모듈·플러그인을 켜고 끄면 정적 위치 목록이 바뀔 수 있다 — 저장된 힌트의 위치 목록과 지금 위치 목록이 다를 때만 힌트를 다시 맞춘다.
     * 맞추지 않으면 새로 더해진 정적 위치는 첫 배너를 등록하기 전까지 힌트가 없어, 첫 방문에 배너가 없어도 받는 동안 스켈레톤이 비쳤다 사라진다.
     *
     * @scenario surface=slot_hint_follows_registered_positions
     *
     * @return void 반환값 없음
     */
    public function test_resyncs_when_other_extension_changes_static_positions(): void
    {
        $filter = fn (array $positions) => [...$positions, ['key' => 'event_hero', 'label' => ['ko' => '이벤트', 'en' => 'Event'], 'order' => 200]];
        HookManager::addFilter('sirsoft-hero_section.position.registered_positions', $filter);
        $this->app->forgetInstance(PositionRegistry::class);

        try {
            // 저장된 힌트에 event_hero 가 없다 — 위치를 더한 확장이 켜졌다
            $calls = $this->recordSaves(null, ['home_hero' => 'content', 'shop_hero' => 'content']);
            HookManager::doAction('core.modules.after_activate', 'acme-event', []);
            $this->assertCount(1, $calls);

            // 목록이 같으면 다른 확장 사건에 저장하지 않는다
            $calls = $this->recordSaves(null, ['home_hero' => 'content', 'shop_hero' => 'content', 'event_hero' => 'content']);
            HookManager::doAction('core.plugins.after_activate', 'acme-other', []);
            HookManager::doAction('core.modules.after_deactivate', 'acme-other', []);
            $this->assertCount(0, $calls);
        } finally {
            HookManager::removeFilter('sirsoft-hero_section.position.registered_positions', $filter);
            $this->app->forgetInstance(PositionRegistry::class);
        }

        // 위치를 더한 확장이 꺼졌다 — 저장된 힌트에 남은 event_hero 를 걷는다
        $calls = $this->recordSaves(null, ['home_hero' => 'content', 'shop_hero' => 'content', 'event_hero' => 'content']);
        HookManager::doAction('core.plugins.after_deactivate', 'acme-event', []);
        $this->assertCount(1, $calls);
    }

    /**
     * 위치를 더한 확장을 지우면(켜 둔 채 바로 제거 포함) 남은 힌트 키를 걷는다 — 모듈은 제거에 성공했을 때만 훅이 오고, 플러그인은
     * 결과를 실어 오므로 실패면 그대로 둔다. 히어로 자신의 제거는 설정이 함께 지워지니 저장하지 않는다. 세 힌트 중 하나만 남아도 맞춘다.
     *
     * @scenario surface=slot_hint_follows_registered_positions
     *
     * @return void 반환값 없음
     */
    public function test_resyncs_after_extension_uninstall(): void
    {
        $stale = ['home_hero' => 'content', 'shop_hero' => 'content', 'event_hero' => 'content'];

        $calls = $this->recordSaves(null, $stale);
        HookManager::doAction('core.modules.after_uninstall', 'acme-event', [], true);
        $this->assertCount(1, $calls);

        $calls = $this->recordSaves(null, $stale);
        HookManager::doAction('core.plugins.after_uninstall', 'acme-event', true, false);
        $this->assertCount(0, $calls, '제거에 실패했으면 위치를 지우지 않는다');

        $calls = $this->recordSaves(null, $stale);
        HookManager::doAction('core.plugins.after_uninstall', 'acme-event', true, true);
        $this->assertCount(1, $calls);

        $calls = $this->recordSaves(null, $stale);
        HookManager::doAction('core.plugins.after_uninstall', 'sirsoft-hero_section', true, true);
        $this->assertCount(0, $calls, '히어로 자신의 제거는 저장하지 않는다');

        // 표시 폭 힌트는 맞는데 메뉴 겹침 힌트에만 지운 위치가 남았다
        $calls = $this->recordSaves(null, ['home_hero' => 'content', 'shop_hero' => 'content'], ['home_hero' => false, 'shop_hero' => false, 'event_hero' => false]);
        HookManager::doAction('core.modules.after_uninstall', 'acme-event', [], true);
        $this->assertCount(1, $calls);

        // 셋 다 맞으면 저장하지 않는다
        $calls = $this->recordSaves(['home_hero' => false, 'shop_hero' => false], ['home_hero' => 'content', 'shop_hero' => 'content'], ['home_hero' => false, 'shop_hero' => false]);
        HookManager::doAction('core.modules.after_uninstall', 'acme-event', [], true);
        $this->assertCount(0, $calls);
    }

    /**
     * 배너 유무 힌트는 노출 중이거나 예약된 배너가 있을 때만 참이다 — 꺼 둔 배너·기간이 끝난 배너는 사이트에 나올 일이 없다.
     *
     * @scenario surface=slot_banner_hint_from_banners
     *
     * @return void 반환값 없음
     */
    public function test_filter_fills_banner_hint_from_exposable_banners(): void
    {
        Banner::factory()->forPosition('home_hero')->inactive()->create();
        Banner::factory()->forPosition('home_hero')->ended()->create();
        Banner::factory()->forPosition('shop_hero')->scheduled()->create();

        $filled = HookManager::applyFilters('core.plugin_settings.filter_save_data', ['slot_banners' => ['home_hero' => true]], 'sirsoft-hero_section');
        $this->assertSame(['home_hero' => false, 'shop_hero' => true], $filled['slot_banners']);

        Banner::factory()->forPosition('home_hero')->running()->create();
        $filled = HookManager::applyFilters('core.plugin_settings.filter_save_data', [], 'sirsoft-hero_section');
        $this->assertSame(['home_hero' => true, 'shop_hero' => true], $filled['slot_banners']);
    }

    /**
     * 배너가 바뀌어 유무가 달라질 때만 플러그인 설정을 다시 저장한다 — 배너를 고칠 때마다 설정 파일을 쓰지 않는다.
     *
     * @scenario surface=slot_banner_hint_from_banners
     *
     * @return void 반환값 없음
     */
    public function test_resaves_banner_hint_only_when_presence_changes(): void
    {
        $banner = Banner::factory()->forPosition('home_hero')->running()->create();

        // 저장된 힌트와 같으면 저장하지 않는다
        $calls = $this->recordSaves(['home_hero' => true, 'shop_hero' => false]);
        HookManager::doAction('sirsoft-hero_section.banner.after_create', $banner);
        HookManager::doAction('sirsoft-hero_section.banner.after_update', $banner, [], 'home_hero');
        $this->assertCount(0, $calls);

        // 달라지면(마지막 배너를 지웠다) 힌트를 다시 맞춘다 — 삭제·일괄 처리 경로도 같다
        $banner->delete();
        HookManager::doAction('sirsoft-hero_section.banner.after_delete', $banner->id, []);
        HookManager::doAction('sirsoft-hero_section.banner.after_bulk_delete', [$banner->id], []);
        HookManager::doAction('sirsoft-hero_section.banner.after_bulk_status', [$banner->id], false, []);
        $this->assertCount(3, $calls);
        $this->assertSame(['sirsoft-hero_section'], array_values(array_unique(array_column($calls->getArrayCopy(), 0))));
    }

    /**
     * 실제 훅 체인 — 배너를 API 로 만들고 지우면 사용자 화면이 받는 플러그인 설정의 힌트가 따라간다.
     *
     * @scenario surface=slot_banner_hint_from_banners
     *
     * @return void 반환값 없음
     */
    public function test_banner_hint_follows_real_banner_changes(): void
    {
        // 설치하지 않은 클론에서도 저장이 돌고, 설치한 클론의 실제 설정 파일을 덮지 않게 한다
        $this->registerPluginInstance();
        $settings = app(PluginSettingsService::class);
        $settings->save('sirsoft-hero_section', []);
        $this->assertFalse($settings->get('sirsoft-hero_section', 'slot_banners.home_hero'));

        $banner = Banner::factory()->forPosition('home_hero')->running()->create();
        HookManager::doAction('sirsoft-hero_section.banner.after_create', $banner);
        $this->assertTrue(app(PluginSettingsService::class)->get('sirsoft-hero_section', 'slot_banners.home_hero'));

        $this->actingAs($this->admin)->deleteJson($this->api('/admin/banners/'.$banner->id))->assertOk();
        $this->assertFalse(app(PluginSettingsService::class)->get('sirsoft-hero_section', 'slot_banners.home_hero'));
    }

    /**
     * 플러그인 설정 저장을 가로채 호출을 기록합니다(실제 파일은 쓰지 않는다).
     *
     * @param  array<string, bool>|null  $bannerHint  저장돼 있다고 볼 배너 유무 힌트
     * @param  array<string, string>|null  $widthHint  저장돼 있다고 볼 표시 폭 힌트(정적 위치 목록)
     * @param  array<string, bool>|null  $overlayHint  저장돼 있다고 볼 메뉴 겹침 힌트
     * @param  array<string, array{pc: int|null, mobile: int|null}>|null  $maxHeightHint  저장돼 있다고 볼 최대 높이 힌트
     * @return \ArrayObject<int, array{0: string, 1: array<string, mixed>}> 호출 기록
     */
    private function recordSaves(?array $bannerHint = null, ?array $widthHint = null, ?array $overlayHint = null, ?array $maxHeightHint = null): \ArrayObject
    {
        $calls = new \ArrayObject;
        $settings = Mockery::mock(PluginSettingsService::class);
        $settings->shouldReceive('get')->with('sirsoft-hero_section', 'slot_banners')->andReturn($bannerHint);
        $settings->shouldReceive('get')->with('sirsoft-hero_section', 'slot_widths')->andReturn($widthHint);
        $settings->shouldReceive('get')->with('sirsoft-hero_section', 'slot_overlays')->andReturn($overlayHint);
        $settings->shouldReceive('get')->with('sirsoft-hero_section', 'slot_max_heights')->andReturn($maxHeightHint);
        $settings->shouldReceive('save')->andReturnUsing(function (string $identifier, array $data) use ($calls) {
            $calls->append([$identifier, $data]);

            return true;
        });
        $this->app->instance(PluginSettingsService::class, $settings);

        return $calls;
    }
}
