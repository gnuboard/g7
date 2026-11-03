<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Unit\Listeners;

use App\Extension\HookManager;
use App\Models\User;
use App\Services\PluginSettingsService;
use Mockery;
use Plugins\Sirsoft\HeroSection\Models\PositionSetting;
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
        Mockery::close();

        parent::tearDown();
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
     * 이 플러그인의 설정 초기화·활성화·업데이트 뒤 힌트를 다시 맞춘다 — 다른 플러그인 사건에는 반응하지 않는다.
     *
     * @scenario surface=slot_width_hint_from_position_settings
     *
     * @return void 반환값 없음
     */
    public function test_resyncs_after_reset_activate_update_of_this_plugin(): void
    {
        $calls = $this->recordSaves();

        HookManager::doAction('core.plugin_settings.after_reset', 'sirsoft-hero_section');
        HookManager::doAction('core.plugins.after_activate', 'sirsoft-hero_section', []);
        HookManager::doAction('core.plugins.after_update', 'sirsoft-hero_section', [], []);
        HookManager::doAction('core.plugins.after_update', 'sirsoft-other', [], []);

        $this->assertCount(3, $calls);
        $this->assertSame(['sirsoft-hero_section'], array_values(array_unique(array_column($calls->getArrayCopy(), 0))));
    }

    /**
     * 플러그인 설정 저장을 가로채 호출을 기록합니다(실제 파일은 쓰지 않는다).
     *
     * @return \ArrayObject<int, array{0: string, 1: array<string, mixed>}> 호출 기록
     */
    private function recordSaves(): \ArrayObject
    {
        $calls = new \ArrayObject;
        $settings = Mockery::mock(PluginSettingsService::class);
        $settings->shouldReceive('save')->andReturnUsing(function (string $identifier, array $data) use ($calls) {
            $calls->append([$identifier, $data]);

            return true;
        });
        $this->app->instance(PluginSettingsService::class, $settings);

        return $calls;
    }
}
